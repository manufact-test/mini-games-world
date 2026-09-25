<?php
declare(strict_types=1);

final class AntiFraudCaseException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class AntiFraudCaseService
{
    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_CLOSED = 'closed';

    public const DECISION_PENDING = 'pending';
    public const DECISION_CLEARED = 'cleared';
    public const DECISION_MONITOR = 'monitor';
    public const DECISION_MODERATION_REVIEW = 'moderation_review';
    public const DECISION_RATING_REVIEW = 'rating_review';

    private const CASE_LIMIT = 200;
    private const PAIR_HISTORY_LIMIT = 50;

    public function __construct(
        private DatabaseConnectionInterface $database,
        private MatchReplayReader $replayReader
    ) {}

    public function review(string $matchId): array
    {
        $matchId = $this->bounded($matchId, 96);
        if ($matchId === '') {
            throw new AntiFraudCaseException('match_required', 'Укажите ID матча.');
        }

        $replay = $this->replayReader->load($matchId);
        if ($replay === null) {
            throw new AntiFraudCaseException('match_not_found', 'Матч не найден.');
        }

        $players = is_array($replay['players'] ?? null) ? $replay['players'] : [];
        $humanIds = [];
        foreach ($players as $player) {
            if (!is_array($player) || (bool)($player['is_bot'] ?? false)) continue;
            $mgwId = trim((string)($player['mgw_id'] ?? ''));
            if ($mgwId !== '') $humanIds[$mgwId] = $mgwId;
        }
        $humanIds = array_values($humanIds);

        $pairHistory = count($humanIds) === 2
            ? $this->pairHistory($humanIds[0], $humanIds[1])
            : [];

        $sourceAvailability = [
            'pair_history' => count($humanIds) === 2,
            'devices' => true,
            'sessions' => true,
            'rating_antifarming' => true,
        ];

        $deviceSession = count($humanIds) === 2
            ? $this->deviceSessionSignals(
                $humanIds[0],
                $humanIds[1],
                is_array($replay['match'] ?? null) ? $replay['match'] : [],
                $sourceAvailability
            )
            : [
                'players' => [],
                'shared_device_hash_prefixes' => [],
                'shared_match_window_device_hash_prefixes' => [],
            ];

        $ratingSignal = $this->ratingAntiFarmingSignal($matchId, $sourceAvailability);
        $signals = $this->detectSignals(
            $replay,
            $pairHistory,
            $deviceSession,
            $ratingSignal
        );

        return [
            'replay' => $replay,
            'pair_history' => $pairHistory,
            'device_session' => $deviceSession,
            'signals' => $signals,
            'source_availability' => $sourceAvailability,
            'timing' => $this->timingSummary(is_array($replay['timeline'] ?? null) ? $replay['timeline'] : []),
            'case' => $this->caseForMatch($matchId),
            'policy' => [
                'auto_ban' => false,
                'single_signal_sanction' => false,
                'copy' => 'Сигналы только помогают ручной проверке. Один сигнал не блокирует игрока автоматически.',
            ],
        ];
    }

