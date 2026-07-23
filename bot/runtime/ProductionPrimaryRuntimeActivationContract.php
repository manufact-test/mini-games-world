<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConfig.php';

final class ProductionPrimaryRuntimeActivationContract
{
    public const CONTRACT_VERSION = 'v1-production-db-primary-activation';
    public const ACTIVATION_BUILD = 'v103-mvp14-production-cutover';

    private const MAX_STATE_BYTES = 262_144;
    private const MAX_BACKUP_BYTES = 2_097_152;
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
    private string $configFile;
    private string $privateDir;
    private string $dataDir;

    public function __construct(
        string $projectRoot,
        private array $config,
        string $configFile
    ) {
        $this->projectRoot = $this->canonicalDirectory(
            $projectRoot,
            'Production runtime project root is unavailable.'
        );
        $this->configFile = $this->canonicalPrivateFile(
            $configFile,
            'Production runtime private config is unavailable.'
        );
        $this->privateDir = $this->canonicalDirectory(
            dirname($this->configFile),
            'Production runtime private directory is unavailable.'
        );
        if ($this->isInside($this->privateDir, $this->projectRoot)) {
            throw new RuntimeException(
                'Production runtime private directory must remain outside the deployed project.'
            );
        }
        $this->assertDirectoryPrivate($this->privateDir);

        $dataDir = (string)($this->config['data_dir'] ?? '');
        $this->dataDir = $this->canonicalDirectory(
            $dataDir,
            'Production JSON rollback directory is unavailable.'
        );
        if ($this->isInside($this->dataDir, $this->projectRoot)) {
            throw new RuntimeException(
                'Production JSON rollback directory must remain outside the deployed project.'
            );
        }
    }

