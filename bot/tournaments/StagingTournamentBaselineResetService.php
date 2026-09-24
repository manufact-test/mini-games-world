<?php
declare(strict_types=1);

/**
 * Staging-only post-acceptance cleanup for MVP-21.
 *
 * Product behavior stays installed. Test tournament projections are neutralized so
 * staging again looks like no official tournament has ever been played.
 *
 * Important:
 * - append-only ledger rows are never deleted or rewritten;
 * - historical tournament/result rows are kept as audit evidence;
 * - financial test effects are reversed through compensating ledger entries;
 * - public reward projections are disabled/removed.
 */
final class StagingTournamentBaselineResetService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const CLEANUP_SOURCE_REF = 'mvp21_staging_baseline_reset_2026_09_24';
    private const CLEANUP_CATEGORY = 'staging_test_cleanup';
    private const CONFIRMATION = 'RESET_MVP21_TEST_TOURNAMENT_STATE';

    private $runtimeWriter;

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger,
        ?callable $runtimeWriter = null
    ) {
        $this->runtimeWriter = $runtimeWriter;
    }

    public function preview(array $server): array
    {
        $this->assertStaging($server);
        $state = $this->stateSnapshot();

        $blockers = [];
        foreach ($state['compensation_accounts'] as $account) {
            if ((int)$account['compensation_delta'] < 0
                && (int)$account['available_amount'] + (int)$account['compensation_delta'] < 0) {
                $blockers[] = [
                    'reason'=>'insufficient_balance_for_test_prize_clawback',
                    'account_ref_sha256'=>substr(hash('sha256', (string)$account['account_ref']), 0, 16),
                    'required_clawback'=>abs((int)$account['compensation_delta']),
                    'available_amount'=>(int)$account['available_amount'],
                ];
            }
        }

        $fingerprintPayload = [
            'tournament_ids'=>$state['tournament_ids'],
            'active_tournament_ids'=>$state['active_tournament_ids'],
            'registered_registration_ids'=>$state['registered_registration_ids'],
            'active_reservation_ids'=>$state['active_reservation_ids'],
            'eligible_result_keys'=>$state['eligible_result_keys'],
            'entitlement_keys'=>$state['entitlement_keys'],
            'golden_ticket_mgw_ids'=>$state['golden_ticket_mgw_ids'],
            'official_ledger_net'=>$state['official_ledger_net_fingerprint'],
        ];
        $fingerprint = hash('sha256', LedgerIntegrity::canonicalJson($fingerprintPayload));

        $clean = $state['active_tournament_count'] === 0
            && $state['registered_registration_count'] === 0
            && $state['active_reservation_count'] === 0
            && $state['eligible_result_count'] === 0
            && $state['entitlement_count'] === 0
            && $state['golden_ticket_count'] === 0
            && $state['visible_tournament_notification_count'] === 0
            && $state['combined_unreversed_account_count'] === 0;

        return [
            'ok'=>true,
            'environment'=>'staging',
            'clean'=>$clean,
            'can_apply'=>$blockers === [],
            'fingerprint'=>$fingerprint,
            'counts'=>[
                'tournaments'=>$state['tournament_count'],
                'active_tournaments'=>$state['active_tournament_count'],
                'registered_registrations'=>$state['registered_registration_count'],
                'active_reservations'=>$state['active_reservation_count'],
                'eligible_results'=>$state['eligible_result_count'],
                'reward_entitlements'=>$state['entitlement_count'],
                'golden_tickets'=>$state['golden_ticket_count'],
                'visible_tournament_notifications'=>$state['visible_tournament_notification_count'],
                'compensation_accounts'=>count($state['compensation_accounts']),
                'unreversed_accounts'=>$state['combined_unreversed_account_count'],
                'synthetic_fixture_users'=>$state['synthetic_fixture_user_count'],
            ],
            'compensation'=>[
                'positive_total'=>$state['compensation_positive_total'],
                'negative_total'=>$state['compensation_negative_total'],
            ],
            'blockers'=>$blockers,
        ];
    }

    public function apply(
        array $server,
        string $expectedFingerprint,
        string $confirmation,
        string $actorRef = 'github-actions:mvp21-baseline-reset'
    ): array {
        $this->assertStaging($server);
        if (!hash_equals(self::CONFIRMATION, trim($confirmation))) {
            throw new RuntimeException('Explicit MVP-21 staging baseline reset confirmation is required.');
        }
        $actorRef = trim($actorRef);
        if ($actorRef === '' || strlen($actorRef) > 191) {
            throw new RuntimeException('Invalid staging baseline reset actor.');
        }

        $preview = $this->preview($server);
        if (($preview['clean'] ?? false) === true) {
            return ['status'=>'already_clean','preview'=>$preview,'final'=>$preview];
        }
        if (($preview['can_apply'] ?? false) !== true) {
            throw new RuntimeException('MVP-21 staging baseline reset preview has blockers.');
        }
        if ($expectedFingerprint === ''
            || !hash_equals((string)$preview['fingerprint'], $expectedFingerprint)) {
            throw new RuntimeException('MVP-21 staging baseline state changed after preview.');
        }

        $resetAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.u');

        // First, release any still-active official tournament reservation through
        // the canonical ledger owner. This also restores the reserved entry.
        $activeReservations = $this->database->fetchAll(
            "SELECT reservation_id
             FROM mgw_reservations
             WHERE source_type='official_tournament' AND status='active'
             ORDER BY reservation_id ASC"
        );
        $releasedReservations = 0;
        foreach ($activeReservations as $row) {
            $reservationId = trim((string)($row['reservation_id'] ?? ''));
            if ($reservationId === '') continue;
            $this->ledger->releaseReservation([
                'operation_key'=>'staging:mvp21-baseline:release:' . substr(hash('sha256', $reservationId), 0, 48),
                'reservation_id'=>$reservationId,
                'metadata'=>[
                    'purpose'=>'mvp21_post_acceptance_baseline_reset',
                    'actor_ref'=>$actorRef,
                ],
            ]);
            $releasedReservations++;
        }

        // Recompute after releases. The aggregate of ALL official-tournament
        // ledger deltas is exactly the financial effect the tests left behind.
        $financial = $this->financialSnapshot();
        $financialBlockers = [];
        foreach ($financial as $account) {
            if ((int)$account['net_reserved_delta'] !== 0) {
                $financialBlockers[] = 'reserved_delta_not_zero:' . substr(hash('sha256', (string)$account['account_ref']), 0, 16);
                continue;
            }
            $delta = -(int)$account['net_available_delta'];
            if ($delta < 0 && (int)$account['available_amount'] + $delta < 0) {
                $financialBlockers[] = 'insufficient_clawback_balance:' . substr(hash('sha256', (string)$account['account_ref']), 0, 16);
            }
        }
        if ($financialBlockers !== []) {
            throw new RuntimeException('MVP-21 staging financial reset refused: ' . implode(',', $financialBlockers));
        }

        $compensatedAccounts = 0;
        $runtimeBalances = [];
        foreach ($financial as $account) {
            $accountRef = (string)$account['account_ref'];
            $assetCode = (string)$account['asset_code'];
            $delta = -(int)$account['net_available_delta'];

            if ($delta !== 0) {
                $result = $this->ledger->postAvailableDelta([
                    'operation_key'=>'staging:mvp21-baseline:compensate:'
                        . substr(hash('sha256', $accountRef . '|' . $assetCode), 0, 48),
                    'account_ref'=>$accountRef,
                    'mgw_id'=>$account['mgw_id'] !== null ? (string)$account['mgw_id'] : null,
                    'legacy_user_id'=>$account['legacy_user_id'] !== null ? (string)$account['legacy_user_id'] : null,
                    'asset_code'=>$assetCode,
                    'available_delta'=>$delta,
                    'category'=>self::CLEANUP_CATEGORY,
                    'source_type'=>'staging_test',
                    'source_ref'=>self::CLEANUP_SOURCE_REF,
                    'metadata'=>[
                        'purpose'=>'neutralize_mvp21_test_tournament_financial_effect',
                        'official_tournament_net'=>(int)$account['net_available_delta'],
                    ],
                ]);
                $balance = is_array($result['balance'] ?? null)
                    ? $result['balance']
                    : $this->ledger->getBalance($accountRef, $assetCode);
                $compensatedAccounts++;
            } else {
                $balance = $this->ledger->getBalance($accountRef, $assetCode);
            }

            $legacyUserId = trim((string)($account['legacy_user_id'] ?? ''));
            if ($legacyUserId !== '' && is_array($balance)) {
                $runtimeBalances[$legacyUserId] = (int)($balance['available_amount'] ?? 0);
            }
        }

        $fixtureLegacyIds = $this->syntheticFixtureLegacyIds();

        // Keep tournament rows as staging audit evidence, but remove every
        // product-facing projection that says a real official tournament happened.
        $projectionResult = $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $resetAt,
            $fixtureLegacyIds
        ): array {
            $eligibleDisabled = $db->execute(
                'UPDATE mgw_tournament_results SET reward_eligible=0,updated_at_utc=:updated_at
                 WHERE reward_eligible<>0',
                ['updated_at'=>$resetAt]
            );
            $entitlementsDeleted = $db->execute('DELETE FROM mgw_tournament_reward_entitlements');
            $goldenTicketsDeleted = $db->execute('DELETE FROM mgw_tournament_golden_tickets');

            $registrationsWithdrawn = $db->execute(
                'UPDATE mgw_tournament_registrations
                 SET registration_state=:withdrawn,
                     withdrawn_at_utc=COALESCE(withdrawn_at_utc,:withdrawn_at),
                     updated_at_utc=:updated_at
                 WHERE registration_state=:registered',
                [
                    'withdrawn'=>TournamentRegistrationService::REGISTRATION_WITHDRAWN,
                    'withdrawn_at'=>$resetAt,
                    'updated_at'=>$resetAt,
                    'registered'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                ]
            );

            $activeSlotsReleased = $db->execute(
                'UPDATE mgw_tournaments
                 SET active_slot=NULL,
                     tournament_state=CASE
                         WHEN tournament_state IN (:draft,:open,:waiting,:scheduled) THEN :reset_state
                         ELSE tournament_state
                     END,
                     registration_closed_at_utc=CASE
                         WHEN registration_closed_at_utc IS NULL THEN :closed_at
                         ELSE registration_closed_at_utc
                     END,
                     registration_closed_reason=CASE
                         WHEN tournament_state IN (:draft2,:open2,:waiting2,:scheduled2) THEN :closed_reason
                         ELSE registration_closed_reason
                     END,
                     updated_at_utc=:updated_at
                 WHERE active_slot IS NOT NULL',
                [
                    'draft'=>TournamentRegistrationService::STATE_DRAFT,
                    'open'=>TournamentRegistrationService::STATE_REGISTRATION_OPEN,
                    'waiting'=>TournamentRegistrationService::STATE_WAITING_FOR_DATE,
                    'scheduled'=>TournamentRegistrationService::STATE_SCHEDULED,
                    'reset_state'=>'staging_reset',
                    'closed_at'=>$resetAt,
                    'draft2'=>TournamentRegistrationService::STATE_DRAFT,
                    'open2'=>TournamentRegistrationService::STATE_REGISTRATION_OPEN,
                    'waiting2'=>TournamentRegistrationService::STATE_WAITING_FOR_DATE,
                    'scheduled2'=>TournamentRegistrationService::STATE_SCHEDULED,
                    'closed_reason'=>'staging_baseline_reset',
                    'updated_at'=>$resetAt,
                ]
            );

            $fixturesRetired = 0;
            foreach ($fixtureLegacyIds as $legacyUserId) {
                $fixturesRetired += $db->execute(
                    'UPDATE mgw_users u
                     INNER JOIN mgw_account_ownership o ON o.mgw_id=u.mgw_id
                     SET u.status=:retired,u.updated_at_utc=:updated_at
                     WHERE o.legacy_user_id=:legacy_user_id AND u.status=:active',
                    [
                        'retired'=>'staging_fixture_retired',
                        'updated_at'=>$resetAt,
                        'legacy_user_id'=>$legacyUserId,
                        'active'=>'active',
                    ]
                );
            }

            return [
                'eligible_results_disabled'=>$eligibleDisabled,
                'reward_entitlements_deleted'=>$entitlementsDeleted,
                'golden_tickets_deleted'=>$goldenTicketsDeleted,
                'registrations_withdrawn'=>$registrationsWithdrawn,
                'active_slots_released'=>$activeSlotsReleased,
                'fixture_users_retired'=>$fixturesRetired,
            ];
        });

        $runtime = $this->resetRuntimeState(
            $runtimeBalances,
            $fixtureLegacyIds,
            $resetAt
        );

        $final = $this->preview($server);
        if (($final['clean'] ?? false) !== true) {
            throw new RuntimeException(
                'MVP-21 staging baseline reset finished with residual product state: '
                . json_encode($final['counts'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            );
        }

        return [
            'status'=>'reset',
            'released_reservations'=>$releasedReservations,
            'compensated_accounts'=>$compensatedAccounts,
            'projection_cleanup'=>$projectionResult,
            'runtime_cleanup'=>$runtime,
            'final'=>$final,
        ];
    }

    private function stateSnapshot(): array
    {
        $tournamentRows = $this->database->fetchAll(
            'SELECT tournament_id,active_slot FROM mgw_tournaments ORDER BY tournament_id ASC'
        );
        $tournamentIds = [];
        $activeTournamentIds = [];
        foreach ($tournamentRows as $row) {
            $id = trim((string)($row['tournament_id'] ?? ''));
            if ($id === '') continue;
            $tournamentIds[] = $id;
            if (trim((string)($row['active_slot'] ?? '')) !== '') $activeTournamentIds[] = $id;
        }

        $registeredRows = $this->database->fetchAll(
            'SELECT registration_id FROM mgw_tournament_registrations
             WHERE registration_state=:state ORDER BY registration_id ASC',
            ['state'=>TournamentRegistrationService::REGISTRATION_REGISTERED]
        );
        $registeredIds = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['registration_id'] ?? '')),
            $registeredRows
        )));

        $reservationRows = $this->database->fetchAll(
            "SELECT reservation_id FROM mgw_reservations
             WHERE source_type='official_tournament' AND status='active'
             ORDER BY reservation_id ASC"
        );
        $activeReservationIds = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['reservation_id'] ?? '')),
            $reservationRows
        )));

        $eligibleRows = $this->database->fetchAll(
            'SELECT tournament_id,mgw_id FROM mgw_tournament_results
             WHERE reward_eligible<>0 ORDER BY tournament_id ASC,mgw_id ASC'
        );
        $eligibleKeys = array_map(
            static fn(array $row): string => (string)$row['tournament_id'] . '|' . (string)$row['mgw_id'],
            $eligibleRows
        );

        $entitlementRows = $this->database->fetchAll(
            'SELECT tournament_id,mgw_id,reward_code FROM mgw_tournament_reward_entitlements
             ORDER BY tournament_id ASC,mgw_id ASC,reward_code ASC'
        );
        $entitlementKeys = array_map(
            static fn(array $row): string => (string)$row['tournament_id'] . '|' . (string)$row['mgw_id'] . '|' . (string)$row['reward_code'],
            $entitlementRows
        );

        $ticketRows = $this->database->fetchAll(
            'SELECT mgw_id FROM mgw_tournament_golden_tickets ORDER BY mgw_id ASC'
        );
        $ticketMgwIds = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['mgw_id'] ?? '')),
            $ticketRows
        )));

        $financial = $this->financialSnapshot();
        $positive = 0;
        $negative = 0;
        $fingerprintFinancial = [];
        foreach ($financial as $account) {
            $delta = -(int)$account['net_available_delta'];
            if ($delta > 0) $positive += $delta;
            if ($delta < 0) $negative += abs($delta);
            $fingerprintFinancial[] = [
                'account_ref'=>(string)$account['account_ref'],
                'asset_code'=>(string)$account['asset_code'],
                'net_available_delta'=>(int)$account['net_available_delta'],
                'net_reserved_delta'=>(int)$account['net_reserved_delta'],
                'active_reservation_amount'=>(int)$account['active_reservation_amount'],
            ];
        }

        return [
            'tournament_count'=>count($tournamentIds),
            'tournament_ids'=>$tournamentIds,
            'active_tournament_count'=>count($activeTournamentIds),
            'active_tournament_ids'=>$activeTournamentIds,
            'registered_registration_count'=>count($registeredIds),
            'registered_registration_ids'=>$registeredIds,
            'active_reservation_count'=>count($activeReservationIds),
            'active_reservation_ids'=>$activeReservationIds,
            'eligible_result_count'=>count($eligibleKeys),
            'eligible_result_keys'=>$eligibleKeys,
            'entitlement_count'=>count($entitlementKeys),
            'entitlement_keys'=>$entitlementKeys,
            'golden_ticket_count'=>count($ticketMgwIds),
            'golden_ticket_mgw_ids'=>$ticketMgwIds,
            'compensation_accounts'=>$financial,
            'compensation_positive_total'=>$positive,
            'compensation_negative_total'=>$negative,
            'official_ledger_net_fingerprint'=>$fingerprintFinancial,
            'combined_unreversed_account_count'=>$this->combinedUnreversedAccountCount(),
            'synthetic_fixture_user_count'=>count($this->syntheticFixtureLegacyIds()),
            'visible_tournament_notification_count'=>$this->visibleTournamentNotificationCount(),
        ];
    }

    private function financialSnapshot(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT le.account_ref,
                    MAX(le.mgw_id) AS mgw_id,
                    MAX(le.legacy_user_id) AS legacy_user_id,
                    le.asset_code,
                    SUM(le.available_delta) AS net_available_delta,
                    SUM(le.reserved_delta) AS net_reserved_delta
             FROM mgw_ledger_entries le
             WHERE le.source_type='official_tournament'
             GROUP BY le.account_ref,le.asset_code
             ORDER BY le.account_ref ASC,le.asset_code ASC"
        );

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $assetCode = trim((string)($row['asset_code'] ?? ''));
            if ($accountRef === '' || $assetCode === '') continue;
            $balance = $this->ledger->getBalance($accountRef, $assetCode);
            if (!is_array($balance)) {
                throw new RuntimeException('Tournament baseline reset cannot resolve a touched balance.');
            }
            $activeReservationAmount = (int)($this->database->fetchValue(
                "SELECT COALESCE(SUM(amount),0) FROM mgw_reservations
                 WHERE account_ref=:account_ref AND asset_code=:asset_code
                   AND source_type='official_tournament' AND status='active'",
                ['account_ref'=>$accountRef,'asset_code'=>$assetCode]
            ) ?? 0);
            $result[] = [
                'account_ref'=>$accountRef,
                'mgw_id'=>$row['mgw_id'] !== null ? (string)$row['mgw_id'] : null,
                'legacy_user_id'=>$row['legacy_user_id'] !== null ? (string)$row['legacy_user_id'] : null,
                'asset_code'=>$assetCode,
                'net_available_delta'=>(int)($row['net_available_delta'] ?? 0),
                'net_reserved_delta'=>(int)($row['net_reserved_delta'] ?? 0),
                'active_reservation_amount'=>$activeReservationAmount,
                'available_amount'=>(int)($balance['available_amount'] ?? 0),
                'reserved_amount'=>(int)($balance['reserved_amount'] ?? 0),
                // Preview anticipates the canonical release of still-active entry reservations.
                'compensation_delta'=>-((int)($row['net_available_delta'] ?? 0) + $activeReservationAmount),
            ];
        }
        return $result;
    }

    private function combinedUnreversedAccountCount(): int
    {
        $rows = $this->database->fetchAll(
            "SELECT account_ref,asset_code,
                    SUM(available_delta) AS net_available,
                    SUM(reserved_delta) AS net_reserved
             FROM mgw_ledger_entries
             WHERE source_type='official_tournament'
                OR (source_type='staging_test' AND source_ref=:cleanup_ref AND category=:cleanup_category)
             GROUP BY account_ref,asset_code
             HAVING SUM(available_delta)<>0 OR SUM(reserved_delta)<>0",
            [
                'cleanup_ref'=>self::CLEANUP_SOURCE_REF,
                'cleanup_category'=>self::CLEANUP_CATEGORY,
            ]
        );
        return count($rows);
    }

    private function syntheticFixtureLegacyIds(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT DISTINCT legacy_user_id
             FROM mgw_account_ownership
             WHERE legacy_user_id LIKE 'stg_tour_%'
             ORDER BY legacy_user_id ASC"
        );
        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['legacy_user_id'] ?? ''));
            if ($id !== '' && preg_match('/^stg_tour_(?:v2_)?[a-f0-9]{12}$/', $id) === 1) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function resetRuntimeState(
        array $runtimeBalances,
        array $fixtureLegacyIds,
        string $resetAt
    ): array {
        if ($this->runtimeWriter !== null) {
            $result = ($this->runtimeWriter)($runtimeBalances, $fixtureLegacyIds, $resetAt);
            return is_array($result) ? $result : [];
        }

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        $result = $storage->transaction(function (array &$data) use (
            $runtimeBalances,
            $fixtureLegacyIds,
            $resetAt
        ): array {
            $updatedBalances = 0;
            if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
            foreach ($runtimeBalances as $legacyUserId=>$availableAmount) {
                if (!isset($data['users'][$legacyUserId]) || !is_array($data['users'][$legacyUserId])) continue;
                $data['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] = (int)$availableAmount;
                $updatedBalances++;
            }

            $removedFixtures = 0;
            foreach ($fixtureLegacyIds as $legacyUserId) {
                if (!isset($data['users'][$legacyUserId])) continue;
                unset($data['users'][$legacyUserId]);
                $removedFixtures++;
            }

            $hiddenNotifications = 0;
            if (isset($data['notifications']) && is_array($data['notifications'])) {
                foreach ($data['notifications'] as &$notification) {
                    if (!is_array($notification) || !$this->isTournamentNotification($notification)) continue;
                    if (empty($notification['read_at'])) $notification['read_at'] = $resetAt;
                    if (empty($notification['hidden_at'])) {
                        $notification['hidden_at'] = $resetAt;
                        $hiddenNotifications++;
                    }
                }
                unset($notification);
            }

            return [
                'updated_balances'=>$updatedBalances,
                'removed_fixture_users'=>$removedFixtures,
                'hidden_json_notifications'=>$hiddenNotifications,
                'snapshot'=>$data,
            ];
        });

        $snapshot = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : [];
        unset($result['snapshot']);

        $hiddenDb = 0;
        $notificationRows = $this->database->fetchAll(
            'SELECT notification_id,payload_json,read_at_utc,hidden_at_utc FROM mgw_notifications'
        );
        foreach ($notificationRows as $row) {
            if (!is_array($row)) continue;
            try {
                $payload = json_decode((string)($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (!is_array($payload) || !$this->isTournamentNotification($payload)) continue;
            $hiddenDb += $this->database->execute(
                'UPDATE mgw_notifications
                 SET read_at_utc=COALESCE(read_at_utc,:read_at),
                     hidden_at_utc=COALESCE(hidden_at_utc,:hidden_at)
                 WHERE notification_id=:notification_id',
                [
                    'read_at'=>$resetAt,
                    'hidden_at'=>$resetAt,
                    'notification_id'=>(string)$row['notification_id'],
                ]
            );
        }
        $result['hidden_db_notifications']=$hiddenDb;

        // The DB mirror is mutable only for read/hidden state. Re-sync all
        // remaining runtime users so JSON and DB stay byte-for-byte equivalent.
        $router = new RuntimeStorageRouter($this->config);
        if ($router->enabled()
            && $router->routeFor('accounts') === RuntimeStorageRouter::DRIVER_DATABASE
            && $router->routeFor('notifications') === RuntimeStorageRouter::DRIVER_DATABASE) {
            $repository = new RuntimeNotificationRepository($this->config, $router, $this->database);
            $synced = 0;
            foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key=>$user) {
                if (!is_array($user)) continue;
                $legacyUserId = trim((string)($user['id'] ?? $key));
                if ($legacyUserId === '' || preg_match('/^stg_tour_(?:v2_)?[a-f0-9]{12}$/', $legacyUserId) === 1) continue;
                try {
                    $repository->synchronizeAndList($snapshot, $legacyUserId);
                    $synced++;
                } catch (Throwable $error) {
                    // Existing unrelated parity debt must not make a tournament
                    // baseline reset destructive. Product state is already hidden
                    // consistently in both stores above; surface the warning.
                    $result['notification_sync_warnings'][] = [
                        'user_ref_sha256'=>substr(hash('sha256', $legacyUserId), 0, 16),
                        'error'=>get_class($error),
                    ];
                }
            }
            $result['notification_users_synced']=$synced;
        }

        return $result;
    }

    private function visibleTournamentNotificationCount(): int
    {
        $rows = $this->database->fetchAll(
            'SELECT payload_json,hidden_at_utc FROM mgw_notifications WHERE hidden_at_utc IS NULL'
        );
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            try {
                $payload = json_decode((string)($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($payload) && $this->isTournamentNotification($payload)) $count++;
        }
        return $count;
    }

    private function isTournamentNotification(array $notification): bool
    {
        $sourceType = trim((string)($notification['source_type'] ?? ''));
        if ($sourceType !== '' && $sourceType !== 'system') return false;
        $audienceType = trim((string)($notification['audience_type'] ?? ''));
        $audienceRef = trim((string)($notification['audience_ref'] ?? ''));
        $eventKey = trim((string)($notification['event_key'] ?? ''));
        $requestId = trim((string)($notification['request_id'] ?? ''));
        return $audienceType === 'tournament'
            || str_starts_with($audienceRef, 'official-tournament')
            || str_contains($eventKey, 'official-tournament')
            || str_contains($requestId, 'official-tournament');
    }

    private function assertStaging(array $server): void
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            throw new RuntimeException('MVP-21 tournament baseline reset is staging-only.');
        }
        $host = strtolower(trim((string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if ($host !== self::STAGING_HOST) {
            throw new RuntimeException('MVP-21 tournament baseline reset refuses this host.');
        }
    }
}