    public function snapshot(array $filters = [], int $limit = 100): array
    {
        $limit = max(1, min(self::CASE_LIMIT, $limit));
        $mode = strtolower(trim((string)($filters['mode'] ?? 'active')));
        if (!in_array($mode, ['active', 'closed', 'all'], true)) {
            throw new AntiFraudCaseException('invalid_filter', 'Некорректный режим anti-fraud очереди.');
        }

        $where = [];
        $params = [];
        if ($mode === 'active') {
            $where[] = "c.status_code IN ('open','reviewing')";
        } elseif ($mode === 'closed') {
            $where[] = "c.status_code = 'closed'";
        }

        $query = $this->bounded((string)($filters['query'] ?? ''), 120);
        if ($query !== '') {
            $where[] = '(LOWER(c.case_id) LIKE :query_case
                OR LOWER(c.match_id) LIKE :query_match
                OR LOWER(c.summary) LIKE :query_summary
                OR LOWER(COALESCE(c.owner_ref, \'\')) LIKE :query_owner)';
            $needle = '%' . strtolower($query) . '%';
            $params['query_case'] = $needle;
            $params['query_match'] = $needle;
            $params['query_summary'] = $needle;
            $params['query_owner'] = $needle;
        }

        $sql = 'SELECT c.case_id, c.match_id, c.status_code, c.priority_code, c.decision_code,
                       c.summary, c.owner_ref, c.created_by_admin_ref, c.created_at_utc,
                       c.updated_at_utc, c.reviewed_at_utc, c.closed_at_utc,
                       m.game_type, m.status AS match_status, m.finished_at_utc,
                       (SELECT COUNT(*) FROM mgw_antifraud_case_signals s WHERE s.case_id = c.case_id) AS signal_count
                FROM mgw_antifraud_cases c
                INNER JOIN mgw_matches m ON m.match_id = c.match_id';
        if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY
                    CASE c.priority_code WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
                    CASE c.status_code WHEN 'open' THEN 0 WHEN 'reviewing' THEN 1 ELSE 2 END,
                    c.updated_at_utc DESC
                  LIMIT " . $limit;

        return [
            'mode' => $mode,
            'cases' => array_map(fn(array $row): array => $this->normalizeCaseRow($row), $this->database->fetchAll($sql, $params)),
            'decisions' => $this->decisionLabels(),
            'statuses' => $this->statusLabels(),
        ];
    }

    public function createCase(string $matchId, string $actorRef, string $summary = ''): array
    {
        $matchId = $this->bounded($matchId, 96);
        $actorRef = $this->requiredActor($actorRef);
        $summary = $this->bounded($summary, 800);

        $existing = $this->caseForMatch($matchId);
        if ($existing !== null) return $existing;

        $review = $this->review($matchId);
        $signals = is_array($review['signals'] ?? null) ? $review['signals'] : [];
        if ($signals === []) {
            $signals[] = [
                'code' => 'manual_review',
                'severity' => 'low',
                'label' => 'Ручная проверка',
                'details' => ['reason' => 'Кейс создан администратором без автоматического сигнала.'],
            ];
        }

        $priority = 'low';
        foreach ($signals as $signal) {
            $severity = (string)($signal['severity'] ?? 'low');
            if ($severity === 'high') {
                $priority = 'high';
                break;
            }
            if ($severity === 'medium') $priority = 'normal';
        }

        if ($summary === '') {
            $summary = 'Проверка матча: ' . implode(', ', array_map(
                static fn(array $signal): string => (string)($signal['label'] ?? $signal['code'] ?? 'сигнал'),
                array_slice($signals, 0, 4)
            ));
        }

        $caseId = 'AFC-' . gmdate('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $now = $this->timestamp();

        try {
            $this->database->transaction(function () use ($caseId, $matchId, $priority, $summary, $actorRef, $now, $signals): void {
                $this->database->execute(
                    'INSERT INTO mgw_antifraud_cases (
                        case_id, match_id, status_code, priority_code, decision_code, summary,
                        owner_ref, created_by_admin_ref, created_at_utc, updated_at_utc,
                        reviewed_at_utc, closed_at_utc
                     ) VALUES (
                        :case_id, :match_id, :status_code, :priority_code, :decision_code, :summary,
                        NULL, :created_by, :created_at, :updated_at, NULL, NULL
                     )',
                    [
                        'case_id' => $caseId,
                        'match_id' => $matchId,
                        'status_code' => self::STATUS_OPEN,
                        'priority_code' => $priority,
                        'decision_code' => self::DECISION_PENDING,
                        'summary' => $summary,
                        'created_by' => $actorRef,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                foreach ($signals as $signal) {
                    $this->database->execute(
                        'INSERT INTO mgw_antifraud_case_signals (
                            case_id, signal_code, severity_code, label, details_json, detected_at_utc
                         ) VALUES (
                            :case_id, :signal_code, :severity_code, :label, :details_json, :detected_at
                         )',
                        [
                            'case_id' => $caseId,
                            'signal_code' => $this->bounded((string)($signal['code'] ?? 'manual_review'), 48),
                            'severity_code' => $this->bounded((string)($signal['severity'] ?? 'low'), 16),
                            'label' => $this->bounded((string)($signal['label'] ?? 'Сигнал'), 160),
                            'details_json' => $this->json($signal['details'] ?? []),
                            'detected_at' => $now,
                        ]
                    );
                }

                $this->insertEvent($caseId, 'created', $actorRef, null, self::STATUS_OPEN, $summary, $now);
            });
        } catch (Throwable $error) {
            $existing = $this->caseForMatch($matchId);
            if ($existing !== null) return $existing;
            throw $error;
        }

        return $this->caseById($caseId);
    }

    public function takeInReview(string $caseId, string $actorRef): array
    {
        $case = $this->caseById($caseId);
        $actorRef = $this->requiredActor($actorRef);
        $status = (string)$case['status'];

        if ($status === self::STATUS_REVIEWING) return $case;
        if ($status !== self::STATUS_OPEN) {
            throw new AntiFraudCaseException('case_terminal', 'Закрытый anti-fraud кейс доступен только для просмотра.');
        }

        $now = $this->timestamp();
        $this->database->transaction(function () use ($case, $actorRef, $now): void {
            $this->database->execute(
                'UPDATE mgw_antifraud_cases
                 SET status_code = :status, owner_ref = :owner_ref,
                     reviewed_at_utc = :reviewed_at, updated_at_utc = :updated_at
                 WHERE case_id = :case_id AND status_code = :expected',
                [
                    'status' => self::STATUS_REVIEWING,
                    'owner_ref' => $actorRef,
                    'reviewed_at' => $now,
                    'updated_at' => $now,
                    'case_id' => (string)$case['case_id'],
                    'expected' => self::STATUS_OPEN,
                ]
            );
            $this->insertEvent(
                (string)$case['case_id'],
                'status_changed',
                $actorRef,
                self::STATUS_OPEN,
                self::STATUS_REVIEWING,
                null,
                $now
            );
        });

        return $this->caseById((string)$case['case_id']);
    }

    public function resolve(string $caseId, string $decision, string $note, string $actorRef): array
    {
        $case = $this->caseById($caseId);
        $actorRef = $this->requiredActor($actorRef);
        $decision = strtolower(trim($decision));
        $note = $this->bounded($note, 800);

        if (!array_key_exists($decision, $this->decisionLabels()) || $decision === self::DECISION_PENDING) {
            throw new AntiFraudCaseException('invalid_decision', 'Выберите итог проверки.');
        }
        if ((string)$case['status'] !== self::STATUS_REVIEWING) {
            throw new AntiFraudCaseException('case_not_reviewing', 'Сначала возьмите кейс в работу.');
        }
        if ($note === '') {
            throw new AntiFraudCaseException('note_required', 'Добавьте короткий комментарий к итогу проверки.');
        }

        $now = $this->timestamp();
        $this->database->transaction(function () use ($case, $decision, $note, $actorRef, $now): void {
            $this->database->execute(
                'UPDATE mgw_antifraud_cases
                 SET status_code = :status, decision_code = :decision,
                     updated_at_utc = :updated_at, closed_at_utc = :closed_at
                 WHERE case_id = :case_id AND status_code = :expected',
                [
                    'status' => self::STATUS_CLOSED,
                    'decision' => $decision,
                    'updated_at' => $now,
                    'closed_at' => $now,
                    'case_id' => (string)$case['case_id'],
                    'expected' => self::STATUS_REVIEWING,
                ]
            );
            $this->insertEvent(
                (string)$case['case_id'],
                'decision',
                $actorRef,
                self::DECISION_PENDING,
                $decision,
                $note,
                $now
            );
            $this->insertEvent(
                (string)$case['case_id'],
                'status_changed',
                $actorRef,
                self::STATUS_REVIEWING,
                self::STATUS_CLOSED,
                null,
                $now
            );
        });

        return $this->caseById((string)$case['case_id']);
    }

    public function caseById(string $caseId): array
    {
        $caseId = $this->bounded($caseId, 48);
        $rows = $this->database->fetchAll(
            'SELECT c.*, m.game_type, m.status AS match_status, m.finished_at_utc
             FROM mgw_antifraud_cases c
             INNER JOIN mgw_matches m ON m.match_id = c.match_id
             WHERE c.case_id = :case_id
             LIMIT 1',
            ['case_id' => $caseId]
        );
        if ($rows === []) {
            throw new AntiFraudCaseException('case_not_found', 'Anti-fraud кейс не найден.');
        }

        $case = $this->normalizeCaseRow($rows[0]);
        $case['signals'] = $this->signalsForCase($caseId);
        $case['events'] = $this->eventsForCase($caseId);
        return $case;
    }

    public function caseForMatch(string $matchId): ?array
    {
        $matchId = $this->bounded($matchId, 96);
        if ($matchId === '') return null;
        $rows = $this->database->fetchAll(
            'SELECT case_id FROM mgw_antifraud_cases WHERE match_id = :match_id LIMIT 1',
            ['match_id' => $matchId]
        );
        if ($rows === []) return null;
        return $this->caseById((string)$rows[0]['case_id']);
    }

    public function statusLabels(): array
    {
        return [
            self::STATUS_OPEN => 'Новый',
            self::STATUS_REVIEWING => 'На проверке',
            self::STATUS_CLOSED => 'Обработан',
        ];
    }

    public function decisionLabels(): array
    {
        return [
            self::DECISION_PENDING => 'Решение не принято',
            self::DECISION_CLEARED => 'Нарушений не найдено',
            self::DECISION_MONITOR => 'Оставить под наблюдением',
            self::DECISION_MODERATION_REVIEW => 'Передать на модерацию',
            self::DECISION_RATING_REVIEW => 'Передать на проверку рейтинга',
        ];
    }

    private function pairHistory(string $firstMgwId, string $secondMgwId): array
    {
        return $this->database->fetchAll(
            'SELECT m.match_id, m.game_type, m.status, m.bet, m.match_source,
                    m.winner_player_ref, m.finish_reason, m.created_at_utc,
                    m.started_at_utc, m.finished_at_utc,
                    a.player_ref AS first_player_ref, a.result AS first_result,
                    b.player_ref AS second_player_ref, b.result AS second_result
             FROM mgw_matches m
             INNER JOIN mgw_match_players a
                ON a.match_id = m.match_id AND a.mgw_id = :first_mgw_id
             INNER JOIN mgw_match_players b
                ON b.match_id = m.match_id AND b.mgw_id = :second_mgw_id
             ORDER BY COALESCE(m.finished_at_utc, m.started_at_utc, m.created_at_utc) DESC
             LIMIT ' . self::PAIR_HISTORY_LIMIT,
            [
                'first_mgw_id' => $firstMgwId,
                'second_mgw_id' => $secondMgwId,
            ]
        );
    }

    private function deviceSessionSignals(
        string $firstMgwId,
        string $secondMgwId,
        array $match,
        array &$sourceAvailability
    ): array {
        $devices = $this->optionalFetchAll(
            'SELECT device_id, mgw_id, device_key_hash, platform, first_seen_at_utc, last_seen_at_utc
             FROM mgw_devices
             WHERE mgw_id IN (:first_mgw_id, :second_mgw_id)
             ORDER BY last_seen_at_utc DESC',
            ['first_mgw_id' => $firstMgwId, 'second_mgw_id' => $secondMgwId],
            $sourceAvailability,
            'devices'
        );

        $byHash = [];
        $deviceHashById = [];
        foreach ($devices as $device) {
            $hash = trim((string)($device['device_key_hash'] ?? ''));
            $mgwId = trim((string)($device['mgw_id'] ?? ''));
            $deviceId = (string)($device['device_id'] ?? '');
            if ($hash === '' || $mgwId === '') continue;
            $byHash[$hash][$mgwId] = true;
            if ($deviceId !== '') $deviceHashById[$deviceId] = $hash;
        }

        $sharedHashes = [];
        foreach ($byHash as $hash => $owners) {
            if (isset($owners[$firstMgwId], $owners[$secondMgwId])) {
                $sharedHashes[] = substr($hash, 0, 12);
            }
        }

        $sessions = $this->optionalFetchAll(
            'SELECT session_key_hash, mgw_id, device_id, provider, issued_at_utc,
                    last_seen_at_utc, expires_at_utc, revoked_at_utc
             FROM mgw_sessions
             WHERE mgw_id IN (:first_mgw_id, :second_mgw_id)
             ORDER BY issued_at_utc DESC
             LIMIT 200',
            ['first_mgw_id' => $firstMgwId, 'second_mgw_id' => $secondMgwId],
            $sourceAvailability,
            'sessions'
        );

        $matchStart = $this->date($match['started_at_utc'] ?? $match['created_at_utc'] ?? null);
        $matchEnd = $this->date($match['finished_at_utc'] ?? $match['updated_at_utc'] ?? null) ?? $matchStart;
        $overlapByHash = [];
        $publicSessions = [$firstMgwId => [], $secondMgwId => []];

        foreach ($sessions as $session) {
            $mgwId = trim((string)($session['mgw_id'] ?? ''));
            if (!isset($publicSessions[$mgwId])) continue;
            $issued = $this->date($session['issued_at_utc'] ?? null);
            $expires = $this->date($session['expires_at_utc'] ?? null);
            $revoked = $this->date($session['revoked_at_utc'] ?? null);
            $lastSeen = $this->date($session['last_seen_at_utc'] ?? null);
            $effectiveEnd = $revoked ?? $expires ?? $lastSeen;
            $overlaps = $matchStart !== null && $matchEnd !== null
                && $issued !== null && $effectiveEnd !== null
                && $issued <= $matchEnd && $effectiveEnd >= $matchStart;

            $deviceId = (string)($session['device_id'] ?? '');
            $hash = $deviceId !== '' ? ($deviceHashById[$deviceId] ?? '') : '';
            if ($overlaps && $hash !== '') $overlapByHash[$hash][$mgwId] = true;

            if (count($publicSessions[$mgwId]) < 12) {
                $publicSessions[$mgwId][] = [
                    'session_prefix' => substr((string)($session['session_key_hash'] ?? ''), 0, 10),
                    'device_hash_prefix' => $hash !== '' ? substr($hash, 0, 12) : '',
                    'provider' => (string)($session['provider'] ?? ''),
                    'issued_at' => $session['issued_at_utc'] ?? null,
                    'last_seen_at' => $session['last_seen_at_utc'] ?? null,
                    'expires_at' => $session['expires_at_utc'] ?? null,
                    'revoked_at' => $session['revoked_at_utc'] ?? null,
                    'overlaps_match' => $overlaps,
                ];
            }
        }

        $sharedWindow = [];
        foreach ($overlapByHash as $hash => $owners) {
            if (isset($owners[$firstMgwId], $owners[$secondMgwId])) {
                $sharedWindow[] = substr($hash, 0, 12);
            }
        }

        return [
            'players' => [
                $firstMgwId => ['sessions' => $publicSessions[$firstMgwId] ?? []],
                $secondMgwId => ['sessions' => $publicSessions[$secondMgwId] ?? []],
            ],
            'shared_device_hash_prefixes' => array_values(array_unique($sharedHashes)),
            'shared_match_window_device_hash_prefixes' => array_values(array_unique($sharedWindow)),
        ];
    }

    private function ratingAntiFarmingSignal(string $matchId, array &$sourceAvailability): ?array
    {
        $rows = $this->optionalFetchAll(
            'SELECT anti_farming_limited, points_requested, points_delta, outcome_code
             FROM mgw_game_rating_outcomes
             WHERE match_id = :match_id
             LIMIT 1',
            ['match_id' => $matchId],
            $sourceAvailability,
            'rating_antifarming'
        );
        if ($rows === []) return null;
        $row = $rows[0];
        if ((int)($row['anti_farming_limited'] ?? 0) !== 1) return null;

        return [
            'code' => 'rating_antifarming_limited',
            'severity' => 'medium',
            'label' => 'Сработал лимит повторных побед рейтинга',
            'details' => [
                'points_requested' => (int)($row['points_requested'] ?? 0),
                'points_awarded' => (int)($row['points_delta'] ?? 0),
                'outcome_code' => (string)($row['outcome_code'] ?? ''),
            ],
        ];
    }

    private function detectSignals(
        array $replay,
        array $pairHistory,
        array $deviceSession,
        ?array $ratingSignal
    ): array {
        $signals = [];
        if ($ratingSignal !== null) $signals[] = $ratingSignal;

        $sharedWindow = is_array($deviceSession['shared_match_window_device_hash_prefixes'] ?? null)
            ? $deviceSession['shared_match_window_device_hash_prefixes']
            : [];
        $sharedAll = is_array($deviceSession['shared_device_hash_prefixes'] ?? null)
            ? $deviceSession['shared_device_hash_prefixes']
            : [];

        if ($sharedWindow !== []) {
            $signals[] = [
                'code' => 'shared_device_match_window',
                'severity' => 'high',
                'label' => 'Оба игрока использовали один device-key в окне матча',
                'details' => ['device_hash_prefixes' => $sharedWindow],
            ];
        } elseif ($sharedAll !== []) {
            $signals[] = [
                'code' => 'shared_device_history',
                'severity' => 'medium',
                'label' => 'У игроков есть общий device-key в истории',
                'details' => ['device_hash_prefixes' => $sharedAll],
            ];
        }

        $match = is_array($replay['match'] ?? null) ? $replay['match'] : [];
        $targetTime = $this->date($match['finished_at_utc'] ?? $match['started_at_utc'] ?? $match['created_at_utc'] ?? null);
        if ($targetTime !== null && $pairHistory !== []) {
            $samePair24h = 0;
            foreach ($pairHistory as $history) {
                $time = $this->date($history['finished_at_utc'] ?? $history['started_at_utc'] ?? $history['created_at_utc'] ?? null);
                if ($time === null) continue;
                $delta = abs($targetTime->getTimestamp() - $time->getTimestamp());
                if ($delta <= 86400) $samePair24h++;
            }

            $threshold = 4;
            try {
                $value = $this->database->fetchValue(
                    'SELECT max_credited_wins_same_opponent_day
                     FROM mgw_leaderboard_control WHERE control_key = :control_key',
                    ['control_key' => 'global']
                );
                if ($value !== null) $threshold = max(2, (int)$value + 1);
            } catch (Throwable) {
                // Rating anti-farming control is an optional signal source here.
            }

            if ($samePair24h >= $threshold) {
                $signals[] = [
                    'code' => 'repeat_pair_24h',
                    'severity' => 'medium',
                    'label' => 'Частые матчи одной пары за сутки',
                    'details' => [
                        'matches_24h' => $samePair24h,
                        'review_threshold' => $threshold,
                    ],
                ];
            }
        }

        $diagnostics = is_array($replay['diagnostics'] ?? null) ? $replay['diagnostics'] : [];
        if (($diagnostics['replayable'] ?? true) !== true) {
            $signals[] = [
                'code' => 'replay_integrity_gap',
                'severity' => 'low',
                'label' => 'Replay-цепочка неполна',
                'details' => [
                    'missing_snapshot_versions' => $diagnostics['missing_snapshot_versions'] ?? [],
                ],
            ];
        }

        return $signals;
    }

    private function timingSummary(array $timeline): array
    {
        $byActor = [];
        foreach ($timeline as $event) {
            if (!is_array($event)) continue;
            $actor = trim((string)($event['actor_user_id'] ?? ''));
            $at = $this->date($event['occurred_at_utc'] ?? null);
            if ($actor === '' || $at === null) continue;
            $byActor[$actor][] = $at->getTimestamp() + ((int)$at->format('u') / 1_000_000);
        }

        $result = [];
        foreach ($byActor as $actor => $times) {
            sort($times, SORT_NUMERIC);
            $intervals = [];
            for ($i = 1, $count = count($times); $i < $count; $i++) {
                $intervals[] = max(0.0, $times[$i] - $times[$i - 1]);
            }
            sort($intervals, SORT_NUMERIC);
            $median = null;
            if ($intervals !== []) {
                $middle = intdiv(count($intervals), 2);
                $median = count($intervals) % 2 === 0
                    ? ($intervals[$middle - 1] + $intervals[$middle]) / 2
                    : $intervals[$middle];
            }
            $result[$actor] = [
                'action_count' => count($times),
                'min_interval_seconds' => $intervals !== [] ? round((float)$intervals[0], 3) : null,
                'median_interval_seconds' => $median !== null ? round((float)$median, 3) : null,
            ];
        }
        return $result;
    }

    private function signalsForCase(string $caseId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT signal_code, severity_code, label, details_json, detected_at_utc
             FROM mgw_antifraud_case_signals
             WHERE case_id = :case_id
             ORDER BY CASE severity_code WHEN \'high\' THEN 0 WHEN \'medium\' THEN 1 ELSE 2 END,
                      signal_code',
            ['case_id' => $caseId]
        );
        return array_map(function (array $row): array {
            return [
                'code' => (string)($row['signal_code'] ?? ''),
                'severity' => (string)($row['severity_code'] ?? ''),
                'label' => (string)($row['label'] ?? ''),
                'details' => $this->decodeJson((string)($row['details_json'] ?? '{}')),
                'detected_at' => $row['detected_at_utc'] ?? null,
            ];
        }, $rows);
    }

    private function eventsForCase(string $caseId): array
    {
        return $this->database->fetchAll(
            'SELECT event_id, event_type, actor_ref, previous_value, next_value, note, created_at_utc
             FROM mgw_antifraud_case_events
             WHERE case_id = :case_id
             ORDER BY event_id ASC',
            ['case_id' => $caseId]
        );
    }

    private function insertEvent(
        string $caseId,
        string $eventType,
        string $actorRef,
        ?string $previous,
        ?string $next,
        ?string $note,
        string $now
    ): void {
        $this->database->execute(
            'INSERT INTO mgw_antifraud_case_events (
                case_id, event_type, actor_ref, previous_value, next_value, note, created_at_utc
             ) VALUES (
                :case_id, :event_type, :actor_ref, :previous_value, :next_value, :note, :created_at
             )',
            [
                'case_id' => $caseId,
                'event_type' => $eventType,
                'actor_ref' => $actorRef,
                'previous_value' => $previous,
                'next_value' => $next,
                'note' => $note,
                'created_at' => $now,
            ]
        );
    }

    private function normalizeCaseRow(array $row): array
    {
        $status = (string)($row['status_code'] ?? self::STATUS_OPEN);
        $decision = (string)($row['decision_code'] ?? self::DECISION_PENDING);
        return [
            'case_id' => (string)($row['case_id'] ?? ''),
            'match_id' => (string)($row['match_id'] ?? ''),
            'status' => $status,
            'status_label' => $this->statusLabels()[$status] ?? $status,
            'priority' => (string)($row['priority_code'] ?? 'normal'),
            'decision' => $decision,
            'decision_label' => $this->decisionLabels()[$decision] ?? $decision,
            'summary' => (string)($row['summary'] ?? ''),
            'owner_ref' => $row['owner_ref'] !== null ? (string)$row['owner_ref'] : null,
            'created_by_admin_ref' => (string)($row['created_by_admin_ref'] ?? ''),
            'created_at' => $row['created_at_utc'] ?? null,
            'updated_at' => $row['updated_at_utc'] ?? null,
            'reviewed_at' => $row['reviewed_at_utc'] ?? null,
            'closed_at' => $row['closed_at_utc'] ?? null,
            'game_type' => (string)($row['game_type'] ?? ''),
            'match_status' => (string)($row['match_status'] ?? ''),
            'match_finished_at' => $row['finished_at_utc'] ?? null,
            'signal_count' => (int)($row['signal_count'] ?? 0),
        ];
    }

    private function optionalFetchAll(
        string $sql,
        array $parameters,
        array &$availability,
        string $key
    ): array {
        try {
            return $this->database->fetchAll($sql, $parameters);
        } catch (Throwable) {
            $availability[$key] = false;
            return [];
        }
    }

    private function requiredActor(string $actorRef): string
    {
        $actorRef = $this->bounded($actorRef, 191);
        if ($actorRef === '') throw new AntiFraudCaseException('actor_required', 'Не удалось определить администратора.');
        return $actorRef;
    }

    private function bounded(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('mb_substr')) return mb_substr($value, 0, $max);
        return substr($value, 0, $max);
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d H:i:s.u');
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decodeJson(string $value): mixed
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['_invalid_json' => true];
        }
    }
}