    public function inspect(): array
    {
        $checks = [];
        $blockers = [];

        $environment = (string)($this->config['environment'] ?? '');
        $checks['environment_exact'] = $environment === 'production';
        if (!$checks['environment_exact']) {
            $blockers[] = 'Production DB-primary activation requires the exact production environment.';
        }

        $storageDriver = (string)($this->config['storage_driver'] ?? 'json');
        $checks['global_json_rollback_driver'] = $storageDriver === 'json';
        if (!$checks['global_json_rollback_driver']) {
            $blockers[] = 'Global JSON rollback storage must remain active during DB-primary activation.';
        }

        $databaseIdentity = '';
        try {
            $database = DatabaseConfig::fromApplicationConfig($this->config);
            $databaseIdentity = $database->identityFingerprint();
            $checks['database_enabled'] = $database->enabled();
            $checks['database_identity_valid'] = preg_match('/\A[a-f0-9]{64}\z/', $databaseIdentity) === 1;
        } catch (Throwable) {
            $checks['database_enabled'] = false;
            $checks['database_identity_valid'] = false;
        }
        if (!$checks['database_enabled'] || !$checks['database_identity_valid']) {
            $blockers[] = 'Production DB-primary activation requires an enabled database with an exact identity.';
        }

        $flags = $this->config['feature_flags'] ?? [];
        if (!is_array($flags)) {
            $flags = [];
            $blockers[] = 'Production feature flags must be an array.';
        }
        $settings = $flags['database_runtime'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
            $blockers[] = 'Production database runtime settings must be an array.';
        }

        $planFingerprint = $this->exactSha($settings['activation_plan_fingerprint'] ?? null);
        $sourceFingerprint = $this->exactSha($settings['activation_source_fingerprint'] ?? null);
        $activatedAt = $settings['activated_at_utc'] ?? null;
        $modules = $settings['modules'] ?? [];

        $checks['runtime_enabled_exact'] = ($settings['enabled'] ?? null) === true;
        $checks['production_activated_exact'] = ($settings['production_activated'] ?? null) === true;
        $checks['activation_build_exact'] = ($settings['activation_build'] ?? null) === self::ACTIVATION_BUILD;
        $checks['activation_plan_fingerprint_valid'] = $planFingerprint !== '';
        $checks['activation_source_fingerprint_valid'] = $sourceFingerprint !== '';
        $checks['activation_timestamp_exact_utc'] = is_string($activatedAt)
            && $this->validUtcTimestamp($activatedAt);
        $checks['rollback_driver_json'] = ($settings['rollback_driver'] ?? null) === 'json';
        $checks['all_modules_enabled_exact'] = $this->exactModules($modules);

        foreach ([
            'runtime_enabled_exact' => 'Production database runtime is not explicitly enabled.',
            'production_activated_exact' => 'Production activation marker is not explicitly true.',
            'activation_build_exact' => 'Production activation marker belongs to another build.',
            'activation_plan_fingerprint_valid' => 'Production activation plan fingerprint is invalid.',
            'activation_source_fingerprint_valid' => 'Production activation source fingerprint is invalid.',
            'activation_timestamp_exact_utc' => 'Production activation timestamp is invalid.',
            'rollback_driver_json' => 'Production rollback driver must remain JSON.',
            'all_modules_enabled_exact' => 'Production activation requires exactly all nine DB-primary modules.',
        ] as $check => $message) {
            if (($checks[$check] ?? false) !== true) {
                $blockers[] = $message;
            }
        }

        $state = [];
        try {
            $state = $this->readPrivateJson(
                $this->privateDir . '/production-cutover.json',
                self::MAX_STATE_BYTES,
                'Production cutover state'
            );
            $checks['cutover_state_private'] = true;
        } catch (Throwable $error) {
            $checks['cutover_state_private'] = false;
            $blockers[] = $error->getMessage();
        }

        $stateName = is_string($state['state'] ?? null) ? $state['state'] : '';
        $statePlan = $this->exactSha($state['plan_fingerprint'] ?? null);
        $stateSource = $this->exactSha($state['source_fingerprint'] ?? null);
        $stateWriteBlock = $state['json_write_block_active'] ?? null;

        $checks['cutover_state_allowed'] = in_array($stateName, ['awaiting_release', 'completed'], true);
        $checks['cutover_build_exact'] = ($state['build'] ?? null) === self::ACTIVATION_BUILD;
        $checks['cutover_plan_matches_runtime'] = $planFingerprint !== ''
            && $statePlan !== ''
            && hash_equals($planFingerprint, $statePlan);
        $checks['cutover_source_matches_runtime'] = $sourceFingerprint !== ''
            && $stateSource !== ''
            && hash_equals($sourceFingerprint, $stateSource);
        $checks['runtime_backup_recorded'] = ($state['runtime_backup_present'] ?? null) === true;
        $checks['database_route_published'] = ($state['database_runtime_published'] ?? null) === true;
        $checks['cutover_rollback_driver_json'] = ($state['rollback_driver'] ?? null) === 'json';
        $checks['cutover_write_block_boolean'] = is_bool($stateWriteBlock);

        foreach ([
            'cutover_state_allowed' => 'Production cutover is not in an activation-safe state.',
            'cutover_build_exact' => 'Production cutover state belongs to another build.',
            'cutover_plan_matches_runtime' => 'Production cutover plan fingerprint does not match runtime.',
            'cutover_source_matches_runtime' => 'Production cutover source fingerprint does not match runtime.',
            'runtime_backup_recorded' => 'Production cutover state does not record an exact runtime backup.',
            'database_route_published' => 'Production cutover state does not confirm the DB route publication.',
            'cutover_rollback_driver_json' => 'Production cutover state does not preserve JSON rollback.',
            'cutover_write_block_boolean' => 'Production cutover write-block state must be an exact boolean.',
        ] as $check => $message) {
            if (($checks[$check] ?? false) !== true) {
                $blockers[] = $message;
            }
        }

        try {
            $this->readPrivatePayload(
                $this->privateDir . '/production-cutover.runtime.backup',
                self::MAX_BACKUP_BYTES,
                'Production runtime backup'
            );
            $checks['runtime_backup_private'] = true;
        } catch (Throwable $error) {
            $checks['runtime_backup_private'] = false;
            $blockers[] = $error->getMessage();
        }

        $maintenance = ($flags['maintenance_mode'] ?? false) === true;
        $financialReadOnly = ($flags['financial_read_only'] ?? false) === true;
        $writeBlockPath = $this->dataDir . '/.cutover-write-block';

        if ($stateName === 'awaiting_release') {
            $checks['awaiting_release_protected'] = $stateWriteBlock === true
                && ($state['maintenance_active'] ?? null) === true
                && ($state['financial_read_only_active'] ?? null) === true
                && $maintenance
                && $financialReadOnly;
            if (!$checks['awaiting_release_protected']) {
                $blockers[] = 'Awaiting-release production must remain in maintenance, financial read-only and JSON-sealed mode.';
            }

            try {
                $writeBlock = $this->readPrivateJson(
                    $writeBlockPath,
                    self::MAX_STATE_BYTES,
                    'Production JSON write block'
                );
                $checks['write_block_identity_exact'] = ($writeBlock['state'] ?? null) === 'sealed'
                    && ($writeBlock['environment'] ?? null) === 'production'
                    && ($writeBlock['build'] ?? null) === self::ACTIVATION_BUILD
                    && $this->exactSha($writeBlock['plan_fingerprint'] ?? null) !== ''
                    && hash_equals($planFingerprint, (string)$writeBlock['plan_fingerprint']);
            } catch (Throwable $error) {
                $checks['write_block_identity_exact'] = false;
                $blockers[] = $error->getMessage();
            }
            if (!$checks['write_block_identity_exact']) {
                $blockers[] = 'Awaiting-release JSON write block does not match the active production plan.';
            }
        } elseif ($stateName === 'completed') {
            $checks['completed_runtime_released'] = $stateWriteBlock === false
                && !$maintenance
                && !$financialReadOnly;
            if (!$checks['completed_runtime_released']) {
                $blockers[] = 'Completed production activation still reports maintenance, financial read-only or JSON sealing.';
            }
            $checks['write_block_absent'] = !file_exists($writeBlockPath) && !is_link($writeBlockPath);
            if (!$checks['write_block_absent']) {
                $blockers[] = 'Completed production activation still has a JSON write block.';
            }
        }

        ksort($checks, SORT_STRING);
        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));
        sort($blockers, SORT_STRING);

        $fingerprintPayload = [
            'contract_version' => self::CONTRACT_VERSION,
            'activation_build' => self::ACTIVATION_BUILD,
            'state' => $stateName,
            'database_identity_fingerprint' => $databaseIdentity,
            'activation_plan_fingerprint' => $planFingerprint,
            'activation_source_fingerprint' => $sourceFingerprint,
            'checks' => $checks,
            'blockers' => $blockers,
        ];

        return [
            'ready' => $blockers === [],
            'contract_version' => self::CONTRACT_VERSION,
            'activation_build' => self::ACTIVATION_BUILD,
            'state' => $stateName,
            'entrypoints' => ['api', 'webhook'],
            'enabled_modules' => $checks['all_modules_enabled_exact'] ? self::MODULES : [],
            'rollback_driver' => 'json',
            'database_identity_fingerprint' => $databaseIdentity,
            'activation_plan_fingerprint' => $planFingerprint,
            'activation_source_fingerprint' => $sourceFingerprint,
            'json_write_block_active' => $stateWriteBlock === true,
            'checks' => $checks,
            'blockers' => $blockers,
            'contract_fingerprint' => hash('sha256', self::canonicalJson($fingerprintPayload)),
            'application_entrypoints_changed' => false,
            'database_contacted' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function exactModules(mixed $modules): bool
    {
        if (!is_array($modules) || array_is_list($modules)) return false;
        $keys = array_keys($modules);
        sort($keys, SORT_STRING);
        $expected = self::MODULES;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) return false;
        foreach (self::MODULES as $module) {
            if (($modules[$module] ?? null) !== true) return false;
        }
        return true;
    }

    private function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private function validUtcTimestamp(string $value): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/', $value) !== 1) {
            return false;
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return false;
        }
        return $date->getOffset() === 0;
    }

    private function readPrivateJson(string $path, int $maximumBytes, string $label): array
    {
        $raw = $this->readPrivatePayload($path, $maximumBytes, $label);
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label . ' JSON is invalid.', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($label . ' must be a JSON object.');
        }
        return $decoded;
    }

    private function readPrivatePayload(string $path, int $maximumBytes, string $label): string
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException($label . ' is unavailable.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($label . ' must use its exact canonical path.');
        }
        if (dirname($canonical) !== $this->privateDir && dirname($canonical) !== $this->dataDir) {
            throw new RuntimeException($label . ' is outside the approved private directories.');
        }
        clearstatcache(true, $canonical);
        $mode = fileperms($canonical);
        $size = filesize($canonical);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException($label . ' must have exact mode 0600.');
        }
        if (!is_int($size) || $size < 1 || $size > $maximumBytes) {
            throw new RuntimeException($label . ' size is invalid.');
        }
        $raw = file_get_contents($canonical);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException($label . ' could not be read exactly.');
        }
        return $raw;
    }

    private function canonicalPrivateFile(string $path, string $message): string
    {
        $path = $this->exactLinuxPath($path, $message);
        if (is_link($path) || !is_file($path)) throw new RuntimeException($message);
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($message);
        }
        clearstatcache(true, $canonical);
        $mode = fileperms($canonical);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Production runtime private config must have exact mode 0600.');
        }
        return $canonical;
    }

    private function canonicalDirectory(string $path, string $message): string
    {
        $path = $this->exactLinuxPath($path, $message);
        if (is_link($path) || !is_dir($path)) throw new RuntimeException($message);
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($message);
        }
        return $canonical;
    }

    private function exactLinuxPath(string $path, string $message): string
    {
        if ($path === ''
            || str_contains($path, '\\')
            || !str_starts_with($path, '/')
            || ($path !== '/' && str_ends_with($path, '/'))) {
            throw new RuntimeException($message);
        }
        return $path;
    }

    private function assertDirectoryPrivate(string $directory): void
    {
        clearstatcache(true, $directory);
        $mode = fileperms($directory);
        if (!is_int($mode) || ($mode & 0022) !== 0) {
            throw new RuntimeException(
                'Production runtime private directory must not be group/world writable.'
            );
        }
    }

    private function isInside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
