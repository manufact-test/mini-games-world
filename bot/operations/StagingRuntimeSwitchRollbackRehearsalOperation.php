<?php
declare(strict_types=1);

final class StagingRuntimeSwitchRollbackRehearsalOperation
{
    private const BUILD = 'v101-mvp14-db-switch-rollback-rehearsal';
    private const OPERATION_ID = 'mvp-14.8.4k-switch-rollback-rehearsal-v1';
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

    private string $runtimeFile;
    private string $controlFile;
    private string $backupFile;

    public function __construct(
        private array $config,
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        string $privateDir
    ) {
        $privateDir = rtrim(trim($privateDir), '/\\');
        if ($privateDir === '') {
            throw new InvalidArgumentException('Switch rehearsal private directory is required.');
        }
        $this->runtimeFile = $privateDir . '/runtime.php';
        $this->controlFile = $privateDir . '/cutover-rehearsal.json';
        $this->backupFile = $privateDir . '/switch-rollback-rehearsal.runtime.backup';
    }

    public function definition(): StagingOperationDefinition
    {
        return new StagingOperationDefinition(
            self::OPERATION_ID,
            self::BUILD,
            fn(): array => $this->run(),
            fn(?array $result, ?Throwable $error): array => $this->rollback($result, $error)
        );
    }

    private function run(): array
    {
        $this->assertEnvironment();
        if ($this->storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Global JSON rollback storage must remain active during the rehearsal.');
        }

        $recoveredInterrupted = false;
        if (is_file($this->backupFile)) {
            $recovery = $this->rollback(null, new RuntimeException('Recovered interrupted rehearsal.'));
            if (empty($recovery['ok'])) {
                throw new RuntimeException('Interrupted switch rehearsal could not be recovered safely.');
            }
            $recoveredInterrupted = true;
        }

        $originalRuntime = $this->readRuntime();
        $originalRouter = new RuntimeStorageRouter($this->config);
        $enabledModules = $originalRouter->enabledModules();
        $missingModules = array_values(array_diff(self::MODULES, $enabledModules));
        if (!$originalRouter->enabled() || $missingModules !== []) {
            throw new RuntimeException('All staged DB runtime modules must be enabled before switch rehearsal.');
        }

        $this->createBackup();
        $freezeService = new FreezeDrainRehearsalService(
            $this->config,
            $this->storage,
            $this->controlFile,
            $originalRouter
        );
        $freeze = $freezeService->freeze();
        if (empty($freeze['drain']['ready'])) {
            throw new RuntimeException('Staging traffic did not drain to zero before switch rehearsal.');
        }

        $sealService = new SealedSnapshotControlService(
            $this->config,
            $this->storage,
            $this->controlFile
        );
        $seal = $sealService->seal();
        if (empty($seal['freeze']['sealed']) || empty($seal['freeze']['storage_write_block_active'])) {
            throw new RuntimeException('Frozen JSON snapshot could not be sealed.');
        }

        $snapshot = $this->snapshot();
        $sealedFingerprint = $this->snapshotFingerprint($snapshot);
        $finalSynchronization = $this->synchronizeAll($this->config, $snapshot);
        if (empty($finalSynchronization['ok'])) {
            throw new RuntimeException('Final DB synchronization failed before switch rehearsal.');
        }

        $databaseBeforeRollback = $this->fullRegression($this->config);
        if (empty($databaseBeforeRollback['ok']) || !empty($databaseBeforeRollback['blockers'])) {
            throw new RuntimeException('DB runtime regression failed before rollback rehearsal.');
        }

        $rollbackRuntime = $originalRuntime;
        if (!isset($rollbackRuntime['database_runtime']) || !is_array($rollbackRuntime['database_runtime'])) {
            $rollbackRuntime['database_runtime'] = [];
        }
        $rollbackRuntime['database_runtime']['enabled'] = false;
        $this->writeRuntime($rollbackRuntime);

        $rollbackConfig = $this->configWithRuntime($rollbackRuntime);
        $jsonRollbackSmoke = $this->jsonRollbackSmoke($rollbackConfig, $sealedFingerprint);
        if (empty($jsonRollbackSmoke['ok'])) {
            throw new RuntimeException('JSON rollback route smoke failed.');
        }

        if (!$this->restoreBackup(false)) {
            throw new RuntimeException('Private runtime configuration backup could not be restored.');
        }
        $restoredRuntime = $this->readRuntime();
        if (!hash_equals($this->fingerprint($originalRuntime), $this->fingerprint($restoredRuntime))) {
            throw new RuntimeException('Private runtime configuration was not restored exactly.');
        }

        $databaseAfterRestore = $this->fullRegression($this->config);
        if (empty($databaseAfterRestore['ok']) || !empty($databaseAfterRestore['blockers'])) {
            throw new RuntimeException('DB runtime regression failed after restoring the DB route.');
        }

        $release = $sealService->release('staging DB switch and JSON rollback rehearsal completed');
        if (!empty($release['freeze']['storage_write_block_active'])) {
            throw new RuntimeException('JSON write block remained active after rehearsal release.');
        }

        $releasedFingerprint = $this->snapshotFingerprint($this->snapshot());
        if (!hash_equals($sealedFingerprint, $releasedFingerprint)) {
            throw new RuntimeException('JSON rollback snapshot changed during the sealed rehearsal.');
        }

        $this->deleteFile($this->backupFile);

        return [
            'ok' => true,
            'operation_type' => 'db_switch_json_rollback_rehearsal',
            'recovered_interrupted' => $recoveredInterrupted,
            'storage_driver' => $this->storage->driver(),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'initial_db_modules' => $enabledModules,
            'missing_required_modules' => $missingModules,
            'freeze' => [
                'active' => !empty($freeze['freeze']['active']),
                'drain_ready' => !empty($freeze['drain']['ready']),
                'active_games' => (int)($freeze['drain']['active_games'] ?? 0),
                'queue_entries' => (int)($freeze['drain']['queue_entries'] ?? 0),
                'open_invites' => (int)($freeze['drain']['open_invites'] ?? 0),
            ],
            'seal' => [
                'active' => !empty($seal['freeze']['sealed']),
                'write_block_active_during_rehearsal' => !empty($seal['freeze']['storage_write_block_active']),
                'snapshot_fingerprint' => $sealedFingerprint,
            ],
            'final_synchronization' => $finalSynchronization,
            'database_before_rollback' => $this->compactRegression($databaseBeforeRollback),
            'json_rollback' => $jsonRollbackSmoke,
            'database_after_restore' => $this->compactRegression($databaseAfterRestore),
            'release' => [
                'write_block_removed' => empty($release['freeze']['storage_write_block_active']),
                'snapshot_unchanged' => hash_equals($sealedFingerprint, $releasedFingerprint),
            ],
            'final_route' => 'database_modules',
            'runtime_restored_exactly' => true,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'blockers' => [],
        ];
    }

