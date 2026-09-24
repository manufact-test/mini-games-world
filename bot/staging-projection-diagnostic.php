<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';
require_once __DIR__ . '/tournaments/StagingTournamentManualAcceptanceService.php';
require_once __DIR__ . '/services/TournamentReconnectTraceService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'method_not_allowed'], 405);
    }
    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        json_response(['ok'=>false,'error'=>'staging_only'], 403);
    }

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m) !== 1) {
        json_response(['ok'=>false,'error'=>'oidc_required'], 403);
    }
    (new GitHubActionsOidcVerifier($config))->verifyAndConsume(trim((string)$m[1]));

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('database_disabled');
    }
    $db = PdoConnectionFactory::create($databaseConfig);

    $migrationVersion = '20260920_0050_add_tournament_rules_consent';
    $migrationPath = __DIR__ . '/database/migrations/' . $migrationVersion . '.php';
    $currentMigrationChecksum = hash_file('sha256', $migrationPath);
    $appliedMigration = $db->fetchAll(
        'SELECT version,checksum,applied_at_utc
         FROM mgw_schema_migrations
         WHERE version=:version
         LIMIT 1',
        ['version'=>$migrationVersion]
    );
    $appliedMigrationChecksum = trim((string)($appliedMigration[0]['checksum'] ?? ''));
    if (!is_string($currentMigrationChecksum) || $currentMigrationChecksum === '') {
        throw new RuntimeException('Could not calculate current tournament migration checksum.');
    }
    if ($appliedMigrationChecksum !== ''
        && !hash_equals(strtolower($appliedMigrationChecksum), strtolower($currentMigrationChecksum))) {
        json_response([
            'ok'=>false,
            'error'=>'migration_checksum_mismatch',
            'migration'=>[
                'version'=>$migrationVersion,
                'applied_checksum'=>$appliedMigrationChecksum,
                'current_checksum'=>$currentMigrationChecksum,
                'applied_at_utc'=>(string)($appliedMigration[0]['applied_at_utc'] ?? ''),
            ],
        ], 409);
    }

    // Exact staging deploys may introduce additive schema that is required by
    // the just-deployed runtime. Apply only the repository's managed pending
    // migrations after GitHub OIDC has authenticated this exact staging push.
    // This endpoint is staging-only and cannot authorize production migration.
    $migrationController = new ManagedMigrationController(
        new MigrationRunner($db, __DIR__ . '/database/migrations'),
        ManagedMigrationConfig::fromApplicationConfig($config)
    );
    $migrationResult = $migrationController->run();

    $ledger = new LedgerWriteService($db);
    $tournaments = new TournamentRegistrationService($db, $ledger);
    $fixture = new StagingTournamentManualAcceptanceService(
        $config,
        $db,
        $ledger,
        $tournaments
    );
    $fixtureOwnershipRepair = $fixture->repairLegacyFixtureOwnership($_SERVER);
    $fixtureRuntimeParity = $fixture->repairFixtureRuntimeParity($_SERVER);
    $tournamentSnapshot = $tournaments->snapshot();

    // One-time staging baseline cleanup after MVP-21 manual acceptance.
    // Preserve the cancellation audit rows, but retire every pre-baseline
    // cancelled/emergency test tournament so participant status reads as a
    // truly empty "before first real tournament" state.
    $stagingCancellationBaselineCleanup = [
        'eligible'=>false,
        'candidate_count'=>0,
        'reset_count'=>0,
    ];
    if (!is_array($tournamentSnapshot['tournament'] ?? null)) {
        $stagingCancellationBaselineCleanup['eligible'] = true;
        $cutoff = '2026-09-24 19:25:00.000000';
        $legacyCancelledRows = $db->fetchAll(
            'SELECT DISTINCT t.tournament_id
             FROM mgw_tournaments t
             INNER JOIN mgw_tournament_cancellation_events e
               ON e.tournament_id=t.tournament_id
             WHERE t.active_slot IS NULL
               AND t.tournament_state IN (:cancelled_state,:emergency_state)
               AND e.created_at_utc<=:cutoff
               AND NOT EXISTS (
                   SELECT 1 FROM mgw_tournament_results tr
                   WHERE tr.tournament_id=t.tournament_id
                     AND tr.reward_eligible<>0
               )
             ORDER BY t.tournament_id ASC',
            [
                'cancelled_state'=>TournamentRegistrationService::STATE_CANCELLED,
                'emergency_state'=>TournamentRegistrationService::STATE_EMERGENCY_STOPPED,
                'cutoff'=>$cutoff,
            ]
        );
        $stagingCancellationBaselineCleanup['candidate_count'] = count($legacyCancelledRows);
        if ($legacyCancelledRows !== []) {
            $resetAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s.u');
            foreach ($legacyCancelledRows as $legacyCancelledRow) {
                if (!is_array($legacyCancelledRow)) continue;
                $legacyTournamentId = trim((string)($legacyCancelledRow['tournament_id'] ?? ''));
                if ($legacyTournamentId === '') continue;
                $stagingCancellationBaselineCleanup['reset_count'] += $db->execute(
                    'UPDATE mgw_tournaments
                     SET tournament_state=:reset_state,
                         registration_closed_reason=:closed_reason,
                         updated_at_utc=:updated_at
                     WHERE tournament_id=:tournament_id
                       AND active_slot IS NULL
                       AND tournament_state IN (:cancelled_state,:emergency_state)',
                    [
                        'reset_state'=>'staging_reset',
                        'closed_reason'=>'staging_baseline_reset',
                        'updated_at'=>$resetAt,
                        'tournament_id'=>$legacyTournamentId,
                        'cancelled_state'=>TournamentRegistrationService::STATE_CANCELLED,
                        'emergency_state'=>TournamentRegistrationService::STATE_EMERGENCY_STOPPED,
                    ]
                );
            }
        }
        $tournamentSnapshot = $tournaments->snapshot();
    }

    $tournamentRoundDiagnostic = [
        'active'=>false,
        'availability'=>null,
        'rounds'=>[],
        'attempt_count'=>0,
    ];
    $activeTournament = $tournamentSnapshot['tournament'] ?? null;
    $activeTournamentId = is_array($activeTournament)
        ? trim((string)($activeTournament['tournament_id'] ?? ''))
        : '';
    if ($activeTournamentId !== '') {
        $tournamentRoundDiagnostic['active'] = true;
        $tournamentRoundDiagnostic['availability'] = $fixture->progressionAcceptanceAvailability($_SERVER);
        $roundRows = $db->fetchAll(
            'SELECT round_no,pair_no,launch_state,attempt_no,wait_kind,match_kind,
                    game_id,winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
             FROM mgw_tournament_round_matches
             WHERE tournament_id=:tournament_id
             ORDER BY round_no ASC,pair_no ASC',
            ['tournament_id'=>$activeTournamentId]
        );
        foreach ($roundRows as $row) {
            if (!is_array($row)) continue;
            $roundNo = max(1, (int)($row['round_no'] ?? 1));
            $key = (string)$roundNo;
            if (!isset($tournamentRoundDiagnostic['rounds'][$key])) {
                $tournamentRoundDiagnostic['rounds'][$key] = [
                    'pair_count'=>0,
                    'completed_count'=>0,
                    'unresolved_count'=>0,
                    'pairs'=>[],
                ];
            }
            $completed = trim((string)($row['completed_at_utc'] ?? '')) !== '';
            $tournamentRoundDiagnostic['rounds'][$key]['pair_count']++;
            $tournamentRoundDiagnostic['rounds'][$key][$completed ? 'completed_count' : 'unresolved_count']++;
            $tournamentRoundDiagnostic['rounds'][$key]['pairs'][] = [
                'pair_no'=>(int)($row['pair_no'] ?? 0),
                'launch_state'=>(string)($row['launch_state'] ?? ''),
                'attempt_no'=>max(1, (int)($row['attempt_no'] ?? 1)),
                'wait_kind'=>(string)($row['wait_kind'] ?? ''),
                'match_kind'=>(string)($row['match_kind'] ?? ''),
                'game_attached'=>trim((string)($row['game_id'] ?? '')) !== '',
                'completed'=>$completed,
                'result_reason'=>(string)($row['result_reason'] ?? ''),
                'winner_present'=>trim((string)($row['winner_mgw_id'] ?? '')) !== '',
                'loser_present'=>trim((string)($row['loser_mgw_id'] ?? '')) !== '',
            ];
        }
        $tournamentRoundDiagnostic['attempt_count'] = (int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_match_attempts WHERE tournament_id=:tournament_id',
            ['tournament_id'=>$activeTournamentId]
        );
    }

    $selectorFallbackCheck = [
        'available'=>false,
        'would_fallback_to_json'=>false,
        'helper'=>'stagingApiPrimaryNotificationSnapshotIsBehind',
    ];
    try {
        $selectorMethod = new ReflectionMethod(
            StorageFactory::class,
            'stagingApiPrimaryNotificationSnapshotIsBehind'
        );
        $selectorMethod->setAccessible(true);
        $selectorFallbackCheck['available'] = true;
        $selectorFallbackCheck['would_fallback_to_json'] = (bool)$selectorMethod->invoke(null, $config);
    } catch (Throwable $selectorError) {
        $selectorFallbackCheck['error_class'] = get_class($selectorError);
    }

    $runtimeStorage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $runtimeSnapshot = $runtimeStorage->transaction(
        static fn(array &$data): array => $data
    );
    $economyPreview = (new UnifiedEconomyRuntimeSyncService(
        $db,
        $ledger,
        new LedgerIntegrityVerifier($db)
    ))->preview($runtimeSnapshot);
    if (($economyPreview['ready'] ?? false) !== true) {
        throw new RuntimeException(
            'unified_economy_probe_failed: '
            . implode('; ', is_array($economyPreview['blocking_reasons'] ?? null)
                ? $economyPreview['blocking_reasons']
                : [])
        );
    }

    $notificationTimestamp = static function (mixed $value): ?string {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return 'invalid_timestamp';
        }
    };
    $normalizeDiagnosticNotification = static function (array $notification) use ($notificationTimestamp): array {
        return [
            'id'=>trim((string)($notification['id'] ?? '')),
            'event_key'=>trim((string)($notification['event_key'] ?? '')),
            'notification_event_id'=>trim((string)($notification['notification_event_id'] ?? '')),
            'event_fingerprint'=>trim((string)($notification['event_fingerprint'] ?? '')),
            'user_id'=>trim((string)($notification['user_id'] ?? '')),
            'type'=>trim((string)($notification['type'] ?? '')),
            'source_type'=>trim((string)($notification['source_type'] ?? '')),
            'audience_type'=>trim((string)($notification['audience_type'] ?? '')),
            'audience_ref'=>trim((string)($notification['audience_ref'] ?? '')),
            'title'=>(string)($notification['title'] ?? 'Уведомление'),
            'message'=>(string)($notification['message'] ?? ''),
            'text'=>(string)($notification['text'] ?? $notification['message'] ?? ''),
            'tone'=>trim((string)($notification['tone'] ?? 'info')) ?: 'info',
            'order_id'=>trim((string)($notification['order_id'] ?? '')),
            'payment_id'=>trim((string)($notification['payment_id'] ?? '')),
            'transaction_id'=>trim((string)($notification['transaction_id'] ?? '')),
            'invite_token'=>trim((string)($notification['invite_token'] ?? '')),
            'cycle_key'=>trim((string)($notification['cycle_key'] ?? '')),
            'deep_link'=>trim((string)($notification['deep_link'] ?? '')),
            'created_by'=>trim((string)($notification['created_by'] ?? '')),
            'created_at'=>$notificationTimestamp($notification['created_at'] ?? null),
            'scheduled_at'=>$notificationTimestamp($notification['scheduled_at'] ?? null),
            'delivered_at'=>$notificationTimestamp($notification['delivered_at'] ?? null),
            'read_at'=>$notificationTimestamp($notification['read_at'] ?? null),
            'hidden_at'=>$notificationTimestamp($notification['hidden_at'] ?? null),
            'expires_at'=>$notificationTimestamp($notification['expires_at'] ?? null),
        ];
    };
    $notificationFieldDiff = static function (string $legacyUserId) use (
        $db,
        $runtimeSnapshot,
        $normalizeDiagnosticNotification
    ): array {
        $ownershipRows = $db->fetchAll(
            'SELECT account_ref
             FROM mgw_account_ownership
             WHERE legacy_user_id=:legacy_user_id
               AND ownership_status=:ownership_status',
            ['legacy_user_id'=>$legacyUserId,'ownership_status'=>'active']
        );
        if (count($ownershipRows) !== 1) {
            return [
                'source_only_event_refs'=>[],
                'database_only_event_refs'=>[],
                'mismatch_field_counts'=>['ownership_scope'=>1],
                'mismatch_samples'=>[],
            ];
        }
        $accountRef = trim((string)($ownershipRows[0]['account_ref'] ?? ''));
        $sourceByEvent = [];
        foreach (is_array($runtimeSnapshot['notifications'] ?? null) ? $runtimeSnapshot['notifications'] : [] as $notification) {
            if (!is_array($notification)
                || trim((string)($notification['user_id'] ?? '')) !== $legacyUserId) {
                continue;
            }
            $eventKey = trim((string)($notification['event_key'] ?? ''));
            if ($eventKey === '') continue;
            $sourceByEvent[$eventKey] = $normalizeDiagnosticNotification($notification);
        }

        $databaseByEvent = [];
        foreach ($db->fetchAll(
            'SELECT notification_id,event_key,legacy_user_id,type,title,message,tone,invite_token,
                    payload_json,created_at_utc,read_at_utc,hidden_at_utc
             FROM mgw_notifications
             WHERE recipient_ref=:recipient_ref',
            ['recipient_ref'=>$accountRef]
        ) as $row) {
            if (!is_array($row)) continue;
            $eventKey = trim((string)($row['event_key'] ?? ''));
            if ($eventKey === '') continue;
            $payload = [];
            try {
                $decoded = json_decode((string)($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) $payload = $decoded;
            } catch (Throwable) {
                $payload = [];
            }
            $payload['id'] = (string)($row['notification_id'] ?? '');
            $payload['event_key'] = $eventKey;
            $payload['user_id'] = (string)($row['legacy_user_id'] ?? '');
            $payload['type'] = (string)($row['type'] ?? '');
            $payload['title'] = (string)($row['title'] ?? 'Уведомление');
            $payload['message'] = (string)($row['message'] ?? '');
            $payload['tone'] = trim((string)($row['tone'] ?? '')) ?: 'info';
            $payload['invite_token'] = (string)($row['invite_token'] ?? '');
            $payload['created_at'] = $row['created_at_utc'] ?? null;
            $payload['read_at'] = $row['read_at_utc'] ?? null;
            $payload['hidden_at'] = $row['hidden_at_utc'] ?? null;
            $databaseByEvent[$eventKey] = $normalizeDiagnosticNotification($payload);
        }

        $sourceOnly = array_values(array_diff(array_keys($sourceByEvent), array_keys($databaseByEvent)));
        $databaseOnly = array_values(array_diff(array_keys($databaseByEvent), array_keys($sourceByEvent)));
        sort($sourceOnly, SORT_STRING);
        sort($databaseOnly, SORT_STRING);
        $fieldCounts = [];
        $samples = [];
        foreach (array_intersect(array_keys($sourceByEvent), array_keys($databaseByEvent)) as $eventKey) {
            $different = [];
            foreach ($sourceByEvent[$eventKey] as $field=>$sourceValue) {
                if (($databaseByEvent[$eventKey][$field] ?? null) === $sourceValue) continue;
                $different[] = (string)$field;
                $fieldCounts[$field] = (int)($fieldCounts[$field] ?? 0) + 1;
            }
            if ($different === [] || count($samples) >= 12) continue;
            sort($different, SORT_STRING);
            $samples[] = [
                'event_ref_sha256'=>substr(hash('sha256', $eventKey), 0, 16),
                'notification_type'=>(string)($sourceByEvent[$eventKey]['type'] ?? ''),
                'source_type'=>(string)($sourceByEvent[$eventKey]['source_type'] ?? ''),
                'audience_type'=>(string)($sourceByEvent[$eventKey]['audience_type'] ?? ''),
                'differing_fields'=>$different,
            ];
        }
        ksort($fieldCounts, SORT_STRING);
        return [
            'source_only_event_refs'=>array_map(
                static fn(string $eventKey): string => substr(hash('sha256', $eventKey), 0, 16),
                array_slice($sourceOnly, 0, 12)
            ),
            'database_only_event_refs'=>array_map(
                static fn(string $eventKey): string => substr(hash('sha256', $eventKey), 0, 16),
                array_slice($databaseOnly, 0, 12)
            ),
            'mismatch_field_counts'=>$fieldCounts,
            'mismatch_samples'=>$samples,
        ];
    };

    $notificationParityFailures = [];
    $notificationParityChecked = 0;
    $notificationRepository = new RuntimeNotificationRepository(
        $config,
        $runtimeStorageRouter,
        $db
    );
    foreach (is_array($runtimeSnapshot['users'] ?? null) ? $runtimeSnapshot['users'] : [] as $key=>$runtimeUser) {
        if (!is_array($runtimeUser)) continue;
        $legacyUserId = trim((string)($runtimeUser['id'] ?? $key));
        if ($legacyUserId === '') continue;
        $notificationParityChecked++;
        $classification = in_array($legacyUserId, ['stg_test_player_a','stg_test_player_b'], true)
            ? 'technical_ab'
            : (preg_match('/^stg_tour_(?:v2_)?[a-f0-9]{12}$/', $legacyUserId) === 1
                ? 'tournament_fixture'
                : 'runtime_user');
        try {
            $report = $notificationRepository->auditParity($runtimeSnapshot, $legacyUserId);
            if (($report['ok'] ?? false) === true) continue;
            $fieldDiff = $notificationFieldDiff($legacyUserId);
            $notificationParityFailures[] = [
                'user_ref_sha256'=>substr(hash('sha256', $legacyUserId), 0, 16),
                'classification'=>$classification,
                'source_count'=>(int)($report['source_count'] ?? 0),
                'database_count'=>(int)($report['database_count'] ?? 0),
                'source_fingerprint'=>(string)($report['source_fingerprint'] ?? ''),
                'database_fingerprint'=>(string)($report['database_fingerprint'] ?? ''),
                'blockers'=>array_values(array_map(
                    static fn(mixed $value): string => substr(trim((string)$value), 0, 240),
                    is_array($report['blockers'] ?? null) ? $report['blockers'] : []
                )),
                'field_diff'=>$fieldDiff,
            ];
        } catch (Throwable $notificationParityError) {
            $notificationParityFailures[] = [
                'user_ref_sha256'=>substr(hash('sha256', $legacyUserId), 0, 16),
                'classification'=>$classification,
                'source_count'=>null,
                'database_count'=>null,
                'source_fingerprint'=>'',
                'database_fingerprint'=>'',
                'blockers'=>['audit_exception:' . get_class($notificationParityError)],
            ];
        }
    }
    $notificationParity = [
        'ok'=>$notificationParityFailures === [],
        'checked_user_count'=>$notificationParityChecked,
        'failure_count'=>count($notificationParityFailures),
        'failures'=>$notificationParityFailures,
        'sensitive_identifiers_exposed'=>false,
    ];

    $primaryStorage = new DatabasePrimaryStateStorageAdapter($db);
    $primaryStatus = $primaryStorage->status();
    $primarySnapshot = $primaryStorage->readOnly(
        static fn(array $data): array => $data
    );

    $snapshotNotificationDiff = static function (
        array $leftSnapshot,
        array $rightSnapshot,
        string $legacyUserId
    ) use ($normalizeDiagnosticNotification): array {
        $index = static function (array $snapshot) use (
            $legacyUserId,
            $normalizeDiagnosticNotification
        ): array {
            $result = [];
            foreach (is_array($snapshot['notifications'] ?? null) ? $snapshot['notifications'] : [] as $notification) {
                if (!is_array($notification)
                    || trim((string)($notification['user_id'] ?? '')) !== $legacyUserId) {
                    continue;
                }
                $eventKey = trim((string)($notification['event_key'] ?? ''));
                if ($eventKey === '') continue;
                $result[$eventKey] = $normalizeDiagnosticNotification($notification);
            }
            return $result;
        };

        $left = $index($leftSnapshot);
        $right = $index($rightSnapshot);
        $leftOnly = array_values(array_diff(array_keys($left), array_keys($right)));
        $rightOnly = array_values(array_diff(array_keys($right), array_keys($left)));
        sort($leftOnly, SORT_STRING);
        sort($rightOnly, SORT_STRING);
        $fieldCounts = [];
        $samples = [];
        foreach (array_intersect(array_keys($left), array_keys($right)) as $eventKey) {
            $different = [];
            foreach ($left[$eventKey] as $field=>$leftValue) {
                if (($right[$eventKey][$field] ?? null) === $leftValue) continue;
                $different[] = (string)$field;
                $fieldCounts[$field] = (int)($fieldCounts[$field] ?? 0) + 1;
            }
            if ($different === [] || count($samples) >= 12) continue;
            sort($different, SORT_STRING);
            $samples[] = [
                'event_ref_sha256'=>substr(hash('sha256', $eventKey), 0, 16),
                'notification_type'=>(string)($left[$eventKey]['type'] ?? ''),
                'source_type'=>(string)($left[$eventKey]['source_type'] ?? ''),
                'audience_type'=>(string)($left[$eventKey]['audience_type'] ?? ''),
                'differing_fields'=>$different,
            ];
        }
        ksort($fieldCounts, SORT_STRING);
        return [
            'left_count'=>count($left),
            'right_count'=>count($right),
            'left_only_event_refs'=>array_map(
                static fn(string $eventKey): string => substr(hash('sha256', $eventKey), 0, 16),
                array_slice($leftOnly, 0, 12)
            ),
            'right_only_event_refs'=>array_map(
                static fn(string $eventKey): string => substr(hash('sha256', $eventKey), 0, 16),
                array_slice($rightOnly, 0, 12)
            ),
            'mismatch_field_counts'=>$fieldCounts,
            'mismatch_samples'=>$samples,
        ];
    };

    $primaryNotificationFailures = [];
    $primaryNotificationChecked = 0;
    foreach (is_array($primarySnapshot['users'] ?? null) ? $primarySnapshot['users'] : [] as $key=>$primaryUser) {
        if (!is_array($primaryUser)) continue;
        $legacyUserId = trim((string)($primaryUser['id'] ?? $key));
        if ($legacyUserId === '') continue;
        $primaryNotificationChecked++;
        $classification = in_array($legacyUserId, ['stg_test_player_a','stg_test_player_b'], true)
            ? 'technical_ab'
            : (preg_match('/^stg_tour_(?:v2_)?[a-f0-9]{12}$/', $legacyUserId) === 1
                ? 'tournament_fixture'
                : 'runtime_user');
        try {
            $report = $notificationRepository->auditParity($primarySnapshot, $legacyUserId);
            if (($report['ok'] ?? false) === true) continue;
            $primaryNotificationFailures[] = [
                'user_ref_sha256'=>substr(hash('sha256', $legacyUserId), 0, 16),
                'classification'=>$classification,
                'source_count'=>(int)($report['source_count'] ?? 0),
                'database_count'=>(int)($report['database_count'] ?? 0),
                'source_fingerprint'=>(string)($report['source_fingerprint'] ?? ''),
                'database_fingerprint'=>(string)($report['database_fingerprint'] ?? ''),
                'blockers'=>array_values(array_map(
                    static fn(mixed $value): string => substr(trim((string)$value), 0, 240),
                    is_array($report['blockers'] ?? null) ? $report['blockers'] : []
                )),
                'rollback_vs_primary'=>$snapshotNotificationDiff(
                    $runtimeSnapshot,
                    $primarySnapshot,
                    $legacyUserId
                ),
            ];
        } catch (Throwable $primaryNotificationError) {
            $primaryNotificationFailures[] = [
                'user_ref_sha256'=>substr(hash('sha256', $legacyUserId), 0, 16),
                'classification'=>$classification,
                'source_count'=>null,
                'database_count'=>null,
                'source_fingerprint'=>'',
                'database_fingerprint'=>'',
                'blockers'=>['audit_exception:' . get_class($primaryNotificationError)],
            ];
        }
    }
    $primaryNotificationParity = [
        'ok'=>$primaryNotificationFailures === [],
        'checked_user_count'=>$primaryNotificationChecked,
        'failure_count'=>count($primaryNotificationFailures),
        'primary_revision'=>(int)($primaryStatus['revision'] ?? 0),
        'primary_state_sha256'=>(string)($primaryStatus['state_sha256'] ?? ''),
        'failures'=>$primaryNotificationFailures,
        'sensitive_identifiers_exposed'=>false,
    ];

    // The canonical browser shell can preload the public rating archive while
    // an unrelated game test is running. Prove that read owner here so an HTTP
    // 500 becomes an exact OIDC-protected staging diagnostic instead of a
    // generic browser "Ошибка API: 500".
    try {
        $ratingArchiveOverview = (new RatingArchiveService($db))->publicOverview();
    } catch (Throwable $ratingArchiveError) {
        throw new RuntimeException(
            'rating_archive_probe_failed: '
            . get_class($ratingArchiveError)
            . ': '
            . $ratingArchiveError->getMessage(),
            0,
            $ratingArchiveError
        );
    }

    $events = $db->fetchAll(
        'SELECT state_revision, status, attempt_count, last_error, projection_version
         FROM mgw_runtime_primary_projection_outbox
         ORDER BY state_revision DESC
         LIMIT 12'
    );

    $failures = [];
    foreach ($events as $row) {
        if (!is_array($row)) continue;
        $error = trim((string)($row['last_error'] ?? ''));
        if ($error === '' && (string)($row['status'] ?? '') === 'completed') continue;
        $failures[] = [
            'state_revision'=>(int)($row['state_revision'] ?? 0),
            'status'=>(string)($row['status'] ?? ''),
            'attempt_count'=>(int)($row['attempt_count'] ?? 0),
            'last_error'=>substr($error, 0, 1200),
            'projection_version'=>(string)($row['projection_version'] ?? ''),
        ];
    }

    json_response([
        'ok'=>true,
        'service'=>'staging-projection-diagnostic',
        'managed_migrations'=>[
            'action'=>(string)($migrationResult['action'] ?? ''),
            'executed_count'=>(int)($migrationResult['executed_count'] ?? 0),
            'pending_after'=>(int)($migrationResult['after']['pending_count'] ?? -1),
        ],
        'failures'=>$failures,
        'tournament'=>$tournamentSnapshot['tournament'] ?? null,
        'registered_count'=>(int)($tournamentSnapshot['tournament']['registered_count'] ?? 0),
        'staging_cancellation_baseline_cleanup'=>$stagingCancellationBaselineCleanup,
        'tournament_fixture_ownership_repair'=>$fixtureOwnershipRepair,
        'tournament_fixture_runtime_parity'=>$fixtureRuntimeParity,
        'tournament_round_diagnostic'=>$tournamentRoundDiagnostic,
        'tournament_reconnect_trace'=>(new TournamentReconnectTraceService($config))->tail(),
        'storage_selector_notification_fallback'=>$selectorFallbackCheck,
        'unified_economy_preview'=>[
            'ready'=>(bool)($economyPreview['ready'] ?? false),
            'reconciled'=>(bool)($economyPreview['reconciled'] ?? false),
            'source_user_count'=>(int)($economyPreview['source_user_count'] ?? 0),
            'planned_delta_count'=>(int)($economyPreview['planned_delta_count'] ?? 0),
            'blocking_reasons'=>is_array($economyPreview['blocking_reasons'] ?? null)
                ? array_values($economyPreview['blocking_reasons'])
                : [],
        ],
        'notification_runtime_parity'=>$notificationParity,
        'notification_primary_parity'=>$primaryNotificationParity,
        'rating_archive'=>[
            'competition_state'=>(string)($ratingArchiveOverview['competition_state'] ?? ''),
            'current_season_id'=>(string)($ratingArchiveOverview['current_season_id'] ?? ''),
            'season_count'=>count(is_array($ratingArchiveOverview['seasons'] ?? null) ? $ratingArchiveOverview['seasons'] : []),
            'hall_of_fame_count'=>count(is_array($ratingArchiveOverview['hall_of_fame'] ?? null) ? $ratingArchiveOverview['hall_of_fame'] : []),
        ],
        'rules'=>isset($tournamentSnapshot['tournament']['rules']) && is_array($tournamentSnapshot['tournament']['rules'])
            ? [
                'version'=>(string)($tournamentSnapshot['tournament']['rules']['version'] ?? ''),
                'language'=>(string)($tournamentSnapshot['tournament']['rules']['language'] ?? ''),
                'sha256'=>(string)($tournamentSnapshot['tournament']['rules']['sha256'] ?? ''),
            ]
            : null,
        'generated_at'=>gmdate(DATE_ATOM),
    ]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging projection diagnostic] ' . $error->getMessage());
    json_response([
        'ok'=>false,
        'error'=>'diagnostic_failed',
        'exception'=>get_class($error),
        'detail'=>substr($error->getMessage(), 0, 1600),
    ], 500);
}
