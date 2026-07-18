<?php
declare(strict_types=1);

final class CutoverReadinessService
{
    private const REQUIRED_PATHS = [
        'bot/storage/contracts/StorageTransactionInterface.php',
        'bot/storage/contracts/StorageAdapterInterface.php',
        'bot/storage/JsonStorageAdapter.php',
        'bot/storage/StorageFactory.php',
        'bot/database/PdoConnectionFactory.php',
        'bot/database/MigrationRunner.php',
        'bot/accounts/AccountIdentityService.php',
        'bot/realtime/RealtimeDatabaseStore.php',
        'bot/ledger/LedgerWriteService.php',
        'bot/migration/StagingJsonDbFinalReconciliationService.php',
        'ops/backup/verify.php',
        'ops/backup/restore.php',
        'ops/migration/staging-json-db-reconciliation.php',
    ];

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Project root must be an existing directory.');
        }
    }

    public function inspectSource(): array
    {
        $required = [];
        foreach (self::REQUIRED_PATHS as $relative) {
            $required[$relative] = is_file($this->projectRoot . '/' . $relative);
        }

        $factoryPath = $this->projectRoot . '/bot/storage/StorageFactory.php';
        $factorySource = is_file($factoryPath) ? (file_get_contents($factoryPath) ?: '') : '';
        $databaseDriverArm = preg_match(
            "/'(?:mysql|mariadb|database|db)'\\s*=>/i",
            $factorySource
        ) === 1;

        $directJsonDatabase = [];
        $explicitJsonFactory = [];
        $legacySnapshotCallbacks = [];

        foreach ($this->phpFiles($this->projectRoot . '/bot') as $path) {
            $relative = $this->relativePath($path);
            if (str_starts_with($relative, 'bot/tests/')) {
                continue;
            }

            $source = file_get_contents($path) ?: '';
            if ($relative !== 'bot/storage/JsonStorageAdapter.php') {
                $directJsonDatabase = array_merge(
                    $directJsonDatabase,
                    $this->occurrences($relative, $source, '/new\\s+JsonDatabase\\s*\\(/')
                );
            }
            if ($relative !== 'bot/storage/StorageFactory.php') {
                $explicitJsonFactory = array_merge(
                    $explicitJsonFactory,
                    $this->occurrences($relative, $source, '/StorageFactory::createJson\\s*\\(/')
                );
            }
            $migrationOnly = str_starts_with($relative, 'bot/migration/')
                || str_starts_with($relative, 'bot/database/')
                || preg_match('#^bot/(?:ledger|realtime)/Legacy#', $relative) === 1;
            if (!$migrationOnly) {
                $legacySnapshotCallbacks = array_merge(
                    $legacySnapshotCallbacks,
                    $this->occurrences(
                        $relative,
                        $source,
                        '/->(?:transaction|readOnly)\\s*\\(\\s*(?:static\\s+)?(?:function|fn)\\s*\\(\\s*array\\b/'
                    )
                );
            }
        }

        $missingRequired = array_keys(array_filter($required, static fn(bool $exists): bool => !$exists));
        $workItems = [];
        if (!$databaseDriverArm) {
            $workItems[] = 'StorageFactory has no DB runtime driver arm.';
        }
        if ($explicitJsonFactory !== []) {
            $workItems[] = 'Runtime call sites still force JsonStorageAdapter through StorageFactory::createJson().';
        }
        if ($legacySnapshotCallbacks !== []) {
            $workItems[] = 'Runtime services still depend on whole-array transaction/readOnly callbacks.';
        }

        $inventory = [
            'scan_scope' => 'bot PHP excluding tests and migration-only services',
            'required_paths' => $required,
            'missing_required_paths' => $missingRequired,
            'storage_factory_has_database_driver' => $databaseDriverArm,
            'direct_json_database_instantiations' => $directJsonDatabase,
            'explicit_json_factory_calls' => $explicitJsonFactory,
            'legacy_array_snapshot_callbacks' => $legacySnapshotCallbacks,
            'runtime_adapter_work_items' => $workItems,
        ];
        $inventory['inventory_fingerprint'] = hash('sha256', $this->canonicalJson($inventory));

        return $inventory;
    }

    public function evaluate(
        array $runtime,
        array $reconciliation,
        array $backups,
        array $sourceInventory
    ): array {
        $blockers = [];
        $environment = strtolower(trim((string)($runtime['environment'] ?? '')));
        $primaryBackup = is_array($backups['primary'] ?? null) ? $backups['primary'] : [];
        $externalBackup = is_array($backups['external'] ?? null) ? $backups['external'] : [];
        $primaryBackupOk = ($primaryBackup['ok'] ?? false) === true;
        $externalBackupOk = ($externalBackup['ok'] ?? false) === true;
        $primaryEnvironmentMatches = $primaryBackupOk
            && strtolower(trim((string)($primaryBackup['environment'] ?? ''))) === $environment;
        $externalEnvironmentMatches = $externalBackupOk
            && strtolower(trim((string)($externalBackup['environment'] ?? ''))) === $environment;
        $sameBackupId = $primaryBackupOk
            && $externalBackupOk
            && trim((string)($primaryBackup['backup_id'] ?? '')) !== ''
            && hash_equals(
                (string)$primaryBackup['backup_id'],
                (string)($externalBackup['backup_id'] ?? '')
            );
        $sameSnapshotHash = $primaryBackupOk
            && $externalBackupOk
            && trim((string)($primaryBackup['snapshot_sha256'] ?? '')) !== ''
            && hash_equals(
                (string)$primaryBackup['snapshot_sha256'],
                (string)($externalBackup['snapshot_sha256'] ?? '')
            );
        $backupPair = [
            'primary_environment_matches_runtime' => $primaryEnvironmentMatches,
            'external_environment_matches_runtime' => $externalEnvironmentMatches,
            'same_backup_id' => $sameBackupId,
            'same_snapshot_sha256' => $sameSnapshotHash,
            'same_verified_snapshot' => $sameBackupId && $sameSnapshotHash,
        ];

        if (!in_array($environment, ['staging', 'local'], true)) {
            $blockers[] = 'environment must be staging or local';
        }
        if (strtolower(trim((string)($runtime['storage_driver'] ?? ''))) !== 'json') {
            $blockers[] = 'JSON must remain the active runtime source during MVP-14.8.1';
        }
        if (empty($runtime['database_enabled'])) {
            $blockers[] = 'database is not enabled';
        }
        if (($runtime['database_connected'] ?? false) !== true) {
            $blockers[] = 'database connection is not healthy';
        }
        if (($runtime['schema_current'] ?? false) !== true || (int)($runtime['pending_migrations'] ?? -1) !== 0) {
            $blockers[] = 'database schema is not current';
        }
        if (($reconciliation['ok'] ?? false) !== true) {
            $blockers[] = 'final JSON to DB reconciliation is not clean';
        }
        if (($reconciliation['count_parity_complete'] ?? false) !== true) {
            $blockers[] = 'JSON to DB count parity is incomplete';
        }
        if (!$primaryBackupOk) {
            $blockers[] = 'latest primary JSON backup did not verify';
        } elseif (!$primaryEnvironmentMatches) {
            $blockers[] = 'primary backup environment does not match runtime environment';
        }
        if (!$externalBackupOk) {
            $blockers[] = 'latest external JSON backup did not verify';
        } elseif (!$externalEnvironmentMatches) {
            $blockers[] = 'external backup environment does not match runtime environment';
        }
        if ($primaryBackupOk && $externalBackupOk && !($sameBackupId && $sameSnapshotHash)) {
            $blockers[] = 'primary and external backups are not the same verified snapshot';
        }
        if (($sourceInventory['missing_required_paths'] ?? []) !== []) {
            $blockers[] = 'required cutover foundations are missing';
        }
        if (($sourceInventory['direct_json_database_instantiations'] ?? []) !== []) {
            $blockers[] = 'direct JsonDatabase construction exists outside JsonStorageAdapter';
        }

        $blockers = array_values(array_unique($blockers));
        $report = [
            'ok' => $blockers === [],
            'ready_for_mvp_14_8_2' => $blockers === [],
            'production_cutover_allowed' => false,
            'production_switch_performed' => false,
            'blockers' => $blockers,
            'runtime_adapter_work_items' => array_values((array)($sourceInventory['runtime_adapter_work_items'] ?? [])),
            'runtime' => $runtime,
            'reconciliation' => [
                'ok' => (bool)($reconciliation['ok'] ?? false),
                'count_parity_complete' => (bool)($reconciliation['count_parity_complete'] ?? false),
                'report_fingerprint' => (string)($reconciliation['report_fingerprint'] ?? ''),
                'blocking_reasons' => array_values((array)($reconciliation['blocking_reasons'] ?? [])),
                'migration_gaps' => array_values((array)($reconciliation['migration_gaps'] ?? [])),
            ],
            'backups' => $backups,
            'backup_pair' => $backupPair,
            'source_inventory' => $sourceInventory,
        ];
        $report['readiness_fingerprint'] = hash('sha256', $this->canonicalJson($report));

        return $report;
    }

    private function phpFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function occurrences(string $relative, string $source, string $pattern): array
    {
        $matches = [];
        if (preg_match_all($pattern, $source, $found, PREG_OFFSET_CAPTURE) !== false) {
            foreach (($found[0] ?? []) as $item) {
                $offset = (int)($item[1] ?? 0);
                $matches[] = [
                    'path' => $relative,
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                ];
            }
        }
        return $matches;
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return ltrim(substr($path, strlen($this->projectRoot)), '/');
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
