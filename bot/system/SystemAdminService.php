<?php
declare(strict_types=1);

/**
 * MVP-22.5 durable operator-state owner.
 *
 * Canonical product state stays with its existing owners:
 * - account count is read from mgw_users/mgw_identities (no parallel counter);
 * - competition state stays in mgw_rating_control;
 * - season lifecycle stays in SeasonLifecycleService;
 * - notifications stay in AdminNotificationEventService.
 *
 * This service stores only Admin readiness/checklist/audit state and coordinates
 * explicit transitions through those existing owners.
 */
final class SystemAdminService
{
    public const CONTROL_KEY = 'global';
    public const READINESS_THRESHOLD = 500;

    public const CHECKLIST_KEYS = [
        'official_season_rehearsal',
        'arena_presentation',
        'profile_presentation',
        'seasonal_badges',
        'top3_frames',
        'yearly_medal',
        'archive_hof',
        'ready_close',
        'missing_assets_recovery',
        'review_recalculation',
        'identity_exclusions',
        'idempotency_retries',
        'launch_notification_localization',
        'staging_e2e_green',
        'manual_acceptance',
    ];

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function snapshot(string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $userCount = $this->canonicalUserCount();
        $this->syncReadiness($userCount);
        $control = $this->systemControl(false);
        $competition = $this->competitionControl(false);
        $checklist = $this->decodeChecklist($control['staging_checklist_json'] ?? null);

        $readyReached = $control['readiness_reached_at_utc'] !== null;
        $readyAcknowledged = $control['readiness_acknowledged_at_utc'] !== null;
        $stagingAccepted = $control['staging_accepted_sha'] !== null
            && $this->checklistComplete($checklist);
        $isActive = $competition['competition_state'] === PerGameRatingService::STATE_ACTIVE;

        return [
            'environment' => $environment,
            'users' => [
                'canonical_count' => $userCount,
                'threshold' => (int)$control['readiness_threshold'],
            ],
            'readiness' => [
                'reached' => $readyReached,
                'reached_at' => $control['readiness_reached_at_utc'],
                'acknowledged' => $readyAcknowledged,
                'acknowledged_at' => $control['readiness_acknowledged_at_utc'],
                'acknowledged_by' => $control['readiness_acknowledged_by'],
                'alert_visible' => $readyReached && !$readyAcknowledged && !$isActive,
            ],
            'staging_acceptance' => [
                'accepted' => $stagingAccepted,
                'sha' => $control['staging_accepted_sha'],
                'accepted_by' => $control['staging_accepted_by'],
                'accepted_at' => $control['staging_accepted_at_utc'],
                'checklist' => $checklist,
                'notes' => $control['staging_notes'],
                'required_keys' => self::CHECKLIST_KEYS,
            ],
            'staging_rehearsal' => [
                'active' => (bool)$control['staging_rehearsal_active'],
                'started_at' => $control['staging_rehearsal_started_at_utc'],
                'started_by' => $control['staging_rehearsal_started_by'],
                'available' => in_array($environment, ['staging', 'local'], true),
            ],
            'competition' => $competition,
            'activation' => [
                'production_action_available' => $environment === 'production'
                    && !$isActive
                    && $readyReached
                    && $stagingAccepted,
                'announcement_sent' => $control['activation_announcement_sent_at_utc'] !== null,
                'announcement_event_id' => $control['activation_announcement_event_id'],
                'announcement_sent_at' => $control['activation_announcement_sent_at_utc'],
            ],
            'recent_audit' => $this->recentAudit(20),
        ];
    }

