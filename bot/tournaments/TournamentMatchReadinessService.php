<?php
declare(strict_types=1);

final class TournamentMatchReadinessService
{
    public const FIRST_ROUND = 1;
    public const READY_WINDOW_SECONDS = 120;
    public const STATE_WAITING_READY = 'waiting_ready';
    public const STATE_READY = 'ready';
    public const STATE_LAUNCHED = 'launched';
    public const STATE_READINESS_EXPIRED = 'readiness_expired';

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function status(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        ?DateTimeImmutable $now = null
    ): array {
        $participant = $this->participant($mgwId, $accountRef, $legacyUserId);
        $moment = $this->moment($now);
        $row = $this->ensureFirstRoundPair($participant, $moment);
        if ($row === null) {
            return [
                'tournament_id'=>(string)$participant['tournament_id'],
                'match'=>null,
            ];
        }

        $row = $this->expireIfNeeded($row, $moment);
        if (!$this->isInitialManualReadyRow($row)) {
            return [
                'tournament_id'=>(string)$participant['tournament_id'],
                'match'=>null,
            ];
        }
        return [
            'tournament_id'=>(string)$participant['tournament_id'],
            'match'=>$this->publicMatch($row, $mgwId, $moment),
        ];
    }

    public function markReady(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        ?DateTimeImmutable $now = null
    ): array {
        $participant = $this->participant($mgwId, $accountRef, $legacyUserId);
        $moment = $this->moment($now);
        $row = $this->ensureFirstRoundPair($participant, $moment);
        if ($row === null) {
            throw new RuntimeException('Для этой пары подтверждение готовности не требуется.');
        }
        if ((int)($row['attempt_no'] ?? 1) !== 1
            || (string)($row['wait_kind'] ?? 'initial_ready') !== 'initial_ready'
            || trim((string)($row['completed_at_utc'] ?? '')) !== '') {
            throw new RuntimeException('Ручное подтверждение готовности для этой стадии уже завершено.');
        }

        $openedAt = $this->parseUtc((string)$row['readiness_opened_at_utc']);
        $deadline = $this->parseUtc((string)$row['readiness_deadline_at_utc']);
        if ($moment < $openedAt) {
            throw new RuntimeException('Подтверждение готовности откроется в момент старта турнира.');
        }
        if ($moment > $deadline && !$this->bothReady($row)) {
            $this->expireIfNeeded($row, $moment);
            throw new RuntimeException('Двухминутное окно готовности завершено.');
        }

        $tournamentId = (string)$row['tournament_id'];
        $pairNo = (int)$row['pair_no'];
        $readyAt = $this->utc($moment);

        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $pairNo,
            $mgwId,
            $readyAt,
            $moment
        ): void {
            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id
                   AND round_no=:round_no
                   AND pair_no=:pair_no' . $this->forUpdate($db),
                [
                    'tournament_id'=>$tournamentId,
                    'round_no'=>self::FIRST_ROUND,
                    'pair_no'=>$pairNo,
                ]
            );
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Tournament readiness state is unavailable.');
            }
            $current = $rows[0];
            if (!in_array($mgwId, [
                (string)$current['player_a_mgw_id'],
                (string)$current['player_b_mgw_id'],
            ], true)) {
                throw new RuntimeException('Игрок не входит в эту турнирную пару.');
            }

            $deadline = $this->parseUtc((string)$current['readiness_deadline_at_utc']);
            if ($moment > $deadline && !$this->bothReady($current)) {
                $db->execute(
                    'UPDATE mgw_tournament_round_matches
                     SET launch_state=:launch_state,updated_at_utc=:updated_at_utc
                     WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no',
                    [
                        'launch_state'=>self::STATE_READINESS_EXPIRED,
                        'updated_at_utc'=>$readyAt,
                        'tournament_id'=>$tournamentId,
                        'round_no'=>self::FIRST_ROUND,
                        'pair_no'=>$pairNo,
                    ]
                );
                throw new RuntimeException('Двухминутное окно готовности завершено.');
            }

            $column = $mgwId === (string)$current['player_a_mgw_id']
                ? 'player_a_ready_at_utc'
                : 'player_b_ready_at_utc';
            if (trim((string)($current[$column] ?? '')) === '') {
                $db->execute(
                    'UPDATE mgw_tournament_round_matches
                     SET ' . $column . '=:ready_at_utc,updated_at_utc=:updated_at_utc
                     WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no',
                    [
                        'ready_at_utc'=>$readyAt,
                        'updated_at_utc'=>$readyAt,
                        'tournament_id'=>$tournamentId,
                        'round_no'=>self::FIRST_ROUND,
                        'pair_no'=>$pairNo,
                    ]
                );
            }

            $fresh = $db->fetchAll(
                'SELECT * FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no',
                [
                    'tournament_id'=>$tournamentId,
                    'round_no'=>self::FIRST_ROUND,
                    'pair_no'=>$pairNo,
                ]
            );
            if (count($fresh) !== 1 || !is_array($fresh[0])) {
                throw new RuntimeException('Tournament readiness state disappeared.');
            }
            if ($this->bothReady($fresh[0])
                && (string)($fresh[0]['launch_state'] ?? '') !== self::STATE_LAUNCHED) {
                $db->execute(
                    'UPDATE mgw_tournament_round_matches
                     SET launch_state=:launch_state,updated_at_utc=:updated_at_utc
                     WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no',
                    [
                        'launch_state'=>self::STATE_READY,
                        'updated_at_utc'=>$readyAt,
                        'tournament_id'=>$tournamentId,
                        'round_no'=>self::FIRST_ROUND,
                        'pair_no'=>$pairNo,
                    ]
                );
            }
        });

        return $this->status($mgwId, $accountRef, $legacyUserId, $moment);
    }

    public function launchContext(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        ?DateTimeImmutable $now = null
    ): ?array {
        $moment = $this->moment($now);
        $snapshot = $this->status($mgwId, $accountRef, $legacyUserId, $moment);
        $match = is_array($snapshot['match'] ?? null) ? $snapshot['match'] : null;
        if ($match === null || empty($match['both_ready'])) return null;

        $tournamentId = (string)$snapshot['tournament_id'];
        $pairNo = (int)$match['pair_no'];
        $rows = $this->database->fetchAll(
            'SELECT m.*,t.game_type
             FROM mgw_tournament_round_matches m
             INNER JOIN mgw_tournaments t ON t.tournament_id=m.tournament_id
             WHERE m.tournament_id=:tournament_id
               AND m.round_no=:round_no
               AND m.pair_no=:pair_no
             LIMIT 2',
            [
                'tournament_id'=>$tournamentId,
                'round_no'=>self::FIRST_ROUND,
                'pair_no'=>$pairNo,
            ]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament launch context is unavailable.');
        }
        $row = $rows[0];
        if ((int)($row['attempt_no'] ?? 1) !== 1
            || (string)($row['wait_kind'] ?? 'initial_ready') !== 'initial_ready'
            || !in_array((string)($row['launch_state'] ?? ''), [self::STATE_READY, self::STATE_LAUNCHED], true)) {
            return null;
        }
        $opensAt = $this->parseUtc((string)$row['readiness_opened_at_utc']);
        if ($moment < $opensAt) return null;

        $identities = $this->database->fetchAll(
            'SELECT r.mgw_id,o.legacy_user_id
             FROM mgw_tournament_registrations r
             INNER JOIN mgw_account_ownership o
               ON o.mgw_id=r.mgw_id
              AND o.account_ref=r.account_ref
              AND o.ownership_status=:ownership_status
             WHERE r.tournament_id=:tournament_id
               AND r.registration_state=:registration_state
               AND r.mgw_id IN (:player_a,:player_b)',
            [
                'ownership_status'=>'active',
                'tournament_id'=>$tournamentId,
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                'player_a'=>(string)$row['player_a_mgw_id'],
                'player_b'=>(string)$row['player_b_mgw_id'],
            ]
        );
        $legacyByMgw = [];
        foreach ($identities as $identity) {
            if (!is_array($identity)) continue;
            $id = trim((string)($identity['mgw_id'] ?? ''));
            $legacy = trim((string)($identity['legacy_user_id'] ?? ''));
            if ($id !== '' && $legacy !== '') $legacyByMgw[$id] = $legacy;
        }

        $a = (string)$row['player_a_mgw_id'];
        $b = (string)$row['player_b_mgw_id'];
        if (!isset($legacyByMgw[$a], $legacyByMgw[$b])) {
            throw new RuntimeException('Tournament players are missing canonical runtime identities.');
        }

        return [
            'tournament_id'=>$tournamentId,
            'round_no'=>self::FIRST_ROUND,
            'pair_no'=>$pairNo,
            'game_type'=>(string)$row['game_type'],
            'game_id'=>$this->expectedGameId($tournamentId, self::FIRST_ROUND, $pairNo),
            'attached_game_id'=>$this->nullableText($row['game_id'] ?? null),
            'players'=>[
                ['mgw_id'=>$a,'legacy_user_id'=>$legacyByMgw[$a]],
                ['mgw_id'=>$b,'legacy_user_id'=>$legacyByMgw[$b]],
            ],
        ];
    }

    public function attachGame(
        string $tournamentId,
        int $roundNo,
        int $pairNo,
        string $gameId,
        ?DateTimeImmutable $now = null
    ): void {
        $gameId = trim($gameId);
        if ($gameId === '') throw new InvalidArgumentException('Tournament game id is required.');
        $timestamp = $this->utc($this->moment($now));

        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $roundNo,
            $pairNo,
            $gameId,
            $timestamp
        ): void {
            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no'
                 . $this->forUpdate($db),
                [
                    'tournament_id'=>$tournamentId,
                    'round_no'=>$roundNo,
                    'pair_no'=>$pairNo,
                ]
            );
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Tournament match pair is unavailable.');
            }
            $row = $rows[0];
            if (!$this->bothReady($row)) {
                throw new RuntimeException('Tournament game cannot launch before both players are ready.');
            }
            $existing = trim((string)($row['game_id'] ?? ''));
            if ($existing !== '' && $existing !== $gameId) {
                throw new RuntimeException('Tournament pair is already attached to another game.');
            }
            if ($existing === $gameId && (string)$row['launch_state'] === self::STATE_LAUNCHED) return;

            $db->execute(
                'UPDATE mgw_tournament_round_matches
                 SET game_id=:game_id,launch_state=:launch_state,updated_at_utc=:updated_at_utc
                 WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no',
                [
                    'game_id'=>$gameId,
                    'launch_state'=>self::STATE_LAUNCHED,
                    'updated_at_utc'=>$timestamp,
                    'tournament_id'=>$tournamentId,
                    'round_no'=>$roundNo,
                    'pair_no'=>$pairNo,
                ]
            );
        });
    }

    public function expectedGameId(string $tournamentId, int $roundNo, int $pairNo): string
    {
        return 'game_tour_' . substr(
            hash('sha256', trim($tournamentId) . '|' . $roundNo . '|' . $pairNo),
            0,
            48
        );
    }

    private function ensureFirstRoundPair(array $participant, DateTimeImmutable $moment): ?array
    {
        $tournamentId = (string)$participant['tournament_id'];
        $generatedAt = trim((string)($participant['bracket_generated_at_utc'] ?? ''));
        if ($generatedAt === '') return null;

        $seedRows = $this->database->fetchAll(
            'SELECT seed_no,pair_no,mgw_id,present_at_start,technical_loss_at_start
             FROM mgw_tournament_bracket_seeds
             WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id
             LIMIT 2',
            [
                'tournament_id'=>$tournamentId,
                'mgw_id'=>$participant['mgw_id'],
            ]
        );
        if (count($seedRows) !== 1 || !is_array($seedRows[0])) {
            throw new RuntimeException('Tournament bracket participant is unavailable.');
        }
        $pairNo = (int)$seedRows[0]['pair_no'];

        $pair = $this->database->fetchAll(
            'SELECT seed_no,pair_no,mgw_id,present_at_start,technical_loss_at_start
             FROM mgw_tournament_bracket_seeds
             WHERE tournament_id=:tournament_id AND pair_no=:pair_no
             ORDER BY seed_no ASC',
            ['tournament_id'=>$tournamentId,'pair_no'=>$pairNo]
        );
        if (count($pair) !== 2 || !is_array($pair[0]) || !is_array($pair[1])) {
            throw new RuntimeException('Tournament first-round pair is incomplete.');
        }
        foreach ($pair as $seed) {
            if ((int)($seed['present_at_start'] ?? 0) !== 1
                || (int)($seed['technical_loss_at_start'] ?? 0) !== 0) {
                return null;
            }
        }

        $start = $this->parseUtc((string)$participant['scheduled_start_at_utc']);
        if ($moment < $start) return null;
        $deadline = $start->modify('+' . self::READY_WINDOW_SECONDS . ' seconds');
        $openedAt = $this->utc($start);
        $deadlineAt = $this->utc($deadline);
        $createdAt = $this->utc($moment);
        $a = (string)$pair[0]['mgw_id'];
        $b = (string)$pair[1]['mgw_id'];

        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $pairNo,
            $a,
            $b,
            $openedAt,
            $deadlineAt,
            $createdAt
        ): void {
            // Serialize first-round pair materialization on the tournament row.
            // Two live clients request match state at almost the same T0 instant;
            // without one shared lock both can observe a missing pair and race the
            // same PRIMARY KEY insert, surfacing a SQLSTATE to the player.
            $tournamentLock = $db->fetchAll(
                'SELECT tournament_id FROM mgw_tournaments
                 WHERE tournament_id=:tournament_id'
                 . $this->forUpdate($db),
                ['tournament_id'=>$tournamentId]
            );
            if (count($tournamentLock) !== 1) {
                throw new RuntimeException('Tournament readiness owner is unavailable.');
            }

            $existing = $db->fetchAll(
                'SELECT tournament_id FROM mgw_tournament_round_matches
                 WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no'
                 . $this->forUpdate($db),
                [
                    'tournament_id'=>$tournamentId,
                    'round_no'=>self::FIRST_ROUND,
                    'pair_no'=>$pairNo,
                ]
            );
            if ($existing !== []) return;

            $db->execute(
                'INSERT INTO mgw_tournament_round_matches (
                    tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
                    readiness_opened_at_utc,readiness_deadline_at_utc,
                    player_a_ready_at_utc,player_b_ready_at_utc,
                    launch_state,game_id,created_at_utc,updated_at_utc
                 ) VALUES (
                    :tournament_id,:round_no,:pair_no,:player_a_mgw_id,:player_b_mgw_id,
                    :readiness_opened_at_utc,:readiness_deadline_at_utc,
                    NULL,NULL,:launch_state,NULL,:created_at_utc,:updated_at_utc
                 )',
                [
                    'tournament_id'=>$tournamentId,
                    'round_no'=>self::FIRST_ROUND,
                    'pair_no'=>$pairNo,
                    'player_a_mgw_id'=>$a,
                    'player_b_mgw_id'=>$b,
                    'readiness_opened_at_utc'=>$openedAt,
                    'readiness_deadline_at_utc'=>$deadlineAt,
                    'launch_state'=>self::STATE_WAITING_READY,
                    'created_at_utc'=>$createdAt,
                    'updated_at_utc'=>$createdAt,
                ]
            );
        });

        return $this->pairRow($tournamentId, $pairNo);
    }

    private function expireIfNeeded(array $row, DateTimeImmutable $moment): array
    {
        if ($this->bothReady($row) || trim((string)($row['game_id'] ?? '')) !== '') return $row;
        $deadline = $this->parseUtc((string)$row['readiness_deadline_at_utc']);
        if ($moment <= $deadline || (string)$row['launch_state'] === self::STATE_READINESS_EXPIRED) return $row;

        $this->database->execute(
            'UPDATE mgw_tournament_round_matches
             SET launch_state=:launch_state,updated_at_utc=:updated_at_utc
             WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no
               AND game_id IS NULL',
            [
                'launch_state'=>self::STATE_READINESS_EXPIRED,
                'updated_at_utc'=>$this->utc($moment),
                'tournament_id'=>$row['tournament_id'],
                'round_no'=>(int)$row['round_no'],
                'pair_no'=>(int)$row['pair_no'],
            ]
        );
        return $this->pairRow((string)$row['tournament_id'], (int)$row['pair_no']);
    }

    private function publicMatch(array $row, string $viewerMgwId, DateTimeImmutable $moment): array
    {
        $names = [];
        foreach ($this->database->fetchAll(
            'SELECT mgw_id,nickname,display_name FROM mgw_users
             WHERE mgw_id IN (:player_a,:player_b)',
            [
                'player_a'=>$row['player_a_mgw_id'],
                'player_b'=>$row['player_b_mgw_id'],
            ]
        ) as $user) {
            if (!is_array($user)) continue;
            $id = (string)$user['mgw_id'];
            $name = trim((string)($user['nickname'] ?? ''));
            if ($name === '') $name = trim((string)($user['display_name'] ?? ''));
            $names[$id] = $name !== '' ? $name : 'Игрок';
        }

        $a = (string)$row['player_a_mgw_id'];
        $b = (string)$row['player_b_mgw_id'];
        $aReady = trim((string)($row['player_a_ready_at_utc'] ?? '')) !== '';
        $bReady = trim((string)($row['player_b_ready_at_utc'] ?? '')) !== '';
        $deadline = $this->parseUtc((string)$row['readiness_deadline_at_utc']);
        $selfIsA = $viewerMgwId === $a;
        $selfReady = $selfIsA ? $aReady : $bReady;
        $opponentReady = $selfIsA ? $bReady : $aReady;
        $expired = (string)$row['launch_state'] === self::STATE_READINESS_EXPIRED;

        return [
            'round_no'=>(int)$row['round_no'],
            'pair_no'=>(int)$row['pair_no'],
            'readiness_opened_at_utc'=>(string)$row['readiness_opened_at_utc'],
            'readiness_deadline_at_utc'=>(string)$row['readiness_deadline_at_utc'],
            'ready_window_seconds'=>self::READY_WINDOW_SECONDS,
            'seconds_remaining'=>max(0, $deadline->getTimestamp() - $moment->getTimestamp()),
            'launch_state'=>(string)$row['launch_state'],
            'self_ready'=>$selfReady,
            'opponent_ready'=>$opponentReady,
            'both_ready'=>$aReady && $bReady,
            'can_ready'=>!$expired && !$selfReady && $moment <= $deadline,
            'game_id'=>$this->nullableText($row['game_id'] ?? null),
            'players'=>[
                [
                    'mgw_id'=>$a,
                    'nickname'=>$names[$a] ?? 'Игрок',
                    'ready'=>$aReady,
                    'self'=>$viewerMgwId === $a,
                ],
                [
                    'mgw_id'=>$b,
                    'nickname'=>$names[$b] ?? 'Игрок',
                    'ready'=>$bReady,
                    'self'=>$viewerMgwId === $b,
                ],
            ],
        ];
    }

    private function participant(string $mgwId, string $accountRef, string $legacyUserId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT t.*,r.registration_id,r.mgw_id,r.account_ref,o.legacy_user_id
             FROM mgw_tournaments t
             INNER JOIN mgw_tournament_registrations r
               ON r.tournament_id=t.tournament_id
              AND r.registration_state=:registration_state
             INNER JOIN mgw_account_ownership o
               ON o.mgw_id=r.mgw_id
              AND o.account_ref=r.account_ref
              AND o.ownership_status=:ownership_status
             WHERE t.active_slot=:active_slot
               AND r.mgw_id=:mgw_id
               AND r.account_ref=:account_ref
               AND o.legacy_user_id=:legacy_user_id
             LIMIT 2',
            [
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                'ownership_status'=>'active',
                'active_slot'=>TournamentRegistrationService::ACTIVE_SLOT,
                'mgw_id'=>trim($mgwId),
                'account_ref'=>trim($accountRef),
                'legacy_user_id'=>trim($legacyUserId),
            ]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Готовность доступна только зарегистрированным участникам турнира.');
        }
        if ((string)$rows[0]['tournament_state'] !== TournamentRegistrationService::STATE_SCHEDULED) {
            throw new RuntimeException('Турнир ещё не готов к старту матчей.');
        }
        return $rows[0];
    }

    private function pairRow(string $tournamentId, int $pairNo): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id AND round_no=:round_no AND pair_no=:pair_no
             LIMIT 2',
            [
                'tournament_id'=>$tournamentId,
                'round_no'=>self::FIRST_ROUND,
                'pair_no'=>$pairNo,
            ]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament readiness pair is unavailable.');
        }
        return $rows[0];
    }

    private function bothReady(array $row): bool
    {
        return trim((string)($row['player_a_ready_at_utc'] ?? '')) !== ''
            && trim((string)($row['player_b_ready_at_utc'] ?? '')) !== '';
    }

    private function isInitialManualReadyRow(array $row): bool
    {
        return (int)($row['round_no'] ?? 0) === self::FIRST_ROUND
            && max(1, (int)($row['attempt_no'] ?? 1)) === 1
            && (string)($row['wait_kind'] ?? 'initial_ready') === 'initial_ready'
            && trim((string)($row['completed_at_utc'] ?? '')) === '';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function moment(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function parseUtc(string $value): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new RuntimeException('Tournament readiness timestamp is invalid.');
        }
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function forUpdate(DatabaseConnectionInterface $database): string
    {
        return $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
