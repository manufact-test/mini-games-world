<?php
declare(strict_types=1);

final class ProductionPreflightRunner
{
    private string $projectRoot;
    private ?string $configFile;

    public function __construct(
        string $projectRoot,
        private array $config,
        ?string $configFile = null,
        private ?int $now = null
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        $this->configFile = $configFile !== null && trim($configFile) !== ''
            ? str_replace('\\', '/', trim($configFile))
            : null;
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Production preflight project root is unavailable.');
        }
    }

    public function run(): array
    {
        $environmentValue = $this->config['environment'] ?? 'production';
        $environment = strtolower(trim($environmentValue instanceof BackedEnum
            ? (string)$environmentValue->value
            : (string)$environmentValue));
        if ($environment !== 'production') {
            throw new RuntimeException('Production preflight is enabled only in production.');
        }
        if (!in_array(FeatureFlagService::BUILD, [
            'v102-mvp14-production-preflight',
            'v103-mvp14-production-cutover',
        ], true)) {
            throw new RuntimeException('Unexpected application build for production preflight.');
        }

        $privateDir = $this->configFile !== null
            ? dirname($this->configFile)
            : dirname($this->projectRoot) . '/_private_mgw';
        $privateDir = rtrim(str_replace('\\', '/', $privateDir), '/');
        if (!is_dir($privateDir) || $this->isInside($privateDir, $this->projectRoot)) {
            throw new RuntimeException('Production private runtime directory is unavailable or unsafe.');
        }

        $lockFile = $privateDir . '/production-preflight.lock';
        $lockHandle = fopen($lockFile, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another production preflight is already running.');
        }
        @chmod($lockFile, 0600);

        try {
            $storage = StorageFactory::create($this->config);
            $snapshot = $storage->readOnly(static fn(array $data): array => $data);
            if (!is_array($snapshot)) throw new RuntimeException('Production JSON snapshot is unavailable.');

            $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
            $databaseConnected = false;
            $migrationStatus = [
                'available_count' => 0,
                'applied_count' => 0,
                'pending_count' => -1,
                'pending' => [],
            ];
            $databaseError = '';
            if ($databaseConfig->enabled()) {
                try {
                    $database = PdoConnectionFactory::create($databaseConfig);
                    $databaseConnected = (int)$database->fetchValue('SELECT 1') === 1;
                    $migrationStatus = (new MigrationRunner(
                        $database,
                        $this->projectRoot . '/bot/database/migrations'
                    ))->status();
                } catch (Throwable $error) {
                    $databaseError = $this->safeMessage($error->getMessage());
                }
            }

            $preflightSettings = is_array($this->config['production_preflight'] ?? null)
                ? $this->config['production_preflight']
                : [];
            $maxBackupAge = filter_var(
                $preflightSettings['max_backup_age_seconds'] ?? 108000,
                FILTER_VALIDATE_INT
            );
            if ($maxBackupAge === false || $maxBackupAge < 3600) $maxBackupAge = 108000;

            $backups = [
                'primary' => [
                    'ok' => false,
                    'source' => 'primary',
                    'fresh' => false,
                    'error' => 'backup configuration was not loaded',
                ],
                'external' => [
                    'ok' => false,
                    'source' => 'external',
                    'fresh' => false,
                    'error' => 'backup configuration was not loaded',
                ],
                'require_external_copy' => true,
            ];
            try {
                $backupSettings = BackupConfigLoader::load($this->projectRoot, $environment);
                $backupManager = new BackupManager(
                    $this->projectRoot,
                    (string)($this->config['data_dir'] ?? ''),
                    (string)$backupSettings['backup_root'],
                    isset($backupSettings['external_dir']) ? (string)$backupSettings['external_dir'] : null,
                    (int)$backupSettings['retention_days'],
                    (int)$backupSettings['retention_count'],
                    (bool)$backupSettings['include_release_files']
                );
                $backups = [
                    'primary' => $this->verifyLatestBackup(
                        $backupManager,
                        (string)$backupSettings['backup_root'],
                        'primary',
                        (int)$maxBackupAge
                    ),
                    'external' => $this->verifyLatestBackup(
                        $backupManager,
                        isset($backupSettings['external_dir']) ? (string)$backupSettings['external_dir'] : null,
                        'external',
                        (int)$maxBackupAge
                    ),
                    'require_external_copy' => (bool)$backupSettings['require_external_copy'],
                ];
            } catch (Throwable $error) {
                $message = $this->safeMessage($error->getMessage());
                $backups['primary']['error'] = $message;
                $backups['external']['error'] = $message;
            }

            $runtimeFlags = is_array($this->config['feature_flags'] ?? null)
                ? $this->config['feature_flags']
                : [];
            $databaseRuntime = is_array($runtimeFlags['database_runtime'] ?? null)
                ? $runtimeFlags['database_runtime']
                : [];
            $requestedModules = [];
            foreach (is_array($databaseRuntime['modules'] ?? null) ? $databaseRuntime['modules'] : [] as $module => $enabled) {
                if ($this->boolValue($enabled)) $requestedModules[] = (string)$module;
            }
            sort($requestedModules, SORT_STRING);

            $dataDir = rtrim((string)($this->config['data_dir'] ?? ''), '/\\');
            $runtimeFile = $privateDir . '/runtime.php';
            $controlFile = $privateDir . '/cutover-rehearsal.json';
            [$controlState, $controlActive] = $this->controlState($controlFile);

            $flags = new FeatureFlagService($this->config);
            $runtime = [
                'environment' => $environment,
                'build' => FeatureFlagService::BUILD,
                'storage_driver' => $storage->driver(),
                'database_enabled' => $databaseConfig->enabled(),
                'database_connected' => $databaseConnected,
                'database_error' => $databaseError,
                'schema_current' => $databaseConnected
                    && (int)($migrationStatus['pending_count'] ?? -1) === 0,
                'available_migrations' => (int)($migrationStatus['available_count'] ?? 0),
                'applied_migrations' => (int)($migrationStatus['applied_count'] ?? 0),
                'pending_migrations' => (int)($migrationStatus['pending_count'] ?? -1),
                'migration_plan_fingerprint' => ManagedMigrationController::fingerprint(
                    is_array($migrationStatus['pending'] ?? null) ? $migrationStatus['pending'] : []
                ),
                'database' => $databaseConfig->safeSummary(),
                'database_runtime_requested' => $this->boolValue($databaseRuntime['enabled'] ?? false),
                'database_runtime_requested_modules' => $requestedModules,
                'maintenance_enabled' => $flags->maintenanceEnabled(),
                'financial_read_only' => $flags->financialReadOnly(),
                'data_directory_readable' => $dataDir !== '' && is_dir($dataDir) && is_readable($dataDir),
                'data_directory_writable' => $dataDir !== '' && is_dir($dataDir) && is_writable($dataDir),
                'private_config_loaded' => $this->configFile !== null
                    && is_file($this->configFile)
                    && !$this->isInside($this->configFile, $this->projectRoot),
                'runtime_file_readable' => is_file($runtimeFile) && is_readable($runtimeFile),
                'runtime_file_writable' => is_file($runtimeFile) && is_writable($runtimeFile),
                'cutover_control_state' => $controlState,
                'cutover_control_active' => $controlActive,
                'json_write_block_active' => $dataDir !== '' && is_file($dataDir . '/.cutover-write-block'),
            ];

            $service = new ProductionPreflightService();
            $inventory = $service->inspectSnapshot($snapshot);
            $rollback = [
                'restore_utility_present' => is_file($this->projectRoot . '/ops/backup/restore.php'),
                'verify_utility_present' => is_file($this->projectRoot . '/ops/backup/verify.php'),
                'runtime_file_restorable' => is_file($runtimeFile)
                    && is_readable($runtimeFile)
                    && is_writable($runtimeFile)
                    && is_writable(dirname($runtimeFile)),
            ];
            $result = $service->evaluate($runtime, $backups, $inventory, $rollback);
            $result['execution_mode'] = 'read-only';
            $result['generated_at_utc'] = gmdate(DATE_ATOM, $this->now ?? time());
            $result['next_step'] = ($result['technical_ready_for_window'] ?? false) === true
                ? 'Agree an exact maintenance window and issue a separate short-lived cutover approval. Do not switch yet.'
                : 'Resolve every blocker and repeat the read-only production preflight.';
            return $result;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function verifyLatestBackup(
        BackupManager $manager,
        ?string $root,
        string $label,
        int $maxAgeSeconds
    ): array {
        if ($root === null || trim($root) === '') {
            return [
                'ok' => false,
                'source' => $label,
                'fresh' => false,
                'error' => 'backup source is not configured',
            ];
        }
        try {
            $snapshot = $manager->latestSnapshot($root);
            $verified = $manager->verify($snapshot);
            $manifest = is_array($verified['manifest'] ?? null) ? $verified['manifest'] : [];
            $createdAt = trim((string)($manifest['created_at_utc'] ?? ''));
            $createdTimestamp = $createdAt !== '' ? strtotime($createdAt) : false;
            $now = $this->now ?? time();
            $ageSeconds = $createdTimestamp === false ? null : max(0, $now - $createdTimestamp);
            return [
                'ok' => true,
                'source' => $label,
                'backup_id' => (string)($verified['backup_id'] ?? ''),
                'snapshot_sha256' => (string)($verified['snapshot_sha256'] ?? ''),
                'created_at_utc' => $createdAt,
                'age_seconds' => $ageSeconds,
                'max_age_seconds' => $maxAgeSeconds,
                'fresh' => $ageSeconds !== null && $ageSeconds <= $maxAgeSeconds,
                'environment' => (string)($manifest['environment'] ?? ''),
                'build' => (string)($manifest['build'] ?? ''),
                'verified_files' => (int)($verified['verified_files'] ?? 0),
                'verified_bytes' => (int)($verified['verified_bytes'] ?? 0),
            ];
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'source' => $label,
                'fresh' => false,
                'error' => $this->safeMessage($error->getMessage()),
            ];
        }
    }

    private function controlState(string $controlFile): array
    {
        if (!is_file($controlFile)) return ['absent', false];
        try {
            $decoded = json_decode((string)file_get_contents($controlFile), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('control root is invalid');
            $state = strtolower(trim((string)($decoded['state'] ?? 'unknown')));
            return [$state, in_array($state, ['frozen', 'sealed'], true)];
        } catch (Throwable) {
            return ['invalid', true];
        }
    }

    private function isInside(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');
        $parent = rtrim(str_replace('\\', '/', trim($parent)), '/');
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
        return mb_substr(trim($message), 0, 500);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (!is_string($value)) return false;
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