    public function acknowledgeReadiness(string $actorRef, string $reason): array
    {
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $reason = $this->requiredText($reason, 800, 'reason');
        $userCount = $this->canonicalUserCount();
        $this->syncReadiness($userCount);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($actorRef, $reason): array {
            $before = $this->systemControl(true);
            if ($before['readiness_reached_at_utc'] === null) {
                throw new RuntimeException('Порог готовности ещё не достигнут.');
            }
            if ($before['readiness_acknowledged_at_utc'] !== null) return $before;

            $now = $this->timestamp();
            $database->execute(
                'UPDATE mgw_system_admin_control
                 SET readiness_acknowledged_at_utc=:ack_at,
                     readiness_acknowledged_by=:ack_by,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'ack_at'=>$now,
                    'ack_by'=>$actorRef,
                    'updated_at'=>$now,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );
            $after = $this->systemControl(true);
            $this->audit($database, 'readiness_acknowledged', $actorRef, $reason, $before, $after, $now);
            return $after;
        });
    }

    public function acceptStaging(
        string $sha,
        array $checklist,
        string $notes,
        string $actorRef
    ): array {
        $sha = strtolower(trim($sha));
        if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
            throw new InvalidArgumentException('Нужен точный 40-символьный SHA принятого staging.');
        }
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $notes = $this->optionalText($notes, 2000);
        $normalized = $this->normalizeChecklist($checklist);
        if (!$this->checklistComplete($normalized)) {
            throw new InvalidArgumentException('Перед фиксацией staging acceptance нужно подтвердить все пункты checklist.');
        }

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $sha,
            $normalized,
            $notes,
            $actorRef
        ): array {
            $before = $this->systemControl(true);
            $now = $this->timestamp();
            $database->execute(
                'UPDATE mgw_system_admin_control
                 SET staging_accepted_sha=:sha,
                     staging_accepted_by=:actor_ref,
                     staging_accepted_at_utc=:accepted_at,
                     staging_checklist_json=:checklist_json,
                     staging_notes=:notes,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'sha'=>$sha,
                    'actor_ref'=>$actorRef,
                    'accepted_at'=>$now,
                    'checklist_json'=>json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'notes'=>$notes !== '' ? $notes : null,
                    'updated_at'=>$now,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );
            $after = $this->systemControl(true);
            $this->audit($database, 'staging_acceptance_recorded', $actorRef, $notes, $before, $after, $now);
            return $after;
        });
    }

    public function startStagingRehearsal(
        string $environment,
        string $actorRef,
        string $reason,
        ?DateTimeImmutable $now = null
    ): array {
        $environment = $this->normalizeEnvironment($environment);
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Staging rehearsal разрешён только в staging/local.');
        }
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $reason = $this->requiredText($reason, 800, 'reason');
        $now = $this->utcNow($now);
        $nowText = $now->format('Y-m-d H:i:s.u');
        $season = (new SeasonCalendar())->definitionForInstant($now);

        $result = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $actorRef,
            $reason,
            $now,
            $nowText,
            $season
        ): array {
            $systemBefore = $this->systemControl(true);
            $competitionBefore = $this->competitionControl(true);
            if ((bool)$systemBefore['staging_rehearsal_active']) {
                return ['system'=>$systemBefore, 'competition'=>$competitionBefore, 'already_active'=>true];
            }
            if ($competitionBefore['competition_state'] !== PerGameRatingService::STATE_PRESEASON) {
                throw new RuntimeException('Staging rehearsal можно начать только из PRESEASON.');
            }

            $database->execute(
                'UPDATE mgw_rating_control
                 SET competition_state=:state,
                     current_season_id=:season_id,
                     activated_at_utc=:activated_at,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'state'=>PerGameRatingService::STATE_ACTIVE,
                    'season_id'=>(string)$season['season_id'],
                    'activated_at'=>$nowText,
                    'updated_at'=>$nowText,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );
            $database->execute(
                'UPDATE mgw_system_admin_control
                 SET staging_rehearsal_active=1,
                     staging_rehearsal_started_at_utc=:started_at,
                     staging_rehearsal_started_by=:started_by,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'started_at'=>$nowText,
                    'started_by'=>$actorRef,
                    'updated_at'=>$nowText,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );

            $lifecycle = (new SeasonLifecycleService($database))->reconcile($now);
            $systemAfter = $this->systemControl(true);
            $competitionAfter = $this->competitionControl(true);
            $this->audit(
                $database,
                'staging_rehearsal_started',
                $actorRef,
                $reason,
                ['system'=>$systemBefore, 'competition'=>$competitionBefore],
                ['system'=>$systemAfter, 'competition'=>$competitionAfter],
                $nowText
            );
            return [
                'system'=>$systemAfter,
                'competition'=>$competitionAfter,
                'lifecycle'=>$lifecycle,
                'already_active'=>false,
            ];
        });

        return $result;
    }

    public function stopStagingRehearsal(
        string $environment,
        string $actorRef,
        string $reason,
        ?DateTimeImmutable $now = null
    ): array {
        $environment = $this->normalizeEnvironment($environment);
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Staging rehearsal разрешён только в staging/local.');
        }
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $reason = $this->requiredText($reason, 800, 'reason');
        $nowText = $this->utcNow($now)->format('Y-m-d H:i:s.u');

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $actorRef,
            $reason,
            $nowText
        ): array {
            $systemBefore = $this->systemControl(true);
            $competitionBefore = $this->competitionControl(true);
            if (!(bool)$systemBefore['staging_rehearsal_active']) {
                return ['system'=>$systemBefore, 'competition'=>$competitionBefore, 'already_stopped'=>true];
            }

            $database->execute(
                'UPDATE mgw_rating_control
                 SET competition_state=:state,
                     current_season_id=:season_id,
                     activated_at_utc=NULL,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'state'=>PerGameRatingService::STATE_PRESEASON,
                    'season_id'=>PerGameRatingService::PRESEASON_ID,
                    'updated_at'=>$nowText,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );
            $database->execute(
                'UPDATE mgw_system_admin_control
                 SET staging_rehearsal_active=0,
                     staging_rehearsal_started_at_utc=NULL,
                     staging_rehearsal_started_by=NULL,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'updated_at'=>$nowText,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );

            $systemAfter = $this->systemControl(true);
            $competitionAfter = $this->competitionControl(true);
            $this->audit(
                $database,
                'staging_rehearsal_stopped',
                $actorRef,
                $reason,
                ['system'=>$systemBefore, 'competition'=>$competitionBefore],
                ['system'=>$systemAfter, 'competition'=>$competitionAfter],
                $nowText
            );
            return ['system'=>$systemAfter, 'competition'=>$competitionAfter, 'already_stopped'=>false];
        });
    }

    public function activateOfficialCompetition(
        string $environment,
        string $actorRef,
        string $reason,
        ?DateTimeImmutable $now = null
    ): array {
        $environment = $this->normalizeEnvironment($environment);
        if ($environment !== 'production') {
            throw new RuntimeException('Production activation нельзя выполнить из staging/local.');
        }
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $reason = $this->requiredText($reason, 800, 'reason');
        $now = $this->utcNow($now);
        $nowText = $now->format('Y-m-d H:i:s.u');
        $userCount = $this->canonicalUserCount();
        $this->syncReadiness($userCount);
        $season = (new SeasonCalendar())->definitionForInstant($now);

        $result = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $actorRef,
            $reason,
            $now,
            $nowText,
            $userCount,
            $season
        ): array {
            $systemBefore = $this->systemControl(true);
            $competitionBefore = $this->competitionControl(true);

            if ($competitionBefore['competition_state'] === PerGameRatingService::STATE_ACTIVE) {
                return [
                    'competition'=>$competitionBefore,
                    'already_active'=>true,
                    'announcement_required'=>$systemBefore['activation_announcement_sent_at_utc'] === null,
                ];
            }
            if ($competitionBefore['competition_state'] !== PerGameRatingService::STATE_PRESEASON) {
                throw new RuntimeException('Official competition можно активировать только из PRESEASON.');
            }
            if ($userCount < (int)$systemBefore['readiness_threshold']) {
                throw new RuntimeException('Production readiness threshold ещё не достигнут.');
            }
            $checklist = $this->decodeChecklist($systemBefore['staging_checklist_json'] ?? null);
            if ($systemBefore['staging_accepted_sha'] === null || !$this->checklistComplete($checklist)) {
                throw new RuntimeException('Сначала нужен полный manual staging acceptance checklist.');
            }

            $database->execute(
                'UPDATE mgw_rating_control
                 SET competition_state=:state,
                     current_season_id=:season_id,
                     activated_at_utc=:activated_at,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'state'=>PerGameRatingService::STATE_ACTIVE,
                    'season_id'=>(string)$season['season_id'],
                    'activated_at'=>$nowText,
                    'updated_at'=>$nowText,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );

            $lifecycle = (new SeasonLifecycleService($database))->reconcile($now);
            $competitionAfter = $this->competitionControl(true);
            $this->audit(
                $database,
                'official_competition_activated',
                $actorRef,
                $reason,
                ['system'=>$systemBefore, 'competition'=>$competitionBefore, 'user_count'=>$userCount],
                ['system'=>$systemBefore, 'competition'=>$competitionAfter, 'user_count'=>$userCount],
                $nowText
            );

            return [
                'competition'=>$competitionAfter,
                'lifecycle'=>$lifecycle,
                'already_active'=>false,
                'announcement_required'=>$systemBefore['activation_announcement_sent_at_utc'] === null,
            ];
        });

        return $result;
    }

    public function markActivationAnnouncement(string $eventId, string $actorRef): array
    {
        $eventId = $this->requiredText($eventId, 96, 'announcement event id');
        $actorRef = $this->requiredText($actorRef, 191, 'actor');

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($eventId, $actorRef): array {
            $before = $this->systemControl(true);
            if ($before['activation_announcement_sent_at_utc'] !== null) return $before;
            $competition = $this->competitionControl(true);
            if ($competition['competition_state'] !== PerGameRatingService::STATE_ACTIVE) {
                throw new RuntimeException('Activation announcement can be marked only after ACTIVE.');
            }
            $now = $this->timestamp();
            $database->execute(
                'UPDATE mgw_system_admin_control
                 SET activation_announcement_event_id=:event_id,
                     activation_announcement_sent_at_utc=:sent_at,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'event_id'=>$eventId,
                    'sent_at'=>$now,
                    'updated_at'=>$now,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );
            $after = $this->systemControl(true);
            $this->audit($database, 'activation_announcement_sent', $actorRef, 'canonical global launch announcement', $before, $after, $now);
            return $after;
        });
    }

    public function recordAudit(
        string $actionCode,
        string $actorRef,
        string $reason,
        array $before,
        array $after
    ): void {
        $actionCode = strtolower($this->requiredText($actionCode, 64, 'action'));
        if (preg_match('/^[a-z0-9_:-]+$/', $actionCode) !== 1) {
            throw new InvalidArgumentException('Invalid system audit action.');
        }
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $reason = $this->requiredText($reason, 800, 'reason');
        $this->audit($this->database, $actionCode, $actorRef, $reason, $before, $after, $this->timestamp());
    }

    private function syncReadiness(int $userCount): void
    {
        $control = $this->systemControl(false);
        if ($control['readiness_reached_at_utc'] !== null
            || $userCount < (int)$control['readiness_threshold']) {
            return;
        }

        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($userCount): void {
            $before = $this->systemControl(true);
            if ($before['readiness_reached_at_utc'] !== null
                || $userCount < (int)$before['readiness_threshold']) {
                return;
            }
            $now = $this->timestamp();
            $database->execute(
                'UPDATE mgw_system_admin_control
                 SET readiness_reached_at_utc=:reached_at,
                     updated_at_utc=:updated_at
                 WHERE control_key=:control_key',
                [
                    'reached_at'=>$now,
                    'updated_at'=>$now,
                    'control_key'=>self::CONTROL_KEY,
                ]
            );
            $after = $this->systemControl(true);
            $this->audit(
                $database,
                'readiness_threshold_reached',
                'system:user-count',
                'Canonical MGW account count reached the official competition readiness threshold.',
                $before + ['canonical_user_count'=>$userCount],
                $after + ['canonical_user_count'=>$userCount],
                $now
            );
        });
    }

    private function canonicalUserCount(): int
    {
        $rows = $this->database->fetchAll(
            'SELECT COUNT(*) AS total
             FROM mgw_users u
             WHERE NOT EXISTS (
                 SELECT 1
                 FROM mgw_identities dev_identity
                 WHERE dev_identity.mgw_id = u.mgw_id
                   AND dev_identity.provider = :development_provider
             )',
            ['development_provider'=>'development']
        );
        return max(0, (int)($rows[0]['total'] ?? 0));
    }

    private function systemControl(bool $forUpdate): array
    {
        $sql = 'SELECT control_key, readiness_threshold,
                       readiness_reached_at_utc, readiness_acknowledged_at_utc,
                       readiness_acknowledged_by,
                       staging_accepted_sha, staging_accepted_by, staging_accepted_at_utc,
                       staging_checklist_json, staging_notes,
                       staging_rehearsal_active, staging_rehearsal_started_at_utc,
                       staging_rehearsal_started_by,
                       activation_announcement_event_id,
                       activation_announcement_sent_at_utc,
                       updated_at_utc
                FROM mgw_system_admin_control
                WHERE control_key=:control_key';
        if ($forUpdate && $this->database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';

        $rows = $this->database->fetchAll($sql, ['control_key'=>self::CONTROL_KEY]);
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-22.5 system admin control is unavailable.');
        }
        $row = $rows[0];
        return [
            'control_key'=>self::CONTROL_KEY,
            'readiness_threshold'=>max(1, (int)($row['readiness_threshold'] ?? self::READINESS_THRESHOLD)),
            'readiness_reached_at_utc'=>$this->nullableText($row['readiness_reached_at_utc'] ?? null),
            'readiness_acknowledged_at_utc'=>$this->nullableText($row['readiness_acknowledged_at_utc'] ?? null),
            'readiness_acknowledged_by'=>$this->nullableText($row['readiness_acknowledged_by'] ?? null),
            'staging_accepted_sha'=>$this->nullableText($row['staging_accepted_sha'] ?? null),
            'staging_accepted_by'=>$this->nullableText($row['staging_accepted_by'] ?? null),
            'staging_accepted_at_utc'=>$this->nullableText($row['staging_accepted_at_utc'] ?? null),
            'staging_checklist_json'=>$this->nullableText($row['staging_checklist_json'] ?? null),
            'staging_notes'=>$this->nullableText($row['staging_notes'] ?? null),
            'staging_rehearsal_active'=>(int)($row['staging_rehearsal_active'] ?? 0) === 1,
            'staging_rehearsal_started_at_utc'=>$this->nullableText($row['staging_rehearsal_started_at_utc'] ?? null),
            'staging_rehearsal_started_by'=>$this->nullableText($row['staging_rehearsal_started_by'] ?? null),
            'activation_announcement_event_id'=>$this->nullableText($row['activation_announcement_event_id'] ?? null),
            'activation_announcement_sent_at_utc'=>$this->nullableText($row['activation_announcement_sent_at_utc'] ?? null),
            'updated_at_utc'=>$this->nullableText($row['updated_at_utc'] ?? null),
        ];
    }

    private function competitionControl(bool $forUpdate): array
    {
        $sql = 'SELECT competition_state, current_season_id, tracking_started_at_utc,
                       activated_at_utc, updated_at_utc
                FROM mgw_rating_control
                WHERE control_key=:control_key';
        if ($forUpdate && $this->database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';

        $rows = $this->database->fetchAll($sql, ['control_key'=>self::CONTROL_KEY]);
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20 rating control is unavailable.');
        }
        $row = $rows[0];
        return [
            'competition_state'=>strtolower(trim((string)($row['competition_state'] ?? ''))),
            'current_season_id'=>trim((string)($row['current_season_id'] ?? '')),
            'tracking_started_at_utc'=>$this->nullableText($row['tracking_started_at_utc'] ?? null),
            'activated_at_utc'=>$this->nullableText($row['activated_at_utc'] ?? null),
            'updated_at_utc'=>$this->nullableText($row['updated_at_utc'] ?? null),
        ];
    }

    private function recentAudit(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->database->fetchAll(
            'SELECT audit_id, action_code, actor_ref, reason_text, created_at_utc
             FROM mgw_system_admin_audit
             ORDER BY audit_id DESC
             LIMIT ' . $limit
        );
    }

    private function audit(
        DatabaseConnectionInterface $database,
        string $actionCode,
        string $actorRef,
        string $reason,
        array $before,
        array $after,
        string $createdAt
    ): void {
        $database->execute(
            'INSERT INTO mgw_system_admin_audit (
                action_code, actor_ref, reason_text, before_json, after_json, created_at_utc
             ) VALUES (
                :action_code, :actor_ref, :reason_text, :before_json, :after_json, :created_at
             )',
            [
                'action_code'=>$actionCode,
                'actor_ref'=>$actorRef,
                'reason_text'=>$reason !== '' ? $reason : null,
                'before_json'=>json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'after_json'=>json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at'=>$createdAt,
            ]
        );
    }

    private function normalizeChecklist(array $checklist): array
    {
        $normalized = [];
        foreach (self::CHECKLIST_KEYS as $key) {
            $normalized[$key] = ($checklist[$key] ?? false) === true
                || (int)($checklist[$key] ?? 0) === 1
                || strtolower(trim((string)($checklist[$key] ?? ''))) === 'true';
        }
        return $normalized;
    }

    private function decodeChecklist(mixed $json): array
    {
        $json = trim((string)($json ?? ''));
        if ($json === '') return $this->normalizeChecklist([]);
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->normalizeChecklist([]);
        }
        return $this->normalizeChecklist(is_array($decoded) ? $decoded : []);
    }

    private function checklistComplete(array $checklist): bool
    {
        foreach (self::CHECKLIST_KEYS as $key) {
            if (($checklist[$key] ?? false) !== true) return false;
        }
        return true;
    }

    private function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        return in_array($environment, ['production', 'staging', 'local'], true)
            ? $environment
            : 'production';
    }

    private function requiredText(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if ($value === '') throw new InvalidArgumentException($label . ' is required.');
        return mb_substr($value, 0, $max);
    }

    private function optionalText(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function utcNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function timestamp(): string
    {
        return $this->utcNow(null)->format('Y-m-d H:i:s.u');
    }
}
