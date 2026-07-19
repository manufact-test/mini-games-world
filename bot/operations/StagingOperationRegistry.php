<?php
declare(strict_types=1);

final class StagingOperationRegistry
{
    public static function definitions(
        array $config,
        StorageAdapterInterface $storage,
        DatabaseConnectionInterface $database,
        array $migrationStatus,
        string $privateDir
    ): array {
        return [
            new StagingOperationDefinition(
                'mvp-14.8.4f-runtime-baseline-v1',
                'v97-mvp14-staging-operations-runner',
                static function () use ($config, $storage, $database, $migrationStatus): array {
                    $router = new RuntimeStorageRouter($config);
                    $requiredModules = [
                        'accounts',
                        'realtime',
                        'invites',
                        'notifications',
                        'economy',
                        'history',
                    ];
                    $enabledModules = $router->enabledModules();
                    $missingModules = array_values(array_diff($requiredModules, $enabledModules));
                    $blockers = [];
                    if ($missingModules !== []) {
                        $blockers[] = 'Required staged DB runtime modules are not enabled.';
                    }
                    if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
                        $blockers[] = 'Global JSON rollback storage is not active.';
                    }
                    if ((int)($migrationStatus['pending_count'] ?? -1) !== 0) {
                        $blockers[] = 'Database schema has pending migrations.';
                    }

                    $snapshot = $storage->readOnly(static fn(array $data): array => $data);
                    if (!is_array($snapshot)) {
                        throw new RuntimeException('Staging operations runner could not read the JSON snapshot.');
                    }

                    $realtimeSync = (new LegacyRealtimeShadowSyncService($storage, $database))->run();
                    $economySync = (new LegacyEconomyShadowSyncService($storage, $database))->run();
                    if (empty($realtimeSync['ok'])) $blockers[] = 'Realtime shadow synchronization failed.';
                    if (empty($economySync['ok'])) $blockers[] = 'Economy shadow synchronization failed.';

                    $historyAudit = (new RuntimeHistoryRepository(
                        $config,
                        $router,
                        $database,
                        new HistoryService($config, new UserService($config))
                    ))->auditParity($snapshot);
                    foreach ((array)($historyAudit['blockers'] ?? []) as $blocker) {
                        $blockers[] = (string)$blocker;
                    }

                    $integrity = new LedgerIntegrityVerifier($database);
                    $economyAudit = (new LegacyEconomyRuntimeReconciliationService(
                        $database,
                        new LegacyEconomyDeltaImportService(
                            $database,
                            new LedgerWriteService($database),
                            $integrity
                        ),
                        $integrity
                    ))->preview();
                    foreach ((array)($economyAudit['blocking_reasons'] ?? []) as $blocker) {
                        $blockers[] = (string)$blocker;
                    }

                    $blockers = array_values(array_unique(array_filter(array_map(
                        static fn(mixed $value): string => trim((string)$value),
                        $blockers
                    ), static fn(string $value): bool => $value !== '')));

                    return [
                        'ok' => $blockers === [],
                        'operation_type' => 'runtime_baseline_audit',
                        'storage_driver' => $storage->driver(),
                        'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
                        'database_driver' => $database->driver(),
                        'schema_current' => (int)($migrationStatus['pending_count'] ?? -1) === 0,
                        'applied_migrations' => (int)($migrationStatus['applied_count'] ?? 0),
                        'enabled_modules' => $enabledModules,
                        'missing_required_modules' => $missingModules,
                        'realtime_shadow' => [
                            'ok' => !empty($realtimeSync['ok']),
                            'source_fingerprint' => (string)($realtimeSync['source_fingerprint'] ?? ''),
                            'sections' => $realtimeSync['sections'] ?? [],
                        ],
                        'economy_shadow' => [
                            'ok' => !empty($economySync['ok']),
                            'source_fingerprint' => (string)($economySync['source_fingerprint'] ?? ''),
                            'sections' => $economySync['sections'] ?? [],
                            'integrity' => $economySync['shadow_integrity'] ?? [],
                        ],
                        'history_audit' => [
                            'ok' => !empty($historyAudit['ok']),
                            'source_user_count' => (int)($historyAudit['source_user_count'] ?? 0),
                            'transaction_count' => (int)($historyAudit['transaction_count'] ?? 0),
                            'game_count' => (int)($historyAudit['game_count'] ?? 0),
                            'mismatch_count' => (int)($historyAudit['mismatch_count'] ?? 0),
                            'operation_mismatch_count' => (int)($historyAudit['operation_mismatch_count'] ?? 0),
                            'match_mismatch_count' => (int)($historyAudit['match_mismatch_count'] ?? 0),
                            'json_history_fingerprint' => (string)($historyAudit['json_history_fingerprint'] ?? ''),
                            'database_history_fingerprint' => (string)($historyAudit['database_history_fingerprint'] ?? ''),
                        ],
                        'economy_audit' => [
                            'ok' => !empty($economyAudit['ok']),
                            'source_user_count' => (int)($economyAudit['source_user_count'] ?? 0),
                            'source_asset_count' => (int)($economyAudit['source_asset_count'] ?? 0),
                            'planned_delta_count' => (int)($economyAudit['planned_delta_count'] ?? 0),
                            'integrity_failure_count' => (int)($economyAudit['integrity_failure_count'] ?? 0),
                            'ledger_entry_count' => (int)($economyAudit['ledger_entry_count'] ?? 0),
                            'active_reservation_count' => (int)($economyAudit['active_reservation_count'] ?? 0),
                            'source_totals' => $economyAudit['source_totals'] ?? [],
                            'database_totals' => $economyAudit['database_totals'] ?? [],
                        ],
                        'blockers' => $blockers,
                        'production_changed' => false,
                        'sensitive_identifiers_exposed' => false,
                    ];
                }
            ),
            (new StagingShopRuntimeOperation(
                $config,
                $storage,
                $database,
                $privateDir
            ))->definition(),
            (new StagingPaymentRuntimeOperation(
                $config,
                $storage,
                $database,
                $privateDir
            ))->definition(),
            (new StagingWeeklyBonusRuntimeOperation(
                $config,
                $storage,
                $database,
                $privateDir
            ))->definition(),
            (new StagingDatabaseRuntimeRegressionOperation(
                $config,
                $storage,
                $database
            ))->definition(),
            (new StagingRuntimeSwitchRollbackRehearsalRetryOperation(
                $config,
                $storage,
                $database,
                $privateDir
            ))->definition(),
        ];
    }
}