    private function synchronizeAll(array $config, array $snapshot): array
    {
        $router = new RuntimeStorageRouter($config);
        $realtimeShadow = (new LegacyRealtimeShadowSyncService($this->storage, $this->database))->run();
        $economyShadow = (new LegacyEconomyShadowSyncService($this->storage, $this->database))->run();
        $realtime = (new RuntimeRealtimeRepository($config, $router, $this->database))->synchronize($snapshot);
        $invites = (new RuntimeInviteRepository($config, $router, $this->database))->synchronize($snapshot);

        $notificationSourceCount = 0;
        $notificationDatabaseCount = 0;
        $notificationUsers = 0;
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

        return [
            'ok' => !empty($realtimeShadow['ok'])
                && !empty($economyShadow['ok'])
                && !empty($realtime['parity'])
                && !empty($invites['parity'])
                && !empty($economy['ok'])
                && !empty($shop['ok'])
                && !empty($payments['ok'])
                && !empty($weekly['ok'])
                && !empty($history['ok']),
            'realtime' => [
                'source_count' => (int)($realtime['games']['source_count'] ?? 0),
                'database_count' => (int)($realtime['games']['database_count'] ?? 0),
                'source_fingerprint' => (string)($realtime['source_fingerprint'] ?? ''),
                'database_fingerprint' => (string)($realtime['database_fingerprint'] ?? ''),
            ],
            'invites' => [
                'source_count' => (int)($invites['source_count'] ?? 0),
                'database_count' => (int)($invites['database_count'] ?? 0),
            ],
            'notifications' => [
                'audited_user_count' => $notificationUsers,
                'source_count' => $notificationSourceCount,
                'database_count' => $notificationDatabaseCount,
            ],
            'economy' => [
                'source_user_count' => (int)($economy['reconciliation']['source_user_count'] ?? 0),
                'source_asset_count' => (int)($economy['reconciliation']['source_asset_count'] ?? 0),
                'planned_delta_count' => (int)($economy['reconciliation']['planned_delta_count'] ?? 0),
                'integrity_failure_count' => (int)($economy['reconciliation']['integrity_failure_count'] ?? 0),
            ],
            'shop' => [
                'source_count' => (int)($shop['audit']['source_order_count'] ?? 0),
                'database_count' => (int)($shop['audit']['database_order_count'] ?? 0),
                'mismatch_count' => (int)($shop['audit']['mismatch_count'] ?? 0),
            ],
            'payments' => [
                'source_count' => (int)($payments['audit']['source_payment_count'] ?? 0),
                'database_count' => (int)($payments['audit']['database_payment_count'] ?? 0),
                'mismatch_count' => (int)($payments['audit']['mismatch_count'] ?? 0),
            ],
            'weekly_bonus' => [
                'source_count' => (int)($weekly['audit']['source_user_count'] ?? 0),
                'database_count' => (int)($weekly['audit']['database_user_count'] ?? 0),
                'mismatch_count' => (int)($weekly['audit']['mismatch_count'] ?? 0),
            ],
            'history' => [
                'source_user_count' => (int)($history['source_user_count'] ?? 0),
                'mismatch_count' => (int)($history['mismatch_count'] ?? 0),
                'json_fingerprint' => (string)($history['json_history_fingerprint'] ?? ''),
                'database_fingerprint' => (string)($history['database_history_fingerprint'] ?? ''),
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

    private function jsonRollbackSmoke(array $config, string $expectedFingerprint): array
    {
        $router = new RuntimeStorageRouter($config);
        $routes = [];
        foreach (self::MODULES as $module) {
            $routes[$module] = $router->routeFor($module);
        }
        $allJson = count(array_filter(
            $routes,
            static fn(string $driver): bool => $driver !== RuntimeStorageRouter::DRIVER_JSON
        )) === 0;

        $snapshot = $this->snapshot();
        $fingerprint = $this->snapshotFingerprint($snapshot);
        $serviceSmoke = [
            'user_present' => false,
            'history_read' => false,
            'weekly_status_read' => false,
            'shop_status_read' => false,
            'payment_history_read' => false,
            'stats_read' => false,
        ];

        foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $legacyUserId = trim((string)($user['id'] ?? $key));
            if ($legacyUserId === '') continue;
            $users = new UserService($config);
            $history = (new HistoryService($config, $users))->userHistory($snapshot, $legacyUserId, 24);
            $weekly = (new WeeklyMatchEconomyService($config, new NotificationService()))->status($snapshot, $user);
            $shop = (new ShopService($config, $users))->status($user);
            $payments = (new PaymentService($config, $users))->userTopupHistory($snapshot, $legacyUserId, 20);
            $stats = (new StatsService())->build($snapshot);
            $serviceSmoke = [
                'user_present' => true,
                'history_read' => is_array($history),
                'weekly_status_read' => is_array($weekly),
                'shop_status_read' => is_array($shop),
                'payment_history_read' => is_array($payments),
                'stats_read' => is_array($stats),
            ];
            break;
        }

        $servicesOk = !in_array(false, $serviceSmoke, true);
        return [
            'ok' => !$router->enabled()
                && $allJson
                && hash_equals($expectedFingerprint, $fingerprint)
                && $servicesOk,
            'database_runtime_enabled' => $router->enabled(),
            'all_module_routes_json' => $allJson,
            'routes' => $routes,
            'snapshot_fingerprint' => $fingerprint,
            'snapshot_unchanged' => hash_equals($expectedFingerprint, $fingerprint),
            'service_smoke' => $serviceSmoke,
        ];
    }

    private function compactRegression(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'read_only' => !empty($report['read_only']),
            'enabled_modules' => $report['enabled_modules'] ?? [],
            'missing_required_modules' => $report['missing_required_modules'] ?? [],
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

    private function configWithRuntime(array $runtime): array
    {
        $config = $this->config;
        $featureFlags = is_array($config['feature_flags'] ?? null) ? $config['feature_flags'] : [];
        if (array_key_exists('database_runtime', $runtime)) {
            $featureFlags['database_runtime'] = $runtime['database_runtime'];
        } else {
            unset($featureFlags['database_runtime']);
        }
        $config['feature_flags'] = $featureFlags;
        return $config;
    }

    private function sourceUsers(array $snapshot): array
    {
        $ids = [];
        foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;
            $legacyUserId = trim((string)($user['id'] ?? $key));
            if ($legacyUserId !== '') $ids[$legacyUserId] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);
        return $ids;
    }

    private function snapshot(): array
    {
        $snapshot = $this->storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Could not read JSON rollback snapshot.');
        }
        return $snapshot;
    }

    private function snapshotFingerprint(array $snapshot): string
    {
        return hash('sha256', LedgerIntegrity::canonicalJson($snapshot));
    }

    private function assertEnvironment(): void
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if ($environment !== 'staging') {
            throw new RuntimeException('DB switch rehearsal is allowed only in staging.');
        }
    }

