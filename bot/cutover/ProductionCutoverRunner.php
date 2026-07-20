<?php
declare(strict_types=1);

final class ProductionCutoverRunner
{
    private const BUILD = 'v103-mvp14-production-cutover';
    private const MODULES = [
        'accounts',
        'realtime',
        'invites',
        'notifications',
        'economy',
        'history',
        'shop',
        'payments',
        'weekly_bonus',
    ];

    private string $projectRoot;
    private string $privateDir;
    private string $runtimeFile;
    private string $runtimeBackupFile;
    private string $stateFile;
    private string $writeBlockFile;

    public function __construct(
        string $projectRoot,
        private array $config,
        private string $configFile,
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        private BackupManager $backupManager,
        private ProductionCutoverConfig $policy,
        private ?int $now = null
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        $this->configFile = str_replace('\\', '/', trim($this->configFile));
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Production cutover project root is unavailable.');
        }
        if ($this->configFile === '' || !is_file($this->configFile)) {
            throw new InvalidArgumentException('Production cutover private config is unavailable.');
        }

        $this->privateDir = rtrim(str_replace('\\', '/', dirname($this->configFile)), '/');
        if ($this->privateDir === '' || $this->isInside($this->privateDir, $this->projectRoot)) {
            throw new RuntimeException('Production cutover private directory is unavailable or unsafe.');
        }
        $this->runtimeFile = $this->privateDir . '/runtime.php';
        $this->runtimeBackupFile = $this->privateDir . '/production-cutover.runtime.backup';
        $this->stateFile = $this->privateDir . '/production-cutover.json';

