<?php
declare(strict_types=1);

/**
 * MVP-21.9 terminal tournament settlement owner.
 *
 * The bracket remains the placement authority. LedgerWriteService remains the
 * money owner. This service only turns a completed final + third-place match
 * into immutable result/reward records and deterministic ledger operations.
 */
final class TournamentSettlementService
{
    public const RESULT_CHAMPION = 'champion';
    public const RESULT_RUNNER_UP = 'runner_up';
    public const RESULT_THIRD = 'third_place';
    public const RESULT_FOURTH = 'fourth_place';
    public const RESULT_PARTICIPANT = 'participant';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger
    ) {}

    public function settleIfComplete(string $tournamentId, ?DateTimeImmutable $now = null): array
    {
        $tournamentId = $this->required($tournamentId, 64, 'tournament id');
        $tournament = $this->tournament($tournamentId);
        $terminal = $this->terminalBracket($tournamentId);
        if ($terminal === null) {
            return [
                'tournament_id'=>$tournamentId,
                'status'=>'pending',
                'settlement_complete'=>false,
                'runtime_balances'=>[],
            ];
        }

        $snapshot = $this->decodeRewardSnapshot((string)$tournament['reward_snapshot_json']);
        $version = $this->required((string)($snapshot['version'] ?? ''), 64, 'reward snapshot version');
        $snapshotHash = hash('sha256', LedgerIntegrity::canonicalJson($snapshot));
        $entryAmount = (int)$tournament['entry_fee_amount'];
        if ($entryAmount <= 0 || (string)$tournament['entry_asset_code'] !== TournamentRegistrationService::ENTRY_ASSET) {
            throw new RuntimeException('Tournament entry snapshot is invalid for settlement.');
        }

        $rows = $this->database->fetchAll(
            'SELECT r.registration_id,r.mgw_id,r.account_ref,r.reservation_id,
                    res.legacy_user_id,res.status AS reservation_status,res.amount AS reservation_amount
             FROM mgw_tournament_registrations r
             INNER JOIN mgw_reservations res ON res.reservation_id=r.reservation_id
             WHERE r.tournament_id=:tournament_id
               AND r.registration_state=:registration_state
             ORDER BY r.registered_at_utc ASC,r.registration_id ASC',
            [
                'tournament_id'=>$tournamentId,
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
            ]
        );
        if (count($rows) !== (int)$tournament['capacity']) {
            throw new RuntimeException('Completed tournament registration set is incomplete.');
        }

        $settledAt = (string)$terminal['completed_at_utc'];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $mgwId = (string)$row['mgw_id'];
            $placement = $terminal['placements'][$mgwId] ?? null;
            $reward = $this->rewardForPlacement($snapshot, $placement);
            $rewardEligible = !$this->isSyntheticStagingFixture(
                $mgwId,
                (string)$row['account_ref'],
                (string)($row['legacy_user_id'] ?? '')
            );
            $entryReturn = $rewardEligible ? (int)($reward['entry_return'] ?? 0) : 0;
            $prize = $rewardEligible ? (int)($reward['prize'] ?? 0) : 0;
            $payout = $rewardEligible
                ? (isset($reward['total']) ? (int)$reward['total'] : ($entryReturn + $prize))
                : 0;
            if ($payout !== $entryReturn + $prize || $entryReturn < 0 || $prize < 0) {
                throw new RuntimeException('Tournament reward snapshot payout is inconsistent.');
            }
            if ((int)$row['reservation_amount'] !== $entryAmount) {
                throw new RuntimeException('Tournament reservation amount differs from entry snapshot.');
            }

            $identity = [
                'account_ref'=>(string)$row['account_ref'],
                'mgw_id'=>$mgwId,
                'legacy_user_id'=>$this->nullable((string)($row['legacy_user_id'] ?? '')),
            ];
            $metadata = [
                'tournament_id'=>$tournamentId,
                'mgw_id'=>$mgwId,
                'placement'=>$placement,
                'reward_snapshot_version'=>$version,
                'reward_snapshot_sha256'=>$snapshotHash,
                'reward_eligible'=>$rewardEligible,
            ];

            $this->ledger->consumeReservation([
                'operation_key'=>$this->operationKey($tournamentId, $mgwId, 'entry'),
                'reservation_id'=>(string)$row['reservation_id'],
                'metadata'=>$metadata + ['purpose'=>'tournament_entry_settlement'],
                'occurred_at_utc'=>$settledAt,
            ]);

            if ($payout > 0) {
                $this->ledger->postAvailableDelta($identity + [
                    'operation_key'=>$this->operationKey($tournamentId, $mgwId, 'payout'),
                    'asset_code'=>(string)$tournament['entry_asset_code'],
                    'available_delta'=>$payout,
                    'category'=>'tournament_reward',
                    'source_type'=>'official_tournament',
                    'source_ref'=>$tournamentId,
                    'metadata'=>$metadata + [
                        'purpose'=>'tournament_placement_payout',
                        'entry_return'=>$entryReturn,
                        'prize'=>$prize,
                        'payout'=>$payout,
                    ],
                    'occurred_at_utc'=>$settledAt,
                ]);
            }

            $this->persistSettledResult(
                $tournamentId,
                $row,
                $placement,
                $version,
                $snapshotHash,
                $entryAmount,
                $entryReturn,
                $prize,
                $payout,
                $rewardEligible,
                $reward,
                $settledAt
            );
        }

        $settledCount = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:tournament_id',
            ['tournament_id'=>$tournamentId]
        );
        if ($settledCount !== count($rows)) {
            throw new RuntimeException('Tournament settlement did not produce one durable result per participant.');
        }

        return [
            'tournament_id'=>$tournamentId,
            'status'=>'settled',
            'settlement_complete'=>true,
            'settled_count'=>$settledCount,
            'reward_snapshot_version'=>$version,
            'runtime_balances'=>$this->runtimeBalances($tournamentId),
        ];
    }

    public function terminalSnapshotForParticipant(string $tournamentId, string $mgwId): array
    {
        $tournamentId = $this->required($tournamentId, 64, 'tournament id');
        $mgwId = $this->required($mgwId, 24, 'MGW-ID');

        $expected = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_registrations
             WHERE tournament_id=:tournament_id AND registration_state=:registration_state',
            [
                'tournament_id'=>$tournamentId,
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
            ]
        );
        $settled = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:tournament_id',
            ['tournament_id'=>$tournamentId]
        );

        $rows = $this->database->fetchAll(
            'SELECT tr.*,u.nickname,u.display_name
             FROM mgw_tournament_results tr
             INNER JOIN mgw_users u ON u.mgw_id=tr.mgw_id
             WHERE tr.tournament_id=:tournament_id
             ORDER BY CASE WHEN tr.placement IS NULL THEN 999 ELSE tr.placement END ASC,tr.mgw_id ASC',
            ['tournament_id'=>$tournamentId]
        );
        $podium = [];
        $self = null;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $public = $this->publicResult($row, $row['mgw_id'] === $mgwId);
            if (($public['placement'] ?? null) !== null && (int)$public['placement'] <= 3) {
                $podium[] = $public;
            }
            if ((string)$row['mgw_id'] === $mgwId) {
                $self = $public;
                $self['entitlements'] = $this->entitlements($tournamentId, $mgwId);
                $balance = $this->ledger->getBalance((string)$row['account_ref'], TournamentRegistrationService::ENTRY_ASSET);
                $self['balance'] = is_array($balance) ? [
                    'available_amount'=>(int)$balance['available_amount'],
                    'reserved_amount'=>(int)$balance['reserved_amount'],
                ] : null;
                $ticket = $this->goldenTicket($mgwId);
                if ($ticket !== null) $self['golden_ticket'] = $ticket;
            }
        }

        $completedAt = null;
        foreach ($rows as $row) {
            $candidate = trim((string)($row['settled_at_utc'] ?? ''));
            if ($candidate !== '' && ($completedAt === null || strcmp($candidate, $completedAt) > 0)) $completedAt = $candidate;
        }

        return [
            'tournament_id'=>$tournamentId,
            'settlement_state'=>$expected > 0 && $settled === $expected ? 'settled' : 'pending',
            'settlement_complete'=>$expected > 0 && $settled === $expected,
            'settled_count'=>$settled,
            'participant_count'=>$expected,
            'settled_at_utc'=>$completedAt,
            'podium'=>$podium,
            'self_result'=>$self,
        ];
    }

    private function persistSettledResult(
        string $tournamentId,
        array $registration,
        ?int $placement,
        string $version,
        string $snapshotHash,
        int $entryAmount,
        int $entryReturn,
        int $prize,
        int $payout,
        bool $rewardEligible,
        array $reward,
        string $settledAt
    ): void {
        $mgwId = (string)$registration['mgw_id'];
        $resultCode = $this->resultCode($placement);

        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $tournamentId,$registration,$mgwId,$placement,$version,$snapshotHash,
            $entryAmount,$entryReturn,$prize,$payout,$rewardEligible,$reward,$settledAt,$resultCode
        ): void {
            $existing = $db->fetchAll(
                'SELECT * FROM mgw_tournament_results
                 WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id' . $this->forUpdate($db),
                ['tournament_id'=>$tournamentId,'mgw_id'=>$mgwId]
            );
            if ($existing !== []) {
                $row = $existing[0];
                if ($this->nullableInt($row['placement'] ?? null) !== $placement
                    || (string)$row['reward_snapshot_sha256'] !== $snapshotHash
                    || (int)$row['payout_amount'] !== $payout
                    || (int)($row['reward_eligible'] ?? 1) !== ($rewardEligible ? 1 : 0)
                    || (string)$row['reservation_id'] !== (string)$registration['reservation_id']) {
                    throw new RuntimeException('Existing tournament result conflicts with canonical settlement.');
                }
            } else {
                $db->execute(
                    'INSERT INTO mgw_tournament_results (
                        tournament_id,mgw_id,registration_id,account_ref,placement,result_code,
                        reward_snapshot_version,reward_snapshot_sha256,
                        entry_amount,entry_return_amount,prize_amount,payout_amount,reward_eligible,
                        reservation_id,settled_at_utc,created_at_utc,updated_at_utc
                     ) VALUES (
                        :tournament_id,:mgw_id,:registration_id,:account_ref,:placement,:result_code,
                        :reward_snapshot_version,:reward_snapshot_sha256,
                        :entry_amount,:entry_return_amount,:prize_amount,:payout_amount,:reward_eligible,
                        :reservation_id,:settled_at_utc,:created_at_utc,:updated_at_utc
                     )',
                    [
                        'tournament_id'=>$tournamentId,
                        'mgw_id'=>$mgwId,
                        'registration_id'=>(string)$registration['registration_id'],
                        'account_ref'=>(string)$registration['account_ref'],
                        'placement'=>$placement,
                        'result_code'=>$resultCode,
                        'reward_snapshot_version'=>$version,
                        'reward_snapshot_sha256'=>$snapshotHash,
                        'entry_amount'=>$entryAmount,
                        'entry_return_amount'=>$entryReturn,
                        'prize_amount'=>$prize,
                        'payout_amount'=>$payout,
                        'reward_eligible'=>$rewardEligible ? 1 : 0,
                        'reservation_id'=>(string)$registration['reservation_id'],
                        'settled_at_utc'=>$settledAt,
                        'created_at_utc'=>$settledAt,
                        'updated_at_utc'=>$settledAt,
                    ]
                );
            }

            foreach ($rewardEligible ? $this->rewardEntitlements($reward, $placement, $settledAt) : [] as $entitlement) {
                $sql = $db->driver() === 'sqlite'
                    ? 'INSERT OR IGNORE INTO mgw_tournament_reward_entitlements (
                        tournament_id,mgw_id,reward_code,reward_kind,valid_from_at_utc,valid_until_at_utc,
                        metadata_json,granted_at_utc,created_at_utc
                       ) VALUES (
                        :tournament_id,:mgw_id,:reward_code,:reward_kind,:valid_from_at_utc,:valid_until_at_utc,
                        :metadata_json,:granted_at_utc,:created_at_utc
                       )'
                    : 'INSERT IGNORE INTO mgw_tournament_reward_entitlements (
                        tournament_id,mgw_id,reward_code,reward_kind,valid_from_at_utc,valid_until_at_utc,
                        metadata_json,granted_at_utc,created_at_utc
                       ) VALUES (
                        :tournament_id,:mgw_id,:reward_code,:reward_kind,:valid_from_at_utc,:valid_until_at_utc,
                        :metadata_json,:granted_at_utc,:created_at_utc
                       )';
                $db->execute($sql, [
                    'tournament_id'=>$tournamentId,
                    'mgw_id'=>$mgwId,
                    'reward_code'=>$entitlement['reward_code'],
                    'reward_kind'=>$entitlement['reward_kind'],
                    'valid_from_at_utc'=>$entitlement['valid_from_at_utc'],
                    'valid_until_at_utc'=>$entitlement['valid_until_at_utc'],
                    'metadata_json'=>json_encode($entitlement['metadata'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                    'granted_at_utc'=>$settledAt,
                    'created_at_utc'=>$settledAt,
                ]);
            }

            if ($rewardEligible && $placement === 1 && !empty($reward['golden_ticket'])) {
                $championshipCount = (int)$db->fetchValue(
                    'SELECT COUNT(*) FROM mgw_tournament_results WHERE mgw_id=:mgw_id AND placement=1',
                    ['mgw_id'=>$mgwId]
                );
                $ticketRows = $db->fetchAll(
                    'SELECT * FROM mgw_tournament_golden_tickets WHERE mgw_id=:mgw_id' . $this->forUpdate($db),
                    ['mgw_id'=>$mgwId]
                );
                if ($ticketRows === []) {
                    $db->execute(
                        'INSERT INTO mgw_tournament_golden_tickets (
                            mgw_id,ticket_state,championship_count,first_tournament_id,last_tournament_id,
                            first_awarded_at_utc,last_awarded_at_utc,updated_at_utc
                         ) VALUES (
                            :mgw_id,:ticket_state,:championship_count,:first_tournament_id,:last_tournament_id,
                            :first_awarded_at_utc,:last_awarded_at_utc,:updated_at_utc
                         )',
                        [
                            'mgw_id'=>$mgwId,
                            'ticket_state'=>'active',
                            'championship_count'=>$championshipCount,
                            'first_tournament_id'=>$tournamentId,
                            'last_tournament_id'=>$tournamentId,
                            'first_awarded_at_utc'=>$settledAt,
                            'last_awarded_at_utc'=>$settledAt,
                            'updated_at_utc'=>$settledAt,
                        ]
                    );
                } else {
                    $db->execute(
                        'UPDATE mgw_tournament_golden_tickets
                         SET ticket_state=:ticket_state,
                             championship_count=:championship_count,
                             last_tournament_id=:last_tournament_id,
                             last_awarded_at_utc=:last_awarded_at_utc,
                             updated_at_utc=:updated_at_utc
                         WHERE mgw_id=:mgw_id',
                        [
                            'ticket_state'=>'active',
                            'championship_count'=>$championshipCount,
                            'last_tournament_id'=>$tournamentId,
                            'last_awarded_at_utc'=>$settledAt,
                            'updated_at_utc'=>$settledAt,
                            'mgw_id'=>$mgwId,
                        ]
                    );
                }
            }
        });
    }

    private function terminalBracket(string $tournamentId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT match_kind,winner_mgw_id,loser_mgw_id,completed_at_utc
             FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id AND match_kind IN (:final_kind,:third_kind)
             ORDER BY round_no DESC,pair_no ASC',
            [
                'tournament_id'=>$tournamentId,
                'final_kind'=>TournamentRoundProgressionService::MATCH_FINAL,
                'third_kind'=>TournamentRoundProgressionService::MATCH_THIRD_PLACE,
            ]
        );
        $final = null;
        $third = null;
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string)($row['completed_at_utc'] ?? '')) === '') continue;
            if ((string)$row['match_kind'] === TournamentRoundProgressionService::MATCH_FINAL) $final = $row;
            if ((string)$row['match_kind'] === TournamentRoundProgressionService::MATCH_THIRD_PLACE) $third = $row;
        }
        if (!is_array($final) || !is_array($third)) return null;

        $placements = [];
        foreach ([
            [(string)($final['winner_mgw_id'] ?? ''),1],
            [(string)($final['loser_mgw_id'] ?? ''),2],
            [(string)($third['winner_mgw_id'] ?? ''),3],
            [(string)($third['loser_mgw_id'] ?? ''),4],
        ] as [$candidate,$place]) {
            $candidate = trim($candidate);
            if ($candidate === '') continue;
            if (isset($placements[$candidate])) {
                throw new RuntimeException('Terminal tournament placements are not unique.');
            }
            $placements[$this->required($candidate,24,'terminal placement player')] = $place;
        }
        $completedAt = strcmp((string)$final['completed_at_utc'], (string)$third['completed_at_utc']) >= 0
            ? (string)$final['completed_at_utc']
            : (string)$third['completed_at_utc'];
        return ['placements'=>$placements,'completed_at_utc'=>$completedAt];
    }

    private function rewardForPlacement(array $snapshot, ?int $placement): array
    {
        $placements = is_array($snapshot['placements'] ?? null) ? $snapshot['placements'] : [];
        $key = $placement !== null && $placement <= 3 ? (string)$placement : 'other';
        $reward = $placements[$key] ?? null;
        if (!is_array($reward)) throw new RuntimeException('Tournament reward snapshot is missing placement data.');
        return $reward;
    }

    private function rewardEntitlements(array $reward, ?int $placement, string $settledAt): array
    {
        if ($placement === null || $placement > 3) return [];
        $items = [];
        $permanent = function (string $code, array $metadata = []) use (&$items, $settledAt): void {
            $items[] = [
                'reward_code'=>$code,
                'reward_kind'=>'permanent_achievement',
                'valid_from_at_utc'=>$settledAt,
                'valid_until_at_utc'=>null,
                'metadata'=>$metadata,
            ];
        };
        $temporary = function (string $code, int $days) use (&$items, $settledAt): void {
            $start = new DateTimeImmutable($settledAt, new DateTimeZone('UTC'));
            $items[] = [
                'reward_code'=>$code,
                'reward_kind'=>'temporary_style',
                'valid_from_at_utc'=>$start->format('Y-m-d H:i:s.u'),
                'valid_until_at_utc'=>$start->modify('+' . $days . ' days')->format('Y-m-d H:i:s.u'),
                'metadata'=>['duration_days'=>$days,'auto_equip'=>false],
            ];
        };

        if ($placement === 1) {
            if (!empty($reward['golden_ticket'])) $permanent('golden_ticket', ['transferable'=>false,'sellable'=>false]);
            if ((int)($reward['champion_crown_days'] ?? 0) > 0) $temporary('champion_crown', (int)$reward['champion_crown_days']);
            if (!empty($reward['permanent_winner_badge'])) $permanent('winner_badge');
            if (!empty($reward['exclusive_champion_cosmetics'])) $permanent('champion_cosmetics');
            if (!empty($reward['hall_of_fame'])) $permanent('hall_of_fame');
        } elseif ($placement === 2) {
            if ((int)($reward['silver_frame_days'] ?? 0) > 0) $temporary('silver_frame', (int)$reward['silver_frame_days']);
            if (!empty($reward['permanent_finalist_result'])) $permanent('finalist_result');
        } elseif ($placement === 3) {
            if ((int)($reward['bronze_mark_days'] ?? 0) > 0) $temporary('bronze_mark', (int)$reward['bronze_mark_days']);
            if (!empty($reward['permanent_third_place_result'])) $permanent('third_place_result');
        }

        $cup = trim((string)($reward['permanent_cup'] ?? ''));
        if ($cup !== '') $permanent('cup_' . $cup, ['cup_tier'=>$cup]);
        return $items;
    }

    private function publicResult(array $row, bool $self): array
    {
        $nickname = trim((string)($row['nickname'] ?? ''));
        if ($nickname === '') $nickname = trim((string)($row['display_name'] ?? ''));
        if ($nickname === '') $nickname = 'Игрок';
        return [
            'placement'=>$this->nullableInt($row['placement'] ?? null),
            'result_code'=>(string)$row['result_code'],
            'nickname'=>$nickname,
            'self'=>$self,
            'entry_amount'=>(int)$row['entry_amount'],
            'entry_return_amount'=>(int)$row['entry_return_amount'],
            'prize_amount'=>(int)$row['prize_amount'],
            'payout_amount'=>(int)$row['payout_amount'],
            'reward_eligible'=>(int)($row['reward_eligible'] ?? 1) === 1,
            'reward_snapshot_version'=>(string)$row['reward_snapshot_version'],
            'settled_at_utc'=>(string)$row['settled_at_utc'],
        ];
    }

    private function entitlements(string $tournamentId, string $mgwId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT reward_code,reward_kind,valid_from_at_utc,valid_until_at_utc,metadata_json,granted_at_utc
             FROM mgw_tournament_reward_entitlements
             WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id
             ORDER BY reward_kind ASC,reward_code ASC',
            ['tournament_id'=>$tournamentId,'mgw_id'=>$mgwId]
        );
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $result[] = [
                'reward_code'=>(string)$row['reward_code'],
                'reward_kind'=>(string)$row['reward_kind'],
                'valid_from_at_utc'=>$this->nullable((string)($row['valid_from_at_utc'] ?? '')),
                'valid_until_at_utc'=>$this->nullable((string)($row['valid_until_at_utc'] ?? '')),
                'granted_at_utc'=>(string)$row['granted_at_utc'],
            ];
        }
        return $result;
    }

    private function goldenTicket(string $mgwId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT ticket_state,championship_count,first_tournament_id,last_tournament_id,
                    first_awarded_at_utc,last_awarded_at_utc
             FROM mgw_tournament_golden_tickets WHERE mgw_id=:mgw_id',
            ['mgw_id'=>$mgwId]
        );
        if ($rows === [] || !is_array($rows[0])) return null;
        return [
            'state'=>(string)$rows[0]['ticket_state'],
            'championship_count'=>(int)$rows[0]['championship_count'],
            'first_awarded_at_utc'=>(string)$rows[0]['first_awarded_at_utc'],
            'last_awarded_at_utc'=>(string)$rows[0]['last_awarded_at_utc'],
        ];
    }

    private function runtimeBalances(string $tournamentId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT res.legacy_user_id,b.available_amount,b.reserved_amount
             FROM mgw_tournament_results tr
             INNER JOIN mgw_reservations res ON res.reservation_id=tr.reservation_id
             INNER JOIN mgw_balances b ON b.account_ref=tr.account_ref AND b.asset_code=:asset_code
             WHERE tr.tournament_id=:tournament_id',
            ['asset_code'=>TournamentRegistrationService::ENTRY_ASSET,'tournament_id'=>$tournamentId]
        );
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $legacy = trim((string)($row['legacy_user_id'] ?? ''));
            if ($legacy === '') continue;
            $result[$legacy] = [
                'available_amount'=>(int)$row['available_amount'],
                'reserved_amount'=>(int)$row['reserved_amount'],
            ];
        }
        return $result;
    }

    private function isSyntheticStagingFixture(
        string $mgwId,
        string $accountRef,
        string $legacyUserId
    ): bool {
        $legacyUserId = trim($legacyUserId);
        if ($legacyUserId === '') return false;

        $rows = $this->database->fetchAll(
            'SELECT source_type,source_ref
             FROM mgw_account_ownership
             WHERE account_ref=:account_ref
               AND mgw_id=:mgw_id
               AND legacy_user_id=:legacy_user_id
               AND ownership_status=:ownership_status
             LIMIT 2',
            [
                'account_ref'=>$accountRef,
                'mgw_id'=>$mgwId,
                'legacy_user_id'=>$legacyUserId,
                'ownership_status'=>'active',
            ]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) return false;

        $sourceType = (string)($rows[0]['source_type'] ?? '');
        $sourceRef = (string)($rows[0]['source_ref'] ?? '');
        $legacyFixture = preg_match('/^MGW-STG-[a-f0-9]{12}$/', $mgwId) === 1
            && preg_match('/^legacy:stg_tour_[a-f0-9]{12}$/', $accountRef) === 1
            && preg_match('/^stg_tour_[a-f0-9]{12}$/', $legacyUserId) === 1
            && $sourceType === 'staging_fixture_repair';

        $v2Fixture = preg_match('/^MGW-[0-9A-HJKMNP-TV-Z]{16}$/', strtoupper($mgwId)) === 1
            && preg_match('/^legacy:stg_tour_v2_[a-f0-9]{12}$/', $accountRef) === 1
            && preg_match('/^stg_tour_v2_[a-f0-9]{12}$/', $legacyUserId) === 1
            && $sourceType === 'runtime_identity'
            && $sourceRef === 'development:' . $legacyUserId;

        return $legacyFixture || $v2Fixture;
    }

    private function tournament(string $tournamentId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT tournament_id,capacity,entry_fee_amount,entry_asset_code,reward_snapshot_json
             FROM mgw_tournaments WHERE tournament_id=:tournament_id LIMIT 2',
            ['tournament_id'=>$tournamentId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Tournament settlement owner is unavailable.');
        }
        return $rows[0];
    }

    private function decodeRewardSnapshot(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Tournament reward snapshot is invalid.');
        }
        if (!is_array($decoded)) throw new RuntimeException('Tournament reward snapshot is invalid.');
        return $decoded;
    }

    private function operationKey(string $tournamentId, string $mgwId, string $suffix): string
    {
        return 'tournament:settle:' . substr(hash('sha256', $tournamentId . '|' . $mgwId), 0, 32) . ':' . $suffix;
    }

    private function resultCode(?int $placement): string
    {
        return match ($placement) {
            1 => self::RESULT_CHAMPION,
            2 => self::RESULT_RUNNER_UP,
            3 => self::RESULT_THIRD,
            4 => self::RESULT_FOURTH,
            default => self::RESULT_PARTICIPANT,
        };
    }

    private function required(string $value, int $max, string $field): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) throw new InvalidArgumentException('Invalid ' . $field . '.');
        return $value;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private function forUpdate(DatabaseConnectionInterface $database): string
    {
        return $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