    private function readRuntime(): array
    {
        if (!is_file($this->runtimeFile)) return [];
        $runtime = require $this->runtimeFile;
        if (!is_array($runtime)) {
            throw new RuntimeException('Private runtime config must return an array.');
        }
        return $runtime;
    }

    private function writeRuntime(array $runtime): void
    {
        $temporary = $this->runtimeFile . '.tmp-' . bin2hex(random_bytes(6));
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($runtime, true) . ";\n";
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Could not write private runtime config.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->runtimeFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish private runtime config.');
        }
        @chmod($this->runtimeFile, 0600);
    }

    private function createBackup(): void
    {
        $payload = is_file($this->runtimeFile)
            ? file_get_contents($this->runtimeFile)
            : '__MGW_RUNTIME_ABSENT__';
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Private runtime config backup source is unreadable.');
        }
        if (file_put_contents($this->backupFile, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not create switch rehearsal runtime backup.');
        }
        @chmod($this->backupFile, 0600);
    }

    private function restoreBackup(bool $delete): bool
    {
        if (!is_file($this->backupFile)) return false;
        $payload = file_get_contents($this->backupFile);
        if (!is_string($payload) || $payload === '') return false;

        if ($payload === '__MGW_RUNTIME_ABSENT__') {
            if (is_file($this->runtimeFile) && !@unlink($this->runtimeFile)) return false;
        } else {
            $temporary = $this->runtimeFile . '.restore-' . bin2hex(random_bytes(6));
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) return false;
            @chmod($temporary, 0600);
            if (!rename($temporary, $this->runtimeFile)) {
                @unlink($temporary);
                return false;
            }
            @chmod($this->runtimeFile, 0600);
        }

        if ($delete) $this->deleteFile($this->backupFile);
        return true;
    }

    private function rollback(?array $result, ?Throwable $error): array
    {
        $runtimeRestored = !is_file($this->backupFile) || $this->restoreBackup(true);
        $writeBlockRemoved = false;
        $controlReleased = false;
        try {
            $release = (new SealedSnapshotControlService(
                $this->config,
                $this->storage,
                $this->controlFile
            ))->emergencyRelease('automatic switch rehearsal rollback');
            $writeBlockRemoved = empty($release['freeze']['storage_write_block_active']);
            $controlReleased = in_array((string)($release['freeze']['state'] ?? ''), ['released', 'absent'], true);
        } catch (Throwable) {
            $writeBlockRemoved = !is_file(rtrim((string)($this->config['data_dir'] ?? ''), '/\\') . '/.cutover-write-block');
        }

        return [
            'ok' => $runtimeRestored && $writeBlockRemoved,
            'runtime_restored' => $runtimeRestored,
            'write_block_removed' => $writeBlockRemoved,
            'control_released' => $controlReleased,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function deleteFile(string $path): void
    {
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Could not remove private switch rehearsal file.');
        }
    }

    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
