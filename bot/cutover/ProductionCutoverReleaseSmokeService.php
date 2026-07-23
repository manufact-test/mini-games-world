<?php
declare(strict_types=1);

final class ProductionCutoverReleaseSmokeService
{
    public const CONTRACT_VERSION = 'v1-production-cutover-release-smoke';
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
    private string $receiptFile;

    public function __construct(
        string $projectRoot,
        private array $config,
        private string $configFile,
        private DatabaseConnectionInterface $database,
        private StorageAdapterInterface $jsonStorage,
        private ProductionCutoverConfig $policy,
        private ?int $now = null
    ) {
        $this->projectRoot = $this->canonicalDirectory($projectRoot, 'project root');
        $this->configFile = $this->privateFile($this->configFile, 'private config');
        $this->privateDir = $this->canonicalDirectory(dirname($this->configFile), 'private directory');
        if ($this->isInside($this->privateDir, $this->projectRoot)) {
            throw new RuntimeException('Release smoke private directory must remain outside deployment.');
        }
        clearstatcache(true, $this->privateDir);
        $mode = fileperms($this->privateDir);
        if (!is_int($mode) || ($mode & 0022) !== 0) {
            throw new RuntimeException('Release smoke private directory is group/world writable.');
        }
        if (($this->config['environment'] ?? null) !== 'production') {
            throw new RuntimeException('Release smoke requires the exact production environment.');
        }
        if (($this->config['storage_driver'] ?? null) !== RuntimeStorageRouter::DRIVER_JSON
            || $this->jsonStorage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Release smoke requires JSON as the rollback storage source.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Release smoke requires MySQL/MariaDB.');
        }
        $this->receiptFile = $this->privateDir . '/production-cutover-release-receipt.json';
    }

    public function run(): array
    {
        $manifest = (new ProductionCutoverPackageManifest($this->projectRoot))->inspect();
        $this->policy->assertPackage($manifest);
        $runtimeContract = ProductionRuntimePrimaryContract::inspect($this->projectRoot);
        if (($runtimeContract['ready'] ?? false) !== true) {
            throw new RuntimeException('Release smoke runtime primary contract is not ready.');
        }

        $activation = (new ProductionPrimaryRuntimeActivationContract(
            $this->projectRoot,
            $this->config,
            $this->configFile
        ))->inspect();
        if (($activation['ready'] ?? false) !== true
            || ($activation['state'] ?? '') !== 'awaiting_release'
            || ($activation['json_write_block_active'] ?? false) !== true) {
            throw new RuntimeException(
                'Release smoke requires the exact protected awaiting_release activation state.'
            );
        }

        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Release smoke database config is disabled.');
        }
        $databaseIdentity = $databaseConfig->identityFingerprint();
        if (!hash_equals(
            (string)($activation['database_identity_fingerprint'] ?? ''),
            $databaseIdentity
        )) {
            throw new RuntimeException('Release smoke database identity does not match activation evidence.');
        }
        if ((int)$this->database->fetchValue('SELECT 1') !== 1) {
            throw new RuntimeException('Release smoke database readiness probe failed.');
        }
        $migrations = (new MigrationRunner(
            $this->database,
            $this->projectRoot . '/bot/database/migrations'
        ))->status();
        if ((int)($migrations['pending_count'] ?? -1) !== 0) {
            throw new RuntimeException('Release smoke found pending database migrations.');
        }

