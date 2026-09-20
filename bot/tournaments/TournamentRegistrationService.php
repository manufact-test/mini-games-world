<?php
declare(strict_types=1);

final class TournamentRegistrationService
{
    public const ACTIVE_SLOT = 'official';
    public const ENTRY_ASSET = 'mgw_coin';
    public const ENTRY_FEE = 50000;
    public const STATE_DRAFT = 'draft';
    public const STATE_REGISTRATION_OPEN = 'registration_open';
    public const REGISTRATION_REGISTERED = 'registered';
    public const REGISTRATION_WITHDRAWN = 'withdrawn';
    public const ALLOWED_CAPACITIES = [8, 16, 32, 64, 128];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger
    ) {}

    public function createDraft(
        string $gameType,
        int $capacity,
        string $title,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $gameType = $this->token($gameType, 32, 'game type');
        if (!in_array($capacity, self::ALLOWED_CAPACITIES, true)) {
            throw new InvalidArgumentException('Tournament capacity must be 8, 16, 32, 64 or 128.');
        }
        $title = $this->text($title, 160);
        if ($title === '') $title = 'Официальный турнир';
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $createdAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $gameType,
            $capacity,
            $title,
            $actorRef,
            $createdAt
        ): array {
            $existing = $db->fetchAll(
                'SELECT tournament_id FROM mgw_tournaments
                 WHERE active_slot=:active_slot' . $this->forUpdate($db),
                ['active_slot'=>self::ACTIVE_SLOT]
            );
            if ($existing !== []) {
                throw new RuntimeException('Одновременно может существовать только один официальный турнир.');
            }

            $tournamentId = $this->newTournamentId($actorRef, $createdAt);
            $snapshot = self::canonicalRewardSnapshot();

            $db->execute(
                'INSERT INTO mgw_tournaments (
                    tournament_id,active_slot,title,game_type,capacity,
                    entry_fee_amount,entry_asset_code,reward_snapshot_json,
                    tournament_state,created_by_ref,opened_by_ref,
                    created_at_utc,registration_opened_at_utc,updated_at_utc
                 ) VALUES (
                    :tournament_id,:active_slot,:title,:game_type,:capacity,
                    :entry_fee_amount,:entry_asset_code,:reward_snapshot_json,
                    :tournament_state,:created_by_ref,NULL,
                    :created_at_utc,NULL,:updated_at_utc
                 )',
                [
                    'tournament_id'=>$tournamentId,
                    'active_slot'=>self::ACTIVE_SLOT,
                    'title'=>$title,
                    'game_type'=>$gameType,
                    'capacity'=>$capacity,
                    'entry_fee_amount'=>self::ENTRY_FEE,
                    'entry_asset_code'=>self::ENTRY_ASSET,
                    'reward_snapshot_json'=>$this->encodeJson($snapshot),
                    'tournament_state'=>self::STATE_DRAFT,
                    'created_by_ref'=>$actorRef,
                    'created_at_utc'=>$createdAt,
                    'updated_at_utc'=>$createdAt,
                ]
            );

            return $this->snapshotForRow($db, $this->tournamentRow($db, $tournamentId, false), null, null);
        });
    }

    public function openRegistration(
        string $tournamentId,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $tournamentId = $this->requiredText($tournamentId, 64, 'tournament id');
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $openedAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,
            $actorRef,
            $openedAt
        ): array {
            $row = $this->tournamentRow($db, $tournamentId, true);
            if ((string)$row['active_slot'] !== self::ACTIVE_SLOT) {
                throw new RuntimeException('Tournament is not the active official tournament.');
            }
            if ((string)$row['tournament_state'] === self::STATE_REGISTRATION_OPEN) {
                return $this->snapshotForRow($db, $row, null, null);
            }
            if ((string)$row['tournament_state'] !== self::STATE_DRAFT) {
                throw new RuntimeException('Tournament registration cannot be opened from the current state.');
            }

            $updated = $db->execute(
                'UPDATE mgw_tournaments
                 SET tournament_state=:state,
                     opened_by_ref=:opened_by_ref,
                     registration_opened_at_utc=:opened_at,
                     updated_at_utc=:updated_at
                 WHERE tournament_id=:tournament_id
                   AND tournament_state=:expected_state',
                [
                    'state'=>self::STATE_REGISTRATION_OPEN,
                    'opened_by_ref'=>$actorRef,
                    'opened_at'=>$openedAt,
                    'updated_at'=>$openedAt,
                    'tournament_id'=>$tournamentId,
                    'expected_state'=>self::STATE_DRAFT,
                ]
            );
            if ($updated !== 1) throw new RuntimeException('Tournament registration state changed concurrently.');

            return $this->snapshotForRow($db, $this->tournamentRow($db, $tournamentId, false), null, null);
        });
    }

    public function snapshot(?string $mgwId = null, ?string $accountRef = null): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_tournaments
             WHERE active_slot=:active_slot
             LIMIT 1',
            ['active_slot'=>self::ACTIVE_SLOT]
        );
        if ($rows === []) {
            return [
                'tournament'=>null,
                'registration'=>null,
                'balance'=>$accountRef === null ? null : $this->publicBalance($accountRef),
            ];
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Official tournament state is invalid.');
        }
        return $this->snapshotForRow($this->database, $rows[0], $mgwId, $accountRef);
    }

    public function register(
        string $mgwId,
        string $accountRef,
        ?DateTimeImmutable $now = null
    ): array {
        $mgwId = $this->requiredText($mgwId, 24, 'MGW-ID');
        $accountRef = $this->requiredText($accountRef, 255, 'account ref');
        $registeredAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $mgwId,
            $accountRef,
            $registeredAt
        ): array {
            $tournament = $this->activeTournamentRow($db, true);
            if ((string)$tournament['tournament_state'] !== self::STATE_REGISTRATION_OPEN) {
                throw new RuntimeException('Регистрация на официальный турнир сейчас закрыта.');
            }

            $registrationRows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id' . $this->forUpdate($db),
                ['tournament_id'=>$tournament['tournament_id'],'mgw_id'=>$mgwId]
            );
            $existing = $registrationRows[0] ?? null;
            if (is_array($existing) && (string)$existing['registration_state'] === self::REGISTRATION_REGISTERED) {
                return $this->snapshotForRow($db, $tournament, $mgwId, $accountRef);
            }

            $registeredCount = $this->registeredCount($db, (string)$tournament['tournament_id']);
            $capacity = (int)$tournament['capacity'];
            if ($registeredCount >= $capacity) {
                throw new RuntimeException('Все места в турнире уже заняты.');
            }

            $attempt = is_array($existing) ? ((int)$existing['attempt_no'] + 1) : 1;
            $registrationId = $this->registrationId((string)$tournament['tournament_id'], $mgwId, $attempt);
            $operationKey = $this->operationKey('register', (string)$tournament['tournament_id'], $mgwId, $attempt);
            $identity = $this->ledgerIdentity($accountRef, $mgwId);

            $reservation = $this->ledger->createReservation([
                'operation_key'=>$operationKey,
                'account_ref'=>$identity['account_ref'],
                'mgw_id'=>$identity['mgw_id'],
                'legacy_user_id'=>$identity['legacy_user_id'],
                'asset_code'=>(string)$tournament['entry_asset_code'],
                'amount'=>(int)$tournament['entry_fee_amount'],
                'source_type'=>'official_tournament',
                'source_ref'=>(string)$tournament['tournament_id'],
                'metadata'=>[
                    'tournament_id'=>(string)$tournament['tournament_id'],
                    'registration_id'=>$registrationId,
                    'attempt_no'=>$attempt,
                    'purpose'=>'registration_entry_reservation',
                ],
            ]);

            if (is_array($existing)) {
                $updated = $db->execute(
                    'UPDATE mgw_tournament_registrations
                     SET registration_id=:registration_id,
                         account_ref=:account_ref,
                         attempt_no=:attempt_no,
                         registration_state=:registration_state,
                         reservation_id=:reservation_id,
                         registered_at_utc=:registered_at_utc,
                         withdrawn_at_utc=NULL,
                         updated_at_utc=:updated_at_utc
                     WHERE tournament_id=:tournament_id
                       AND mgw_id=:mgw_id
                       AND registration_state=:expected_state',
                    [
                        'registration_id'=>$registrationId,
                        'account_ref'=>$accountRef,
                        'attempt_no'=>$attempt,
                        'registration_state'=>self::REGISTRATION_REGISTERED,
                        'reservation_id'=>$reservation['reservation_id'],
                        'registered_at_utc'=>$registeredAt,
                        'updated_at_utc'=>$registeredAt,
                        'tournament_id'=>$tournament['tournament_id'],
                        'mgw_id'=>$mgwId,
                        'expected_state'=>self::REGISTRATION_WITHDRAWN,
                    ]
                );
                if ($updated !== 1) throw new RuntimeException('Tournament registration changed concurrently.');
            } else {
                $db->execute(
                    'INSERT INTO mgw_tournament_registrations (
                        registration_id,tournament_id,mgw_id,account_ref,attempt_no,
                        registration_state,reservation_id,registered_at_utc,
                        withdrawn_at_utc,updated_at_utc
                     ) VALUES (
                        :registration_id,:tournament_id,:mgw_id,:account_ref,:attempt_no,
                        :registration_state,:reservation_id,:registered_at_utc,
                        NULL,:updated_at_utc
                     )',
                    [
                        'registration_id'=>$registrationId,
                        'tournament_id'=>$tournament['tournament_id'],
                        'mgw_id'=>$mgwId,
                        'account_ref'=>$accountRef,
                        'attempt_no'=>$attempt,
                        'registration_state'=>self::REGISTRATION_REGISTERED,
                        'reservation_id'=>$reservation['reservation_id'],
                        'registered_at_utc'=>$registeredAt,
                        'updated_at_utc'=>$registeredAt,
                    ]
                );
            }

            return $this->snapshotForRow($db, $tournament, $mgwId, $accountRef);
        });
    }

    public function leave(
        string $mgwId,
        string $accountRef,
        ?DateTimeImmutable $now = null
    ): array {
        $mgwId = $this->requiredText($mgwId, 24, 'MGW-ID');
        $accountRef = $this->requiredText($accountRef, 255, 'account ref');
        $withdrawnAt = $this->utc($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $mgwId,
            $accountRef,
            $withdrawnAt
        ): array {
            $tournament = $this->activeTournamentRow($db, true);
            if ((string)$tournament['tournament_state'] !== self::STATE_REGISTRATION_OPEN) {
                throw new RuntimeException('Из текущего состояния турнира выйти через регистрацию нельзя.');
            }

            $rows = $db->fetchAll(
                'SELECT * FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id' . $this->forUpdate($db),
                ['tournament_id'=>$tournament['tournament_id'],'mgw_id'=>$mgwId]
            );
            if ($rows === [] || !is_array($rows[0])
                || (string)$rows[0]['registration_state'] !== self::REGISTRATION_REGISTERED) {
                throw new RuntimeException('Активная регистрация не найдена.');
            }
            $registration = $rows[0];

            if ($this->registeredCount($db, (string)$tournament['tournament_id']) >= (int)$tournament['capacity']) {
                throw new RuntimeException('После заполнения турнира регистрация зафиксирована и выйти через этот этап уже нельзя.');
            }

            $attempt = (int)$registration['attempt_no'];
            $this->ledger->releaseReservation([
                'operation_key'=>$this->operationKey('leave', (string)$tournament['tournament_id'], $mgwId, $attempt),
                'reservation_id'=>(string)$registration['reservation_id'],
                'metadata'=>[
                    'tournament_id'=>(string)$tournament['tournament_id'],
                    'registration_id'=>(string)$registration['registration_id'],
                    'attempt_no'=>$attempt,
                    'reason'=>'participant_left_before_full',
                ],
            ]);

            $updated = $db->execute(
                'UPDATE mgw_tournament_registrations
                 SET registration_state=:state,
                     withdrawn_at_utc=:withdrawn_at,
                     updated_at_utc=:updated_at
                 WHERE tournament_id=:tournament_id
                   AND mgw_id=:mgw_id
                   AND registration_state=:expected_state',
                [
                    'state'=>self::REGISTRATION_WITHDRAWN,
                    'withdrawn_at'=>$withdrawnAt,
                    'updated_at'=>$withdrawnAt,
                    'tournament_id'=>$tournament['tournament_id'],
                    'mgw_id'=>$mgwId,
                    'expected_state'=>self::REGISTRATION_REGISTERED,
                ]
            );
            if ($updated !== 1) throw new RuntimeException('Tournament registration changed concurrently.');

            return $this->snapshotForRow($db, $tournament, $mgwId, $accountRef);
        });
    }

    public static function canonicalRewardSnapshot(): array
    {
        return [
            'version'=>'mvp21-tournament-rewards-v1',
            'entry'=>[
                'asset_code'=>self::ENTRY_ASSET,
                'amount'=>self::ENTRY_FEE,
                'registration_semantics'=>'reserved_not_spent',
            ],
            'placements'=>[
                '1'=>[
                    'total'=>200000,
                    'entry_return'=>50000,
                    'prize'=>150000,
                    'golden_ticket'=>true,
                    'champion_crown_days'=>30,
                    'permanent_winner_badge'=>true,
                    'exclusive_champion_cosmetics'=>true,
                    'hall_of_fame'=>true,
                    'permanent_cup'=>'gold',
                ],
                '2'=>[
                    'total'=>80000,
                    'entry_return'=>50000,
                    'prize'=>30000,
                    'silver_frame_days'=>30,
                    'permanent_finalist_result'=>true,
                    'permanent_cup'=>'silver',
                ],
                '3'=>[
                    'total'=>50000,
                    'entry_return'=>50000,
                    'prize'=>0,
                    'bronze_mark_days'=>30,
                    'permanent_third_place_result'=>true,
                    'permanent_cup'=>'bronze',
                ],
                'other'=>[
                    'entry_return'=>0,
                    'refund_only_on_cancellation_or_emergency'=>true,
                ],
            ],
            'golden_ticket'=>[
                'sellable'=>false,
                'transferable'=>false,
                'valid_until_big_tournament'=>true,
                'big_tournament_fixed_capacity'=>false,
                'big_tournament_fixed_date'=>false,
                'repeat_championship_increments'=>'championship_count',
            ],
        ];
    }

    private function snapshotForRow(
        DatabaseConnectionInterface $db,
        array $row,
        ?string $mgwId,
        ?string $accountRef
    ): array {
        $registeredCount = $this->registeredCount($db, (string)$row['tournament_id']);
        $registration = null;
        if ($mgwId !== null && trim($mgwId) !== '') {
            $rows = $db->fetchAll(
                'SELECT registration_id,tournament_id,mgw_id,account_ref,attempt_no,
                        registration_state,reservation_id,registered_at_utc,
                        withdrawn_at_utc,updated_at_utc
                 FROM mgw_tournament_registrations
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id',
                ['tournament_id'=>$row['tournament_id'],'mgw_id'=>$mgwId]
            );
            if ($rows !== [] && is_array($rows[0])) $registration = $this->publicRegistration($rows[0]);
        }

        return [
            'tournament'=>$this->publicTournament($row, $registeredCount),
            'registration'=>$registration,
            'balance'=>$accountRef === null ? null : $this->publicBalance($accountRef),
        ];
    }

    private function publicTournament(array $row, int $registeredCount): array
    {
        $capacity = (int)$row['capacity'];
        return [
            'tournament_id'=>(string)$row['tournament_id'],
            'title'=>(string)$row['title'],
            'game_type'=>(string)$row['game_type'],
            'capacity'=>$capacity,
            'registered_count'=>$registeredCount,
            'remaining_count'=>max(0, $capacity - $registeredCount),
            'is_full'=>$registeredCount >= $capacity,
            'entry_fee'=>[
                'asset_code'=>(string)$row['entry_asset_code'],
                'amount'=>(int)$row['entry_fee_amount'],
            ],
            'reward_snapshot'=>$this->decodeJson((string)$row['reward_snapshot_json']),
            'state'=>(string)$row['tournament_state'],
            'created_at_utc'=>(string)$row['created_at_utc'],
            'registration_opened_at_utc'=>$this->nullableText($row['registration_opened_at_utc'] ?? null, 32),
            'updated_at_utc'=>(string)$row['updated_at_utc'],
        ];
    }

    private function publicRegistration(array $row): array
    {
        return [
            'registration_id'=>(string)$row['registration_id'],
            'state'=>(string)$row['registration_state'],
            'attempt_no'=>(int)$row['attempt_no'],
            'reservation_id'=>(string)$row['reservation_id'],
            'registered_at_utc'=>(string)$row['registered_at_utc'],
            'withdrawn_at_utc'=>$this->nullableText($row['withdrawn_at_utc'] ?? null, 32),
            'updated_at_utc'=>(string)$row['updated_at_utc'],
        ];
    }

    private function publicBalance(string $accountRef): array
    {
        $balance = $this->ledger->getBalance($accountRef, self::ENTRY_ASSET);
        if ($balance === null) {
            return [
                'asset_code'=>self::ENTRY_ASSET,
                'available_amount'=>0,
                'reserved_amount'=>0,
            ];
        }
        return [
            'asset_code'=>(string)$balance['asset_code'],
            'available_amount'=>(int)$balance['available_amount'],
            'reserved_amount'=>(int)$balance['reserved_amount'],
        ];
    }

    private function activeTournamentRow(DatabaseConnectionInterface $db, bool $lock): array
    {
        $rows = $db->fetchAll(
            'SELECT * FROM mgw_tournaments
             WHERE active_slot=:active_slot' . ($lock ? $this->forUpdate($db) : ''),
            ['active_slot'=>self::ACTIVE_SLOT]
        );
        if ($rows === []) throw new RuntimeException('Официальный турнир пока не создан.');
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Official tournament state is invalid.');
        }
        return $rows[0];
    }

    private function tournamentRow(DatabaseConnectionInterface $db, string $tournamentId, bool $lock): array
    {
        $rows = $db->fetchAll(
            'SELECT * FROM mgw_tournaments
             WHERE tournament_id=:tournament_id' . ($lock ? $this->forUpdate($db) : ''),
            ['tournament_id'=>$tournamentId]
        );
        if ($rows === []) throw new RuntimeException('Tournament was not found.');
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament state is invalid.');
        }
        return $rows[0];
    }

    private function registeredCount(DatabaseConnectionInterface $db, string $tournamentId): int
    {
        return (int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_registrations
             WHERE tournament_id=:tournament_id AND registration_state=:state',
            ['tournament_id'=>$tournamentId,'state'=>self::REGISTRATION_REGISTERED]
        );
    }

    private function ledgerIdentity(string $accountRef, string $mgwId): array
    {
        if (str_starts_with($accountRef, 'legacy:')) {
            return [
                'account_ref'=>$accountRef,
                'mgw_id'=>$mgwId,
                'legacy_user_id'=>substr($accountRef, strlen('legacy:')),
            ];
        }
        if (str_starts_with($accountRef, 'mgw:')) {
            return [
                'account_ref'=>$accountRef,
                'mgw_id'=>$mgwId,
                'legacy_user_id'=>null,
            ];
        }
        throw new InvalidArgumentException('Canonical tournament account_ref is required.');
    }

    private function operationKey(string $action, string $tournamentId, string $mgwId, int $attempt): string
    {
        return 'tournament:' . $tournamentId . ':' . $action . ':' . $mgwId . ':' . $attempt;
    }

    private function registrationId(string $tournamentId, string $mgwId, int $attempt): string
    {
        return 'treg_' . substr(hash('sha256', $tournamentId . '|' . $mgwId . '|' . $attempt), 0, 48);
    }

    private function newTournamentId(string $actorRef, string $createdAt): string
    {
        return 'tour_' . substr(hash('sha256', $createdAt . '|' . $actorRef . '|' . bin2hex(random_bytes(16))), 0, 40);
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function utc(?DateTimeImmutable $now): string
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function forUpdate(DatabaseConnectionInterface $db): string
    {
        return $db->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private function token(string $value, int $max, string $label): string
    {
        $value = strtolower($this->text($value, $max));
        if ($value === '' || preg_match('/^[a-z][a-z0-9_]{0,' . ($max - 1) . '}$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid tournament ' . $label . '.');
        }
        return $value;
    }

    private function requiredText(string $value, int $max, string $label): string
    {
        $value = $this->text($value, $max);
        if ($value === '') throw new InvalidArgumentException('Tournament ' . $label . ' is required.');
        return $value;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = $this->text((string)($value ?? ''), $max);
        return $value === '' ? null : $value;
    }

    private function text(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