        $dataDir = rtrim(str_replace('\\', '/', trim((string)($this->config['data_dir'] ?? ''))), '/');
        if ($dataDir === '' || !is_dir($dataDir)) {
            throw new RuntimeException('Production JSON data directory is unavailable.');
        }
        $this->writeBlockFile = $dataDir . '/.cutover-write-block';
    }

    public function run(): array
    {
        $this->assertEnvironmentAndBuild();

        $state = $this->readState();
        if (($state['state'] ?? '') === 'completed') {
            return $this->completedNoop($state);
        }
        if (($state['state'] ?? '') === 'rolled_back') {
            return $this->rolledBackNoop($state);
        }
        if (in_array((string)($state['state'] ?? ''), ['running', 'switching', 'validating'], true)
            || ($state === [] && is_file($this->runtimeBackupFile))) {
            $rollback = $this->rollbackInternal('interrupted cutover recovered before retry');
            return [
                'ok' => false,
                'report_type' => 'mvp-14.9-production-cutover',
                'action' => 'automatic_rollback',
                'reason' => 'interrupted_cutover_recovered',
                'rollback' => $rollback,
                'production_db_runtime_enabled' => false,
                'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'manual_review_required' => true,
                'generated_at_utc' => $this->nowUtc(),
            ];
        }

        $startedAt = $this->nowUtc();
        $preflight = null;
        $backup = null;
        $frozenSourceFingerprint = '';

        try {
            $preflight = (new ProductionPreflightRunner(
                $this->projectRoot,
                $this->config,
                $this->configFile,
                $this->timestamp()
            ))->run();
            if (($preflight['technical_ready_for_window'] ?? false) !== true
                || !empty($preflight['blockers'])) {
                throw new RuntimeException('Production preflight is not clean for the cutover window.');
            }

            $planFingerprint = strtolower(trim((string)($preflight['cutover_plan_fingerprint'] ?? '')));
            $this->policy->assertApproved(self::BUILD, $planFingerprint, $this->timestamp());

            $originalRuntime = $this->readRuntime();
            $this->assertOriginalRuntimeSafe($originalRuntime);
            $this->createRuntimeBackup();
            $this->writeState([
                'state' => 'running',
                'build' => self::BUILD,
                'started_at_utc' => $startedAt,
                'plan_fingerprint' => $planFingerprint,
                'source_fingerprint' => (string)($preflight['source_inventory']['source_fingerprint'] ?? ''),
                'runtime_backup_present' => true,
            ]);

            $maintenanceRuntime = $this->maintenanceRuntime($originalRuntime);
            $this->writeRuntime($maintenanceRuntime);
            $maintenanceConfig = $this->configWithRuntime($maintenanceRuntime);
            $this->assertMaintenanceActive($maintenanceConfig);

            $queueCleanup = $this->cleanupQueue();
            $drain = $this->inspectDrain();
            $this->assertDrainReady($drain);

            $this->activateWriteBlock($planFingerprint);
            $snapshot = $this->snapshot();
            $frozenSourceFingerprint = $this->snapshotFingerprint($snapshot);
            $expectedSourceFingerprint = strtolower(trim((string)($preflight['source_inventory']['source_fingerprint'] ?? '')));
            if ($expectedSourceFingerprint === '' || !hash_equals($expectedSourceFingerprint, $frozenSourceFingerprint)) {
                throw new RuntimeException('Production JSON source changed after the approved preflight.');
            }

            $backup = $this->backupManager->create('production', self::BUILD);
            $this->assertBackup($backup);

            $this->writeState([
                'state' => 'switching',
                'build' => self::BUILD,
                'started_at_utc' => $startedAt,
                'plan_fingerprint' => $planFingerprint,
                'source_fingerprint' => $frozenSourceFingerprint,
                'backup_id' => (string)($backup['backup_id'] ?? ''),
                'backup_snapshot_sha256' => (string)($backup['snapshot_sha256'] ?? ''),
                'runtime_backup_present' => true,
                'json_write_block_active' => true,
            ]);

            $import = $this->importAll($snapshot);
            if (empty($import['ok'])) {
                throw new RuntimeException('Production JSON to database import did not complete cleanly.');
            }

            $activatedRuntime = $this->activatedRuntime(
                $maintenanceRuntime,
                $planFingerprint,
                $frozenSourceFingerprint
            );
            $activatedConfig = $this->configWithRuntime($activatedRuntime);
            $router = new RuntimeStorageRouter($activatedConfig);
            if (!$router->enabled() || array_values(array_diff(self::MODULES, $router->enabledModules())) !== []) {
                throw new RuntimeException('Production database runtime activation contract is incomplete.');
            }

            $synchronization = $this->synchronizeRuntime($activatedConfig, $snapshot);
            if (empty($synchronization['ok'])) {
                throw new RuntimeException('Production database runtime synchronization failed.');
            }

            $regressionBeforePublish = $this->fullRegression($activatedConfig);
            if (empty($regressionBeforePublish['ok']) || !empty($regressionBeforePublish['blockers'])) {
                throw new RuntimeException('Production DB regression failed before publishing the route.');
            }

            $this->writeRuntime($activatedRuntime);
            $this->writeState([
                'state' => 'validating',
                'build' => self::BUILD,
                'started_at_utc' => $startedAt,
                'plan_fingerprint' => $planFingerprint,
                'source_fingerprint' => $frozenSourceFingerprint,
                'backup_id' => (string)($backup['backup_id'] ?? ''),
                'backup_snapshot_sha256' => (string)($backup['snapshot_sha256'] ?? ''),
                'runtime_backup_present' => true,
                'database_runtime_published' => true,
                'json_write_block_active' => true,
            ]);

            $this->removeWriteBlock();
            $publishedConfig = $this->configWithRuntime($this->readRuntime());
            $publishedRouter = new RuntimeStorageRouter($publishedConfig);
            if (!$publishedRouter->enabled()) {
                throw new RuntimeException('Published production database runtime did not activate.');
            }

            $publishedSnapshot = $this->snapshot();
            $publishedFingerprint = $this->snapshotFingerprint($publishedSnapshot);
            if (!hash_equals($frozenSourceFingerprint, $publishedFingerprint)) {
                throw new RuntimeException('JSON rollback snapshot changed during the sealed cutover.');
            }

            $regressionAfterPublish = $this->fullRegression($publishedConfig);
            if (empty($regressionAfterPublish['ok']) || !empty($regressionAfterPublish['blockers'])) {
                throw new RuntimeException('Production DB regression failed after publishing the route.');
            }

            $finalRuntime = $this->releaseMaintenance($originalRuntime, $activatedRuntime);
            $this->writeRuntime($finalRuntime);
            $finalConfig = $this->configWithRuntime($finalRuntime);
            $finalRouter = new RuntimeStorageRouter($finalConfig);
            if (!$finalRouter->enabled()
                || array_values(array_diff(self::MODULES, $finalRouter->enabledModules())) !== []) {
                throw new RuntimeException('Final production database route is incomplete.');
            }

            $finalRegression = $this->fullRegression($finalConfig);
            if (empty($finalRegression['ok']) || !empty($finalRegression['blockers'])) {
                throw new RuntimeException('Final production DB regression failed after maintenance release.');
            }

            $finishedAt = $this->nowUtc();
            $completedState = [
                'state' => 'completed',
                'build' => self::BUILD,
                'started_at_utc' => $startedAt,
                'completed_at_utc' => $finishedAt,
                'plan_fingerprint' => $planFingerprint,
                'source_fingerprint' => $frozenSourceFingerprint,
                'backup_id' => (string)($backup['backup_id'] ?? ''),
                'backup_snapshot_sha256' => (string)($backup['snapshot_sha256'] ?? ''),
                'runtime_backup_present' => is_file($this->runtimeBackupFile),
                'database_runtime_published' => true,
                'json_write_block_active' => false,
                'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            ];
            $this->writeState($completedState);

            return [
                'ok' => true,
                'report_type' => 'mvp-14.9-production-cutover',
                'action' => 'cutover_completed',
                'environment' => 'production',
                'build' => self::BUILD,
                'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'runtime_route' => RuntimeStorageRouter::DRIVER_DATABASE,
                'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'enabled_modules' => $finalRouter->enabledModules(),
                'maintenance_released' => true,
                'financial_read_only_released' => true,
                'json_write_block_removed' => !is_file($this->writeBlockFile),
                'json_snapshot_unchanged' => true,
                'source_fingerprint' => $frozenSourceFingerprint,
                'cutover_plan_fingerprint' => $planFingerprint,
                'backup' => $this->compactBackup($backup),
                'drain' => $drain,
                'queue_cleanup' => $queueCleanup,
                'import' => $import,
                'synchronization' => $synchronization,
                'regression_before_publish' => $this->compactRegression($regressionBeforePublish),
                'regression_after_publish' => $this->compactRegression($regressionAfterPublish),
                'final_regression' => $this->compactRegression($finalRegression),
                'automatic_rollback_available' => is_file($this->runtimeBackupFile),
                'production_changed' => true,
                'sensitive_identifiers_exposed' => false,
                'started_at_utc' => $startedAt,
                'finished_at_utc' => $finishedAt,
                'next_step' => 'Run the manual production smoke checklist. Roll back to JSON immediately if any check fails.',
            ];
        } catch (Throwable $error) {
            $rollback = $this->rollbackInternal('automatic rollback after cutover failure');
            return [
                'ok' => false,
                'report_type' => 'mvp-14.9-production-cutover',
                'action' => 'automatic_rollback',
                'environment' => 'production',
                'build' => self::BUILD,
                'error_class' => get_class($error),
                'error_message' => $this->safeMessage($error->getMessage()),
                'preflight_ready' => is_array($preflight) && ($preflight['technical_ready_for_window'] ?? false) === true,
                'backup' => is_array($backup) ? $this->compactBackup($backup) : null,
                'source_fingerprint' => $frozenSourceFingerprint,
                'rollback' => $rollback,
                'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'production_db_runtime_enabled' => false,
                'manual_review_required' => true,
                'sensitive_identifiers_exposed' => false,
                'started_at_utc' => $startedAt,
                'failed_at_utc' => $this->nowUtc(),
            ];
        }
    }

    public function status(): array
    {
        $this->assertEnvironmentAndBuild();
        $state = $this->readState();
        $runtime = $this->readRuntime();
        $runtimeConfig = $this->configWithRuntime($runtime);

        $routerEnabled = false;
        $enabledModules = [];
        $routerError = '';
        try {
            $router = new RuntimeStorageRouter($runtimeConfig);
            $routerEnabled = $router->enabled();
            $enabledModules = $router->enabledModules();
        } catch (Throwable $error) {
            $routerError = $this->safeMessage($error->getMessage());
        }

        return [
            'ok' => $routerError === '',
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'status',
            'environment' => 'production',
            'build' => self::BUILD,
            'state' => (string)($state['state'] ?? 'not_started'),
            'database_runtime_enabled' => $routerEnabled,
            'enabled_modules' => $enabledModules,
            'router_error' => $routerError,
            'runtime_backup_present' => is_file($this->runtimeBackupFile),
            'json_write_block_active' => is_file($this->writeBlockFile),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'state_summary' => $this->compactState($state),
            'approval' => $this->policy->safeSummary(),
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    public function rollback(string $reason = 'manual production rollback'): array
    {
        $this->assertEnvironmentAndBuild();
        return $this->rollbackInternal($reason);
    }

    private function importAll(array $snapshot): array
    {
        $realtimeShadow = (new LegacyRealtimeShadowSyncService($this->storage, $this->database))->run();
        $economyShadow = (new LegacyEconomyShadowSyncService($this->storage, $this->database))->run();
        if (empty($realtimeShadow['ok']) || empty($economyShadow['ok'])) {
            throw new RuntimeException('Legacy shadow synchronization failed.');
        }

        $ledgerVerifier = new LedgerIntegrityVerifier($this->database);
        $accountImport = (new LegacyAccountImportService($this->storage, $this->database))->run();
        $openingBalances = (new LegacyOpeningBalanceImportService(
            $this->database,
            new LedgerWriteService($this->database),
            $ledgerVerifier
        ))->run();
        $ownership = (new LegacyAccountOwnershipLinkService(
            $this->storage,
            $this->database,
            $ledgerVerifier
        ))->run();
        $realtimeImport = (new LegacyRealtimeNormalizedImportService(
            $this->storage,
            $this->database,
            new LegacyRealtimeShadowSyncService($this->storage, $this->database)
        ))->run();
        $financialArchive = (new LegacyFinancialArchiveImportService(
            $this->storage,
            $this->database,
            new LegacyFinancialStatusNormalizer()
        ))->run();

        $schemas = [
            'shop' => (new RuntimeShopSchemaInstaller($this->database))->install(),
            'payments' => (new RuntimePaymentSchemaInstaller($this->database))->install(),
            'weekly_bonus' => (new RuntimeWeeklyBonusSchemaInstaller($this->database))->install(),
        ];
        foreach ($schemas as $module => $schema) {
            if (empty($schema['ok'])) throw new RuntimeException($module . ' runtime schema installation failed.');
        }

        return [
            'ok' => true,
            'source_user_count' => count(is_array($snapshot['users'] ?? null) ? $snapshot['users'] : []),
            'source_game_count' => count(is_array($snapshot['games'] ?? null) ? $snapshot['games'] : []),
            'source_transaction_count' => count(is_array($snapshot['transactions'] ?? null) ? $snapshot['transactions'] : []),
            'realtime_shadow' => $this->compactShadow($realtimeShadow),
            'economy_shadow' => $this->compactShadow($economyShadow),
            'accounts' => $this->compactImport($accountImport, [
                'source_user_count', 'created_user_count', 'created_legacy_link_count',
                'updated_user_count', 'unchanged_user_count',
            ]),
            'opening_balances' => $this->compactImport($openingBalances, [
                'source_user_count', 'source_asset_count', 'created_balance_count',
                'created_ledger_count', 'replayed_ledger_count',
            ]),
            'ownership' => $this->compactImport($ownership, [
                'source_user_count', 'created_ownership_count',
                'created_provider_identity_count', 'reused_provider_identity_count',
                'unchanged_user_count',
            ]),
            'realtime_normalized' => [
                'ok' => !empty($realtimeImport['ok']),
                'status' => (string)($realtimeImport['status'] ?? ''),
                'source_counts' => $realtimeImport['source_counts'] ?? [],
                'created_counts' => $realtimeImport['created_counts'] ?? [],
                'unchanged_counts' => $realtimeImport['unchanged_counts'] ?? [],
            ],
            'financial_archive' => [
                'ok' => !empty($financialArchive['ok']),
                'status' => (string)($financialArchive['status'] ?? ''),
                'source_counts' => $financialArchive['source_counts'] ?? [],
                'archive_counts' => $financialArchive['archive_counts'] ?? [],
                'created_counts' => $financialArchive['created_counts'] ?? [],
                'unchanged_counts' => $financialArchive['unchanged_counts'] ?? [],
                'unknown_status_count' => (int)($financialArchive['unknown_status_count'] ?? 0),
            ],
            'runtime_schemas' => [
                'shop_ok' => !empty($schemas['shop']['ok']),
                'payments_ok' => !empty($schemas['payments']['ok']),
                'weekly_bonus_ok' => !empty($schemas['weekly_bonus']['ok']),
            ],
        ];
    }

    private function synchronizeRuntime(array $config, array $snapshot): array
    {
        $router = new RuntimeStorageRouter($config);
        $realtime = (new RuntimeRealtimeRepository($config, $router, $this->database))->synchronize($snapshot);
        $invites = (new RuntimeInviteRepository($config, $router, $this->database))->synchronize($snapshot);

        $notificationUsers = 0;
        $notificationSourceCount = 0;
        $notificationDatabaseCount = 0;
        $notifications = new RuntimeNotificationRepository($config, $router, $this->database);
        foreach ($this->sourceUsers($snapshot) as $legacyUserId) {
            $report = $notifications->synchronizeAndList($snapshot, $legacyUserId);
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $notificationUsers++;
            $notificationSourceCount += (int)($summary['source_count'] ?? 0);
            $notificationDatabaseCount += (int)($summary['database_count'] ?? 0);
        }

        $economy = (new RuntimeEconomyRepository($config, $router, $this->database))->synchronize($snapshot);
        $shop = (new RuntimeShopRepository($config, $router, $this->storage, $this->database))->synchronizeCurrentJson();
        $payments = (new RuntimePaymentRepository($config, $router, $this->storage, $this->database))->synchronizeCurrentJson();
        $weekly = (new RuntimeWeeklyBonusRepository($config, $router, $this->storage, $this->database))->synchronizeCurrentJson();
        $history = (new RuntimeHistoryRepository(
            $config,
            $router,
            $this->database,
            new HistoryService($config, new UserService($config))
        ))->auditParity($snapshot);

        $ok = !empty($realtime['parity'])
            && !empty($invites['parity'])
            && $notificationSourceCount === $notificationDatabaseCount
            && !empty($economy['ok'])
            && !empty($shop['ok'])
            && !empty($payments['ok'])
            && !empty($weekly['ok'])
            && !empty($history['ok']);

        return [
            'ok' => $ok,
            'realtime' => [
                'source_count' => (int)($realtime['games']['source_count'] ?? 0),
                'database_count' => (int)($realtime['games']['database_count'] ?? 0),
                'parity' => !empty($realtime['parity']),
            ],
            'invites' => [
                'source_count' => (int)($invites['source_count'] ?? 0),
                'database_count' => (int)($invites['database_count'] ?? 0),
                'parity' => !empty($invites['parity']),
            ],
            'notifications' => [
                'audited_user_count' => $notificationUsers,
                'source_count' => $notificationSourceCount,
                'database_count' => $notificationDatabaseCount,
                'parity' => $notificationSourceCount === $notificationDatabaseCount,
            ],
            'economy' => [
                'ok' => !empty($economy['ok']),
                'planned_delta_count' => (int)($economy['reconciliation']['planned_delta_count'] ?? 0),
                'integrity_failure_count' => (int)($economy['reconciliation']['integrity_failure_count'] ?? 0),
            ],
            'shop' => $this->compactFinancialAudit($shop, 'source_order_count', 'database_order_count'),
            'payments' => $this->compactFinancialAudit($payments, 'source_payment_count', 'database_payment_count'),
            'weekly_bonus' => $this->compactFinancialAudit($weekly, 'source_user_count', 'database_user_count'),
            'history' => [
                'ok' => !empty($history['ok']),
                'source_user_count' => (int)($history['source_user_count'] ?? 0),
                'mismatch_count' => (int)($history['mismatch_count'] ?? 0),
            ],
        ];
    }

    private function fullRegression(array $config): array
    {
        return (new StagingDatabaseRuntimeRegressionOperation(
            $config,
            $this->storage,
            $this->database
        ))->definition()->execute();
    }

    private function cleanupQueue(): array
    {
        return $this->storage->transaction(function (array &$data): array {
            $queue = is_array($data['queue'] ?? null) ? $data['queue'] : [];
            $removed = count($queue);
            $reset = 0;
            if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
            foreach ($data['users'] as &$user) {
                if (!is_array($user) || (string)($user['status'] ?? '') !== 'searching') continue;
                $user['status'] = 'idle';
                $user['current_game_id'] = null;
                $reset++;
            }
            unset($user);
            $data['queue'] = [];
            return [
                'removed_queue_entries' => $removed,
                'reset_searching_users' => $reset,
                'active_games_untouched' => true,
            ];
        });
    }

    private function inspectDrain(): array
    {
        $snapshot = $this->snapshot();
        $inventory = (new ProductionPreflightService())->inspectSnapshot($snapshot);
        return [
            'active_games' => (int)($inventory['active_games'] ?? 0),
            'queue_entries' => (int)($inventory['queue_entries'] ?? 0),
            'open_invites' => (int)($inventory['open_invites'] ?? 0),
            'searching_users' => (int)($inventory['searching_users'] ?? 0),
            'playing_users' => (int)($inventory['playing_users'] ?? 0),
            'pending_payments' => (int)($inventory['pending_payments'] ?? 0),
            'unknown_payment_statuses' => (int)($inventory['unknown_payment_statuses'] ?? 0),
            'pending_shop_orders' => (int)($inventory['pending_shop_orders'] ?? 0),
            'unknown_shop_order_statuses' => (int)($inventory['unknown_shop_order_statuses'] ?? 0),
        ];
    }

    private function assertDrainReady(array $drain): void
    {
        foreach ($drain as $value) {
            if ((int)$value !== 0) {
                throw new RuntimeException('Production traffic or financial work did not drain to zero.');
            }
        }
    }

    private function assertBackup(array $backup): void
    {
        if (($backup['ok'] ?? false) !== true) {
            throw new RuntimeException('Production cutover backup failed.');
        }
        if ($this->policy->requirePrimaryBackup()
            && trim((string)($backup['backup_id'] ?? '')) === '') {
            throw new RuntimeException('Production cutover primary backup ID is missing.');
        }
        if ($this->policy->requireExternalCopy()
            && ($backup['external_copy']['copied'] ?? false) !== true) {
            throw new RuntimeException('Production cutover external backup copy failed.');
        }
        $primaryHash = strtolower(trim((string)($backup['snapshot_sha256'] ?? '')));
        $externalHash = strtolower(trim((string)($backup['external_copy']['snapshot_sha256'] ?? '')));
        if ($this->policy->requireExternalCopy()
            && ($primaryHash === '' || $externalHash === '' || !hash_equals($primaryHash, $externalHash))) {
            throw new RuntimeException('Production cutover backup copies do not match.');
        }
    }

    private function maintenanceRuntime(array $runtime): array
    {
        $runtime['maintenance_mode'] = true;
        $runtime['maintenance_message'] = 'Идут технические работы. Mini Games World скоро вернётся.';
        $runtime['financial_read_only'] = true;
        $features = is_array($runtime['features'] ?? null) ? $runtime['features'] : [];
        foreach (['matchmaking', 'invitations', 'payments', 'shop'] as $feature) {
            $features[$feature] = false;
        }
        $runtime['features'] = $features;
        $runtime['database_runtime'] = [
            'enabled' => false,
            'modules' => [],
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
        ];
        return $runtime;
    }

    private function activatedRuntime(array $runtime, string $planFingerprint, string $sourceFingerprint): array
    {
        $modules = array_fill_keys(self::MODULES, true);
        $runtime['database_runtime'] = [
            'enabled' => true,
            'production_activated' => true,
            'activation_plan_fingerprint' => $planFingerprint,
            'activation_source_fingerprint' => $sourceFingerprint,
            'activated_at_utc' => $this->nowUtc(),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'modules' => $modules,
        ];
        return $runtime;
    }

    private function releaseMaintenance(array $originalRuntime, array $activatedRuntime): array
    {
        $final = $originalRuntime;
        $final['database_runtime'] = $activatedRuntime['database_runtime'];
        return $final;
    }

    private function assertOriginalRuntimeSafe(array $runtime): void
    {
        $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
        if ($router->enabled()) {
            throw new RuntimeException('Production database runtime is already enabled before cutover.');
        }
        if (is_file($this->writeBlockFile)) {
            throw new RuntimeException('JSON write block is already active before cutover.');
        }
    }

    private function assertMaintenanceActive(array $config): void
    {
        $flags = new FeatureFlagService($config);
        if (!$flags->maintenanceEnabled() || !$flags->financialReadOnly()) {
            throw new RuntimeException('Production maintenance and financial read-only mode did not activate.');
        }
        if ($flags->featureEnabled('matchmaking')
            || $flags->featureEnabled('invitations')
            || $flags->featureEnabled('payments')
            || $flags->featureEnabled('shop')) {
            throw new RuntimeException('Production cutover write-producing features were not disabled.');
        }
    }

    private function activateWriteBlock(string $planFingerprint): void
    {
        $payload = json_encode([
            'state' => 'sealed',
            'environment' => 'production',
            'build' => self::BUILD,
            'plan_fingerprint' => $planFingerprint,
            'activated_at_utc' => $this->nowUtc(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($this->writeBlockFile, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not activate the production JSON write block.');
        }
        @chmod($this->writeBlockFile, 0600);
    }

    private function removeWriteBlock(): void
    {
        if (is_file($this->writeBlockFile) && !@unlink($this->writeBlockFile)) {
            throw new RuntimeException('Could not remove the production JSON write block.');
        }
    }

    private function rollbackInternal(string $reason): array
    {
        $runtimeRestored = false;
        $writeBlockRemoved = false;
        $error = '';

        try {
            $runtimeRestored = is_file($this->runtimeBackupFile)
                ? $this->restoreRuntimeBackup()
                : true;
        } catch (Throwable $restoreError) {
            $error = $this->safeMessage($restoreError->getMessage());
        }
        try {
            $this->removeWriteBlock();
            $writeBlockRemoved = !is_file($this->writeBlockFile);
        } catch (Throwable $blockError) {
            $error = trim($error . '; ' . $this->safeMessage($blockError->getMessage()), '; ');
        }

        $routerDisabled = false;
        try {
            $runtime = $this->readRuntime();
            $routerDisabled = !(new RuntimeStorageRouter($this->configWithRuntime($runtime)))->enabled();
        } catch (Throwable $routerError) {
            $error = trim($error . '; ' . $this->safeMessage($routerError->getMessage()), '; ');
        }

        $ok = $runtimeRestored && $writeBlockRemoved && $routerDisabled;
        $state = [
            'state' => $ok ? 'rolled_back' : 'rollback_failed',
            'build' => self::BUILD,
            'rolled_back_at_utc' => $this->nowUtc(),
            'reason' => mb_substr(trim(preg_replace('/\s+/u', ' ', $reason) ?? ''), 0, 200),
            'runtime_restored' => $runtimeRestored,
            'json_write_block_removed' => $writeBlockRemoved,
            'database_runtime_disabled' => $routerDisabled,
            'error' => $error,
        ];
        try {
            $this->writeState($state);
        } catch (Throwable $stateError) {
            $error = trim($error . '; ' . $this->safeMessage($stateError->getMessage()), '; ');
            $ok = false;
        }

        return [
            'ok' => $ok,
            'action' => 'rollback_to_json',
            'runtime_restored' => $runtimeRestored,
            'json_write_block_removed' => $writeBlockRemoved,
            'database_runtime_disabled' => $routerDisabled,
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'database_rows_preserved_for_analysis' => true,
            'error' => $error,
            'rolled_back_at_utc' => $this->nowUtc(),
        ];
    }

    private function createRuntimeBackup(): void
    {
        if (is_file($this->runtimeBackupFile)) {
            throw new RuntimeException('Production cutover runtime backup already exists.');
        }
        $payload = is_file($this->runtimeFile)
            ? file_get_contents($this->runtimeFile)
            : '__MGW_RUNTIME_ABSENT__';
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Production runtime backup source is unreadable.');
        }
        if (file_put_contents($this->runtimeBackupFile, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not create the production runtime backup.');
        }
        @chmod($this->runtimeBackupFile, 0600);
    }

    private function restoreRuntimeBackup(): bool
    {
        if (!is_file($this->runtimeBackupFile)) return false;
        $payload = file_get_contents($this->runtimeBackupFile);
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Production runtime backup is unreadable.');
        }

        if ($payload === '__MGW_RUNTIME_ABSENT__') {
            if (is_file($this->runtimeFile) && !@unlink($this->runtimeFile)) {
                throw new RuntimeException('Could not remove the production runtime file during rollback.');
            }
            return true;
        }

        $temporary = $this->runtimeFile . '.restore-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not prepare the production runtime rollback.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->runtimeFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish the production runtime rollback.');
        }
        @chmod($this->runtimeFile, 0600);
        return true;
    }

    private function readRuntime(): array
    {
        if (!is_file($this->runtimeFile)) return [];
        $runtime = require $this->runtimeFile;
        if (!is_array($runtime)) {
            throw new RuntimeException('Production runtime config must return an array.');
        }
        return $runtime;
    }

    private function writeRuntime(array $runtime): void
    {
        $temporary = $this->runtimeFile . '.tmp-' . bin2hex(random_bytes(6));
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($runtime, true) . ";\n";
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the production runtime config.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->runtimeFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish the production runtime config.');
        }
        @chmod($this->runtimeFile, 0600);
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $raw = file_get_contents($this->stateFile);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Production cutover state is empty.');
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Production cutover state must be an object.');
        }
        return $decoded;
    }

    private function writeState(array $state): void
    {
        $temporary = $this->stateFile . '.tmp-' . bin2hex(random_bytes(6));
        $encoded = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the production cutover state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->stateFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish the production cutover state.');
        }
        @chmod($this->stateFile, 0600);
    }

    private function snapshot(): array
    {
        $snapshot = $this->storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Production JSON snapshot is unavailable.');
        }
        return $snapshot;
    }

    private function snapshotFingerprint(array $snapshot): string
    {
        return hash('sha256', LedgerIntegrity::canonicalJson($snapshot));
    }

    private function sourceUsers(array $snapshot): array
    {
        $ids = [];
        foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $legacyId = trim((string)($user['id'] ?? $key));
            if ($legacyId !== '') $ids[$legacyId] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);
        return $ids;
    }

    private function configWithRuntime(array $runtime): array
    {
        $config = $this->config;
        $flags = is_array($config['feature_flags'] ?? null) ? $config['feature_flags'] : [];
        $config['feature_flags'] = array_replace_recursive($flags, $runtime);
        return $config;
    }

    private function assertEnvironmentAndBuild(): void
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if ($environment !== 'production') {
            throw new RuntimeException('Controlled production cutover is enabled only in production.');
        }
        if (FeatureFlagService::BUILD !== self::BUILD) {
            throw new RuntimeException('Unexpected application build for controlled production cutover.');
        }
        if ($this->storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Global JSON rollback storage must remain active during production cutover.');
        }
        $migrationStatus = (new MigrationRunner(
            $this->database,
            $this->projectRoot . '/bot/database/migrations'
        ))->status();
        if ((int)($migrationStatus['pending_count'] ?? -1) !== 0) {
            throw new RuntimeException('Production database schema has pending migrations.');
        }
    }

    private function rolledBackNoop(array $state): array
    {
        $runtime = $this->readRuntime();
        $routerEnabled = false;
        $routerError = '';
        try {
            $routerEnabled = (new RuntimeStorageRouter($this->configWithRuntime($runtime)))->enabled();
        } catch (Throwable $error) {
            $routerError = $this->safeMessage($error->getMessage());
        }
        return [
            'ok' => !$routerEnabled && !is_file($this->writeBlockFile) && $routerError === '',
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'rollback_noop',
            'idempotent' => true,
            'state' => 'rolled_back',
            'environment' => 'production',
            'build' => self::BUILD,
            'runtime_route' => RuntimeStorageRouter::DRIVER_JSON,
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'runtime_backup_present' => is_file($this->runtimeBackupFile),
            'json_write_block_active' => is_file($this->writeBlockFile),
            'router_error' => $routerError,
            'state_summary' => $this->compactState($state),
            'manual_review_required' => true,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function completedNoop(array $state): array
    {
        $runtime = $this->readRuntime();
        $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
        return [
            'ok' => $router->enabled() && !is_file($this->writeBlockFile),
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'cutover_noop',
            'idempotent' => true,
            'state' => 'completed',
            'environment' => 'production',
            'build' => self::BUILD,
            'runtime_route' => $router->enabled() ? RuntimeStorageRouter::DRIVER_DATABASE : RuntimeStorageRouter::DRIVER_JSON,
            'enabled_modules' => $router->enabledModules(),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'runtime_backup_present' => is_file($this->runtimeBackupFile),
            'json_write_block_active' => is_file($this->writeBlockFile),
            'state_summary' => $this->compactState($state),
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function compactBackup(array $backup): array
    {
        return [
            'ok' => !empty($backup['ok']),
            'backup_id' => (string)($backup['backup_id'] ?? ''),
            'snapshot_sha256' => (string)($backup['snapshot_sha256'] ?? ''),
            'external_copy' => [
                'copied' => !empty($backup['external_copy']['copied']),
                'snapshot_matches' => isset($backup['external_copy']['snapshot_sha256'])
                    && hash_equals(
                        (string)($backup['snapshot_sha256'] ?? ''),
                        (string)$backup['external_copy']['snapshot_sha256']
                    ),
            ],
        ];
    }

    private function compactImport(array $report, array $integerKeys): array
    {
        $compact = [
            'ok' => !empty($report['ok']),
            'status' => (string)($report['status'] ?? ''),
        ];
        foreach ($integerKeys as $key) $compact[$key] = (int)($report[$key] ?? 0);
        return $compact;
    }

    private function compactShadow(array $report): array
    {
        $sections = [];
        foreach (is_array($report['sections'] ?? null) ? $report['sections'] : [] as $name => $section) {
            if (!is_array($section)) continue;
            $sections[(string)$name] = [
                'source_count' => (int)($section['source_count'] ?? 0),
                'database_count' => (int)($section['database_count'] ?? 0),
                'inserted_count' => (int)($section['inserted_count'] ?? 0),
                'updated_count' => (int)($section['updated_count'] ?? 0),
                'repair_count' => (int)($section['repair_count'] ?? 0),
                'deleted_count' => (int)($section['deleted_count'] ?? 0),
            ];
        }
        return ['ok' => !empty($report['ok']), 'sections' => $sections];
    }

    private function compactFinancialAudit(array $report, string $sourceKey, string $databaseKey): array
    {
        $audit = is_array($report['audit'] ?? null) ? $report['audit'] : $report;
        return [
            'ok' => !empty($report['ok']),
            'source_count' => (int)($audit[$sourceKey] ?? 0),
            'database_count' => (int)($audit[$databaseKey] ?? 0),
            'mismatch_count' => (int)($audit['mismatch_count'] ?? 0),
        ];
    }

    private function compactRegression(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'enabled_modules' => $report['enabled_modules'] ?? [],
            'missing_required_modules' => $report['missing_required_modules'] ?? [],
            'accounts_ok' => !empty($report['accounts']['ok']),
            'realtime_ok' => !empty($report['realtime']['ok']),
            'invites_ok' => !empty($report['invites']['ok']),
            'notifications_ok' => !empty($report['notifications']['ok']),
            'history_ok' => !empty($report['history']['ok']),
            'economy_ok' => !empty($report['economy']['ok']),
            'shop_ok' => !empty($report['shop']['ok']),
            'payments_ok' => !empty($report['payments']['ok']),
            'weekly_bonus_ok' => !empty($report['weekly_bonus']['ok']),
            'blockers' => array_values((array)($report['blockers'] ?? [])),
        ];
    }

    private function compactState(array $state): array
    {
        return [
            'state' => (string)($state['state'] ?? 'not_started'),
            'build' => (string)($state['build'] ?? ''),
            'started_at_utc' => (string)($state['started_at_utc'] ?? ''),
            'completed_at_utc' => (string)($state['completed_at_utc'] ?? ''),
            'rolled_back_at_utc' => (string)($state['rolled_back_at_utc'] ?? ''),
            'plan_fingerprint' => (string)($state['plan_fingerprint'] ?? ''),
            'source_fingerprint' => (string)($state['source_fingerprint'] ?? ''),
            'backup_id' => (string)($state['backup_id'] ?? ''),
            'backup_snapshot_sha256' => (string)($state['backup_snapshot_sha256'] ?? ''),
            'runtime_backup_present' => !empty($state['runtime_backup_present']),
            'database_runtime_published' => !empty($state['database_runtime_published']),
            'json_write_block_active' => !empty($state['json_write_block_active']),
        ];
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
        return mb_substr(trim($message), 0, 500);
    }

    private function isInside(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');
        $parent = rtrim(str_replace('\\', '/', trim($parent)), '/');
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function timestamp(): int
    {
        return $this->now ?? time();
    }

    private function nowUtc(): string
    {
        return gmdate(DATE_ATOM, $this->timestamp());
    }
}
