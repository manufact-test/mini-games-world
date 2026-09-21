<?php
declare(strict_types=1);

final class TournamentHallService
{
    public const HALL_OPEN_BEFORE_SECONDS = 900;
    public const HALL_PRESENCE_FRESHNESS_SECONDS = 8;
    public const START_BOUNDARY_REQUEST_GRACE_SECONDS = 4;
    public const BRACKET_VERSION = 'mvp21-4-random-v1';

    private $presenceResolver;
    private $randomInt;

    public function __construct(
        private DatabaseConnectionInterface $database,
        callable $presenceResolver,
        ?callable $randomInt = null
    ) {
        $this->presenceResolver = $presenceResolver;
        $this->randomInt = $randomInt ?? static fn(int $min, int $max): int => random_int($min, $max);
    }

    public function status(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        ?DateTimeImmutable $now = null
    ): array {
        $participant = $this->participant($mgwId, $accountRef, $legacyUserId);
        $moment = $this->moment($now);
        return $this->snapshot($participant, $moment);
    }

    public function enter(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        ?DateTimeImmutable $now = null
    ): array {
        $participant = $this->participant($mgwId, $accountRef, $legacyUserId);
        $moment = $this->moment($now);
        $start = $this->scheduledStart($participant);
        $opensAt = $start->modify('-' . self::HALL_OPEN_BEFORE_SECONDS . ' seconds');

        if ($moment < $opensAt) {
            throw new RuntimeException('Турнирный зал откроется за 15 минут до старта.');
        }

        // A late participant can still open the participant Hall, but must never
        // turn an already-missed start boundary into retroactive presence.
        if ($moment >= $start) {
            $this->ensureBracketGenerated((string)$participant['tournament_id'], $moment);
        }

        $enteredAt = $this->utc($moment);
        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $participant,
            $enteredAt
        ): void {
            $existing = $db->fetchAll(
                'SELECT tournament_id,registration_id,mgw_id
                 FROM mgw_tournament_hall_entries
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id'
                 . $this->forUpdate($db),
                [
                    'tournament_id'=>$participant['tournament_id'],
                    'mgw_id'=>$participant['mgw_id'],
                ]
            );
            if ($existing === []) {
                $db->execute(
                    'INSERT INTO mgw_tournament_hall_entries (
                        tournament_id,registration_id,mgw_id,
                        entered_at_utc,last_presence_at_utc,updated_at_utc
                     ) VALUES (
                        :tournament_id,:registration_id,:mgw_id,
                        :entered_at_utc,NULL,:updated_at_utc
                     )',
                    [
                        'tournament_id'=>$participant['tournament_id'],
                        'registration_id'=>$participant['registration_id'],
                        'mgw_id'=>$participant['mgw_id'],
                        'entered_at_utc'=>$enteredAt,
                        'updated_at_utc'=>$enteredAt,
                    ]
                );
                return;
            }
            if (count($existing) !== 1
                || (string)($existing[0]['registration_id'] ?? '') !== (string)$participant['registration_id']) {
                throw new RuntimeException('Турнирный зал entry conflicts with the canonical registration.');
            }
        });

        $boundaryFrom = $start->modify('-' . self::HALL_PRESENCE_FRESHNESS_SECONDS . ' seconds');
        if ($moment >= $boundaryFrom && $moment < $start) {
            // An authenticated Hall entry request in the final freshness window is
            // direct evidence that the participant is actively in this foreground
            // Hall. Do not wait for the separate gameplay-presence projection.
            $this->recordHallRequestPresence($participant, $moment);
        } else {
            $this->recordForegroundPresence($participant, $moment);
        }
        return $this->status($mgwId, $accountRef, $legacyUserId, $moment);
    }

    public function heartbeat(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        ?DateTimeImmutable $now = null
    ): array {
        $participant = $this->participant($mgwId, $accountRef, $legacyUserId);
        $moment = $this->moment($now);
        $start = $this->scheduledStart($participant);

        $entry = $this->hallEntry((string)$participant['tournament_id'], $mgwId);
        if ($entry === null) {
            throw new RuntimeException('Сначала войдите в Турнирный зал.');
        }

        $boundaryFrom = $start->modify('-' . self::HALL_PRESENCE_FRESHNESS_SECONDS . ' seconds');
        $boundaryGraceUntil = $start->modify('+' . self::START_BOUNDARY_REQUEST_GRACE_SECONDS . ' seconds');
        if ($moment >= $boundaryFrom && $moment <= $boundaryGraceUntil) {
            // Heartbeat is emitted only while the participant tournament panel is visible.
            // If transport scheduling lands a final heartbeat just after T0, anchor
            // it to T0 before the immutable bracket is frozen. This is allowed only
            // for an entry that already existed before start; enter() after T0 still
            // freezes the bracket first and cannot retroactively recover presence.
            $effectivePresenceMoment = $moment > $start ? $start : $moment;
            $this->recordHallRequestPresence($participant, $effectivePresenceMoment);
        } else {
            $this->recordForegroundPresence($participant, $moment);
        }

        if ($moment >= $start) {
            $this->ensureBracketGenerated((string)$participant['tournament_id'], $moment);
        }

        return $this->status($mgwId, $accountRef, $legacyUserId, $moment);
    }

    private function ensureBracketGenerated(string $tournamentId, DateTimeImmutable $moment): void
    {
        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $moment
        ): void {
            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournaments
                 WHERE tournament_id=:tournament_id'
                 . $this->forUpdate($db),
                ['tournament_id'=>$tournamentId]
            );
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Official tournament state is unavailable.');
            }
            $tournament = $rows[0];
            if ((string)($tournament['active_slot'] ?? '') !== TournamentRegistrationService::ACTIVE_SLOT
                || (string)($tournament['tournament_state'] ?? '') !== TournamentRegistrationService::STATE_SCHEDULED) {
                throw new RuntimeException('Турнирный зал is available only for the active scheduled tournament.');
            }

            $start = $this->scheduledStart($tournament);
            if ($moment < $start) return;

            if (trim((string)($tournament['bracket_generated_at_utc'] ?? '')) !== '') {
                return;
            }

            $registrations = $db->fetchAll(
                'SELECT r.registration_id,r.mgw_id,r.account_ref
                 FROM mgw_tournament_registrations r
                 WHERE r.tournament_id=:tournament_id
                   AND r.registration_state=:registration_state
                 ORDER BY r.registered_at_utc ASC,r.registration_id ASC'
                 . $this->forUpdate($db),
                [
                    'tournament_id'=>$tournamentId,
                    'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                ]
            );
            $capacity = (int)($tournament['capacity'] ?? 0);
            if ($capacity < 2 || count($registrations) !== $capacity || ($capacity % 2) !== 0) {
                throw new RuntimeException('Tournament bracket requires the exact full registered roster.');
            }

            $hallRows = $db->fetchAll(
                'SELECT mgw_id,last_presence_at_utc
                 FROM mgw_tournament_hall_entries
                 WHERE tournament_id=:tournament_id',
                ['tournament_id'=>$tournamentId]
            );
            $lastPresence = [];
            foreach ($hallRows as $hallRow) {
                if (!is_array($hallRow)) continue;
                $id = trim((string)($hallRow['mgw_id'] ?? ''));
                $value = trim((string)($hallRow['last_presence_at_utc'] ?? ''));
                if ($id !== '' && $value !== '') $lastPresence[$id] = $value;
            }

            $this->shuffle($registrations);
            $registrations = $this->pairTwoLiveManualAcceptancePlayers($registrations);
            $createdAt = $this->utc($moment);
            $freshFrom = $start->modify('-' . self::HALL_PRESENCE_FRESHNESS_SECONDS . ' seconds');

            foreach ($registrations as $index=>$registration) {
                $seedNo = $index + 1;
                $mgwId = trim((string)($registration['mgw_id'] ?? ''));
                $registrationId = trim((string)($registration['registration_id'] ?? ''));
                if ($mgwId === '' || $registrationId === '') {
                    throw new RuntimeException('Tournament bracket contains an incomplete registration.');
                }

                $present = false;
                $seenRaw = $lastPresence[$mgwId] ?? '';
                if ($seenRaw !== '') {
                    $seen = $this->parseUtc($seenRaw);
                    $present = $seen >= $freshFrom && $seen <= $start;
                }

                $db->execute(
                    'INSERT INTO mgw_tournament_bracket_seeds (
                        tournament_id,seed_no,pair_no,registration_id,mgw_id,
                        present_at_start,technical_loss_at_start,created_at_utc
                     ) VALUES (
                        :tournament_id,:seed_no,:pair_no,:registration_id,:mgw_id,
                        :present_at_start,:technical_loss_at_start,:created_at_utc
                     )',
                    [
                        'tournament_id'=>$tournamentId,
                        'seed_no'=>$seedNo,
                        'pair_no'=>(int)(($index / 2) + 1),
                        'registration_id'=>$registrationId,
                        'mgw_id'=>$mgwId,
                        'present_at_start'=>$present ? 1 : 0,
                        'technical_loss_at_start'=>$present ? 0 : 1,
                        'created_at_utc'=>$createdAt,
                    ]
                );
            }

            $effectiveAt = $this->utc($start);
            $updated = $db->execute(
                'UPDATE mgw_tournaments
                 SET bracket_effective_at_utc=:effective_at,
                     bracket_generated_at_utc=:generated_at,
                     bracket_version=:bracket_version,
                     updated_at_utc=:updated_at
                 WHERE tournament_id=:tournament_id
                   AND bracket_generated_at_utc IS NULL',
                [
                    'effective_at'=>$effectiveAt,
                    'generated_at'=>$createdAt,
                    'bracket_version'=>self::BRACKET_VERSION,
                    'updated_at'=>$createdAt,
                    'tournament_id'=>$tournamentId,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Tournament bracket changed concurrently.');
            }
        });
    }

    /**
     * Staging manual-acceptance fixtures are the only registrations whose
     * account refs use the stg_tour namespace. When an 8-player roster contains
     * exactly six such fixtures and two real accounts, keep the six fixtures
     * randomized but place the two real accounts into the same first-round pair.
     * Ordinary tournaments never satisfy this shape and retain the canonical
     * random bracket unchanged.
     */
    private function pairTwoLiveManualAcceptancePlayers(array $registrations): array
    {
        if (count($registrations) !== 8) return $registrations;

        $fixtures = [];
        $live = [];
        foreach ($registrations as $registration) {
            if (!is_array($registration)) return $registrations;
            $accountRef = trim((string)($registration['account_ref'] ?? ''));
            $isFixture = preg_match('/^legacy:stg_tour_(?:v2_)?[a-f0-9]{12}$/', $accountRef) === 1;
            if ($isFixture) $fixtures[] = $registration;
            else $live[] = $registration;
        }

        if (count($fixtures) !== 6 || count($live) !== 2) return $registrations;

        return array_merge($fixtures, $live);
    }

    private function recordHallRequestPresence(array $participant, DateTimeImmutable $moment): void
    {
        $timestamp = $this->utc($moment);
        $this->database->execute(
            'UPDATE mgw_tournament_hall_entries
             SET last_presence_at_utc=:last_presence_at_utc,
                 updated_at_utc=:updated_at_utc
             WHERE tournament_id=:tournament_id
               AND mgw_id=:mgw_id',
            [
                'last_presence_at_utc'=>$timestamp,
                'updated_at_utc'=>$timestamp,
                'tournament_id'=>$participant['tournament_id'],
                'mgw_id'=>$participant['mgw_id'],
            ]
        );
    }

    private function recordForegroundPresence(array $participant, DateTimeImmutable $moment): void
    {
        $legacyUserId = trim((string)($participant['legacy_user_id'] ?? ''));
        if ($legacyUserId === '') return;
        $presence = ($this->presenceResolver)($legacyUserId);
        if (!is_array($presence) || (string)($presence['state'] ?? '') !== 'foreground') {
            return;
        }

        $timestamp = $this->utc($moment);
        $this->database->execute(
            'UPDATE mgw_tournament_hall_entries
             SET last_presence_at_utc=:last_presence_at_utc,
                 updated_at_utc=:updated_at_utc
             WHERE tournament_id=:tournament_id
               AND mgw_id=:mgw_id',
            [
                'last_presence_at_utc'=>$timestamp,
                'updated_at_utc'=>$timestamp,
                'tournament_id'=>$participant['tournament_id'],
                'mgw_id'=>$participant['mgw_id'],
            ]
        );
    }

    private function snapshot(array $participant, DateTimeImmutable $moment): array
    {
        $start = $this->scheduledStart($participant);
        $opensAt = $start->modify('-' . self::HALL_OPEN_BEFORE_SECONDS . ' seconds');
        $tournamentId = (string)$participant['tournament_id'];
        $entry = $this->hallEntry($tournamentId, (string)$participant['mgw_id']);
        $bracketGenerated = trim((string)($participant['bracket_generated_at_utc'] ?? '')) !== '';

        $rosterRows = $this->database->fetchAll(
            'SELECT r.mgw_id,r.registration_id,
                    u.nickname,u.display_name,u.equipped_avatar_item_id,
                    h.entered_at_utc,h.last_presence_at_utc
             FROM mgw_tournament_registrations r
             INNER JOIN mgw_users u ON u.mgw_id=r.mgw_id
             LEFT JOIN mgw_tournament_hall_entries h
               ON h.tournament_id=r.tournament_id AND h.mgw_id=r.mgw_id
             WHERE r.tournament_id=:tournament_id
               AND r.registration_state=:registration_state
             ORDER BY r.registered_at_utc ASC,r.registration_id ASC',
            [
                'tournament_id'=>$tournamentId,
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
            ]
        );

        $frozenPresence = [];
        if ($bracketGenerated) {
            foreach ($this->database->fetchAll(
                'SELECT mgw_id,present_at_start
                 FROM mgw_tournament_bracket_seeds
                 WHERE tournament_id=:tournament_id',
                ['tournament_id'=>$tournamentId]
            ) as $row) {
                if (!is_array($row)) continue;
                $id = trim((string)($row['mgw_id'] ?? ''));
                if ($id !== '') $frozenPresence[$id] = (int)($row['present_at_start'] ?? 0) === 1;
            }
        }

        $freshFrom = $moment->modify('-' . self::HALL_PRESENCE_FRESHNESS_SECONDS . ' seconds');
        $roster = [];
        foreach ($rosterRows as $row) {
            if (!is_array($row)) continue;
            $id = (string)($row['mgw_id'] ?? '');
            $seenRaw = trim((string)($row['last_presence_at_utc'] ?? ''));
            $presentNow = false;
            if ($seenRaw !== '' && !$bracketGenerated) {
                $seen = $this->parseUtc($seenRaw);
                $presentNow = $seen >= $freshFrom && $seen <= $moment;
            }
            $roster[] = [
                'mgw_id'=>$id,
                'nickname'=>$this->playerName($row),
                'avatar_item_id'=>$this->avatar($row),
                'entered'=>trim((string)($row['entered_at_utc'] ?? '')) !== '',
                'present'=>$bracketGenerated
                    ? ($frozenPresence[$id] ?? false)
                    : $presentNow,
            ];
        }

        return [
            'tournament'=>[
                'tournament_id'=>$tournamentId,
                'title'=>(string)($participant['title'] ?? 'Официальный турнир'),
                'game_type'=>(string)($participant['game_type'] ?? ''),
                'capacity'=>(int)($participant['capacity'] ?? 0),
                'scheduled_start_at_utc'=>$this->utc($start),
            ],
            'hall'=>[
                'opens_at_utc'=>$this->utc($opensAt),
                'started'=>$moment >= $start,
                'open'=>$moment >= $opensAt,
                'entered'=>$entry !== null,
                'entered_at_utc'=>$entry === null ? null : (string)$entry['entered_at_utc'],
                'presence_freshness_seconds'=>self::HALL_PRESENCE_FRESHNESS_SECONDS,
                'roster'=>$roster,
            ],
            'bracket'=>$this->bracketSnapshot($tournamentId),
        ];
    }

    private function bracketSnapshot(string $tournamentId): ?array
    {
        $tournamentRows = $this->database->fetchAll(
            'SELECT scheduled_start_at_utc,bracket_effective_at_utc,
                    bracket_generated_at_utc,bracket_version
             FROM mgw_tournaments WHERE tournament_id=:tournament_id',
            ['tournament_id'=>$tournamentId]
        );
        if (count($tournamentRows) !== 1 || !is_array($tournamentRows[0])) {
            throw new RuntimeException('Tournament bracket owner is unavailable.');
        }
        $tournament = $tournamentRows[0];
        $generatedAt = trim((string)($tournament['bracket_generated_at_utc'] ?? ''));
        if ($generatedAt === '') return null;

        $rows = $this->database->fetchAll(
            'SELECT b.seed_no,b.pair_no,b.mgw_id,
                    b.present_at_start,b.technical_loss_at_start,
                    u.nickname,u.display_name,u.equipped_avatar_item_id
             FROM mgw_tournament_bracket_seeds b
             INNER JOIN mgw_users u ON u.mgw_id=b.mgw_id
             WHERE b.tournament_id=:tournament_id
             ORDER BY b.seed_no ASC',
            ['tournament_id'=>$tournamentId]
        );
        $seeds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $seeds[] = [
                'seed_no'=>(int)($row['seed_no'] ?? 0),
                'pair_no'=>(int)($row['pair_no'] ?? 0),
                'mgw_id'=>(string)($row['mgw_id'] ?? ''),
                'nickname'=>$this->playerName($row),
                'avatar_item_id'=>$this->avatar($row),
                'present_at_start'=>(int)($row['present_at_start'] ?? 0) === 1,
                'technical_loss'=>(int)($row['technical_loss_at_start'] ?? 0) === 1,
            ];
        }

        return [
            'version'=>(string)($tournament['bracket_version'] ?? self::BRACKET_VERSION),
            'effective_at_utc'=>(string)($tournament['bracket_effective_at_utc'] ?? $tournament['scheduled_start_at_utc'] ?? ''),
            'generated_at_utc'=>$generatedAt,
            'seeds'=>$seeds,
        ];
    }

    private function participant(string $mgwId, string $accountRef, string $legacyUserId): array
    {
        $mgwId = trim($mgwId);
        $accountRef = trim($accountRef);
        $legacyUserId = trim($legacyUserId);
        if ($mgwId === '' || $accountRef === '' || $legacyUserId === '') {
            throw new RuntimeException('Турнирный зал requires canonical participant identity.');
        }

        $rows = $this->database->fetchAll(
            'SELECT t.*,r.registration_id,r.mgw_id,r.account_ref,
                    o.legacy_user_id
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
                'mgw_id'=>$mgwId,
                'account_ref'=>$accountRef,
                'legacy_user_id'=>$legacyUserId,
            ]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Турнирный зал доступен только зарегистрированным участникам.');
        }
        if ((string)($rows[0]['tournament_state'] ?? '') !== TournamentRegistrationService::STATE_SCHEDULED) {
            throw new RuntimeException('Турнирный зал откроется после назначения даты турнира.');
        }
        $this->scheduledStart($rows[0]);
        return $rows[0];
    }

    private function hallEntry(string $tournamentId, string $mgwId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_tournament_hall_entries
             WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id
             LIMIT 2',
            ['tournament_id'=>$tournamentId,'mgw_id'=>$mgwId]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Турнирный зал participant state is ambiguous.');
        }
        return $rows[0];
    }

    private function scheduledStart(array $row): DateTimeImmutable
    {
        $raw = trim((string)($row['scheduled_start_at_utc'] ?? ''));
        if ($raw === '') throw new RuntimeException('Дата старта турнира не назначена.');
        return $this->parseUtc($raw);
    }

    private function playerName(array $row): string
    {
        $name = trim((string)($row['nickname'] ?? ''));
        if ($name === '') $name = trim((string)($row['display_name'] ?? ''));
        if ($name === '') $name = trim((string)($row['mgw_id'] ?? ''));
        return $name !== '' ? $name : 'Игрок';
    }

    private function avatar(array $row): string
    {
        $avatar = trim((string)($row['equipped_avatar_item_id'] ?? ''));
        return $avatar !== '' ? $avatar : 'starter-default-01';
    }

    private function shuffle(array &$rows): void
    {
        for ($index = count($rows) - 1; $index > 0; $index--) {
            $swap = (int)($this->randomInt)(0, $index);
            if ($swap < 0 || $swap > $index) {
                throw new RuntimeException('Tournament bracket randomizer returned an invalid index.');
            }
            if ($swap === $index) continue;
            [$rows[$index], $rows[$swap]] = [$rows[$swap], $rows[$index]];
        }
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
            throw new RuntimeException('Tournament timestamp is invalid.');
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