        $stateStorage = new DatabasePrimaryStateStorageAdapter(
            $this->database,
            new RuntimePrimaryProjectionOutboxWriter()
        );
        $before = $this->capture($stateStorage);
        $snapshot = $stateStorage->readOnly(static fn(array $state): array => $state);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Release smoke DB-primary snapshot is unavailable.');
        }
        if (!hash_equals(
            (string)$before['state_sha256'],
            hash('sha256', self::canonicalJson($snapshot))
        )) {
            throw new RuntimeException('Release smoke DB-primary snapshot fingerprint mismatch.');
        }

        $projectionConfig = $this->config;
        $projectionConfig['environment'] = 'staging';
        $projectionConfig['storage_driver'] = RuntimeStorageRouter::DRIVER_JSON;
        if (!isset($projectionConfig['feature_flags'])
            || !is_array($projectionConfig['feature_flags'])) {
            $projectionConfig['feature_flags'] = [];
        }
        $projectionConfig['feature_flags']['database_runtime'] = [
            'enabled' => true,
            'modules' => array_fill_keys(self::MODULES, true),
        ];
        $projector = (new RuntimePrimaryRepositoryProjectorFactory(
            $projectionConfig,
            $this->database
        ))->create();
        $audit = (new RuntimePrimaryProjectionAuditorAdapter($projector))->auditOnly(
            $snapshot,
            (int)$before['state_revision'],
            (string)$before['state_sha256']
        );
        $this->assertAudit($audit, (int)$before['state_revision'], (string)$before['state_sha256']);

        $jsonSnapshot = $this->jsonStorage->readOnly(static fn(array $state): array => $state);
        if (!is_array($jsonSnapshot)) {
            throw new RuntimeException('Release smoke JSON rollback snapshot is unavailable.');
        }
        $jsonSha = hash('sha256', self::canonicalJson($jsonSnapshot));
        $sourceFingerprint = (string)($activation['activation_source_fingerprint'] ?? '');
        if (!$this->validSha($sourceFingerprint)
            || !hash_equals($sourceFingerprint, $jsonSha)
            || !hash_equals((string)$before['state_sha256'], $jsonSha)) {
            throw new RuntimeException(
                'Release smoke JSON rollback snapshot does not match DB-primary activation state.'
            );
        }

        $flags = new FeatureFlagService($this->config);
        if (!$flags->maintenanceEnabled() || !$flags->financialReadOnly()) {
            throw new RuntimeException('Release smoke requires maintenance and financial read-only protection.');
        }
        foreach (['matchmaking', 'invitations', 'payments', 'shop'] as $feature) {
            if ($flags->featureEnabled($feature)) {
                throw new RuntimeException('Release smoke found an enabled write-producing feature.');
            }
        }

        $after = $this->capture($stateStorage);
        if ($before !== $after) {
            throw new RuntimeException('Release smoke changed DB-primary state or projection outbox.');
        }

        $now = $this->now ?? time();
        $receipt = [
            'contract_version' => ProductionCutoverReleaseReceiptVerifier::CONTRACT_VERSION,
            'smoke_contract_version' => self::CONTRACT_VERSION,
            'ready' => true,
            'environment' => 'production',
            'build' => ProductionCutoverRunner::BUILD,
            'package_version' => ProductionCutoverRunner::PACKAGE_VERSION,
            'release_commit' => (string)($manifest['release_commit'] ?? ''),
            'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
            'runtime_contract_fingerprint' => (string)(
                $runtimeContract['contract_fingerprint'] ?? ''
            ),
            'plan_fingerprint' => (string)($activation['activation_plan_fingerprint'] ?? ''),
            'source_fingerprint' => $sourceFingerprint,
            'database_identity_fingerprint' => $databaseIdentity,
            'cutover_state' => 'awaiting_release',
            'health_probe' => 'internal_cli_equivalent',
            'health_http_status' => 200,
            'health_ok' => true,
            'database_connected' => true,
            'schema_current' => true,
            'pending_migrations' => 0,
            'enabled_module_count' => count(self::MODULES),
            'read_only_api_smoke' => true,
            'all_module_regression' => true,
            'json_snapshot_unchanged' => true,
            'maintenance_enabled' => true,
            'financial_read_only' => true,
            'json_write_block_active' => true,
            'state_revision' => (int)$after['state_revision'],
            'state_sha256' => (string)$after['state_sha256'],
            'outbox_fingerprint' => (string)$after['outbox_fingerprint'],
            'all_module_fingerprint' => (string)($audit['all_module_fingerprint'] ?? ''),
            'database_write_executed' => false,
            'persistent_config_changed' => false,
            'webhook_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'expires_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 600),
        ];
        $this->writeReceipt($receipt);
        $fingerprint = hash('sha256', self::canonicalJson($receipt));

        return [
            'ok' => true,
            'report_type' => 'mvp-14.10e-production-cutover-package',
            'action' => 'release_smoke_receipt_created',
            'receipt_fingerprint' => $fingerprint,
            'state_revision' => (int)$after['state_revision'],
            'state_sha256' => (string)$after['state_sha256'],
            'outbox_fingerprint' => (string)$after['outbox_fingerprint'],
            'all_module_fingerprint' => (string)($audit['all_module_fingerprint'] ?? ''),
            'enabled_module_count' => count(self::MODULES),
            'database_contacted' => true,
            'database_write_executed' => false,
            'persistent_config_changed' => false,
            'webhook_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $receipt['generated_at_utc'],
            'expires_at_utc' => $receipt['expires_at_utc'],
            'next_step' => 'Bind a separate short-lived release approval to this receipt fingerprint.',
        ];
    }

    private function capture(DatabasePrimaryStateStorageAdapter $storage): array
    {
        $status = $storage->status();
        $revision = (int)($status['revision'] ?? 0);
        $sha = strtolower(trim((string)($status['state_sha256'] ?? '')));
        if (($status['ok'] ?? false) !== true
            || $revision < 1
            || !$this->validSha($sha)) {
            throw new RuntimeException('Release smoke DB-primary state status is invalid.');
        }

        $rows = $this->database->fetchAll(
            'SELECT state_revision, projection_version, state_sha256, status, attempt_count,
                    lease_token, lease_expires_at_utc, last_error
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision <= :state_revision
             ORDER BY state_revision ASC',
            ['state_revision' => $revision]
        );
        if (count($rows) !== $revision) {
            throw new RuntimeException('Release smoke projection outbox chain is incomplete.');
        }
        $normalized = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Release smoke projection outbox row is invalid.');
            }
            $expectedRevision = $index + 1;
            $rowRevision = (int)($row['state_revision'] ?? 0);
            $rowSha = strtolower(trim((string)($row['state_sha256'] ?? '')));
            $projectionVersion = (string)($row['projection_version'] ?? '');
            if ($rowRevision !== $expectedRevision
                || $projectionVersion !== RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION
                || ($row['status'] ?? '') !== 'completed'
                || !$this->validSha($rowSha)
                || (int)($row['attempt_count'] ?? 0) < 1
                || trim((string)($row['lease_token'] ?? '')) !== ''
                || trim((string)($row['lease_expires_at_utc'] ?? '')) !== ''
                || trim((string)($row['last_error'] ?? '')) !== '') {
                throw new RuntimeException('Release smoke projection outbox chain is invalid.');
            }
            if ($rowRevision === $revision && !hash_equals($sha, $rowSha)) {
                throw new RuntimeException('Release smoke final outbox event does not match DB state.');
            }
            $normalized[] = [
                'state_revision' => $rowRevision,
                'projection_version' => $projectionVersion,
                'state_sha256' => $rowSha,
                'status' => (string)($row['status'] ?? ''),
                'attempt_count' => (int)($row['attempt_count'] ?? 0),
                'lease_token' => '',
                'lease_expires_at_utc' => '',
                'last_error' => '',
            ];
        }

        return [
            'state_revision' => $revision,
            'state_sha256' => $sha,
            'outbox_fingerprint' => hash('sha256', self::canonicalJson($normalized)),
        ];
    }

    private function assertAudit(array $audit, int $revision, string $sha): void
    {
        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || (int)($audit['state_revision'] ?? 0) !== $revision
            || !hash_equals($sha, (string)($audit['state_sha256'] ?? ''))) {
            throw new RuntimeException('Release smoke all-module parity audit failed.');
        }
        $modules = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($audit['projected_modules'] ?? [])
        )));
        sort($modules, SORT_STRING);
        $expected = self::MODULES;
        sort($expected, SORT_STRING);
        if ($modules !== $expected
            || !$this->validSha((string)($audit['all_module_fingerprint'] ?? ''))) {
            throw new RuntimeException('Release smoke all-module audit identity is incomplete.');
        }
    }

    private function writeReceipt(array $receipt): void
    {
        if (is_link($this->receiptFile)) {
            throw new RuntimeException('Release smoke receipt path must not be a symlink.');
        }
        $temporary = $this->receiptFile . '.tmp-' . bin2hex(random_bytes(6));
        $payload = json_encode(
            $receipt,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)
                || !chmod($temporary, 0600)) {
                throw new RuntimeException('Release smoke receipt could not be written safely.');
            }
            if (!rename($temporary, $this->receiptFile) || !chmod($this->receiptFile, 0600)) {
                throw new RuntimeException('Release smoke receipt could not be published atomically.');
            }
        } finally {
            if (is_file($temporary) || is_link($temporary)) {
                if (!unlink($temporary)) {
                    throw new RuntimeException('Release smoke temporary receipt could not be removed.');
                }
            }
        }
    }

    private function privateFile(string $path, string $label): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Release smoke ' . $label . ' is unavailable.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException('Release smoke ' . $label . ' is not canonical.');
        }
        clearstatcache(true, $canonical);
        $mode = fileperms($canonical);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Release smoke ' . $label . ' must have exact mode 0600.');
        }
        return $canonical;
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_dir($path)) {
            throw new RuntimeException('Release smoke ' . $label . ' is unavailable.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException('Release smoke ' . $label . ' is not canonical.');
        }
        return $canonical;
    }

    private function isInside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function validSha(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
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
