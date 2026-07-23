<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackExportService
{
    private const DATA_FILES = [
        'users' => 'users.json',
        'games' => 'games.json',
        'queue' => 'queue.json',
        'transactions' => 'transactions.json',
        'support' => 'support.json',
        'shop_orders' => 'shop_orders.json',
        'payments' => 'payments.json',
        'notifications' => 'notifications.json',
        'invites' => 'invites.json',
        'system' => 'system.json',
    ];

    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private RuntimePrimaryProjectionAuditorInterface $auditor,
        private ProductionPrimaryRollbackExportVerifier $verifier
    ) {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Production rollback export requires MySQL/MariaDB.');
        }
    }

    public function export(
        string $projectRoot,
        string $outputRoot,
        array $gateReport
    ): array {
        $projectRoot = $this->canonicalDirectory($projectRoot, 'Production project root');
        $outputRoot = $this->canonicalDirectory($outputRoot, 'Rollback export root');
        if ($outputRoot === $projectRoot || str_starts_with($outputRoot . '/', $projectRoot . '/')) {
            throw new RuntimeException('Rollback export root must remain outside the deployed project.');
        }
        $this->assertDirectoryMode($outputRoot, 0700, 'Rollback export root');

        if (($gateReport['ready'] ?? false) !== true
            || ($gateReport['contract_version'] ?? null)
                !== ProductionPrimaryRollbackExportGate::CONTRACT_VERSION
            || ($gateReport['activation_build'] ?? null)
                !== ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD) {
            throw new RuntimeException('Production rollback export gate is not ready.');
        }

        $requestId = strtolower(trim((string)($gateReport['request_id'] ?? '')));
        $expectedRevision = filter_var(
            $gateReport['expected_state_revision'] ?? null,
            FILTER_VALIDATE_INT
        );
        $expectedSha = $this->exactSha($gateReport['expected_state_sha256'] ?? null);
        $databaseIdentity = $this->exactSha(
            $gateReport['database_identity_fingerprint'] ?? null
        );
        $plan = $this->exactSha($gateReport['activation_plan_fingerprint'] ?? null);
        $source = $this->exactSha($gateReport['activation_source_fingerprint'] ?? null);
        $expectedOutputRoot = $this->exactSha($gateReport['output_root_fingerprint'] ?? null);
        $reasonFingerprint = $this->exactSha($gateReport['reason_fingerprint'] ?? null);

        if (preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1
            || !is_int($expectedRevision)
            || $expectedRevision < 1
            || $expectedSha === ''
            || $databaseIdentity === ''
            || $plan === ''
            || $source === ''
            || $expectedOutputRoot === ''
            || $reasonFingerprint === '') {
            throw new RuntimeException('Production rollback export gate identity is incomplete.');
        }
        $actualOutputRoot = hash('sha256', $outputRoot);
        if (!hash_equals($expectedOutputRoot, $actualOutputRoot)) {
            throw new RuntimeException('Rollback export root does not match the authorization gate.');
        }

        $lockPath = $outputRoot . '/.rollback-export.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Rollback export lock must not be a symbolic link.');
        }
        $lock = fopen($lockPath, 'c+');
        if (!is_resource($lock)) {
            throw new RuntimeException('Rollback export lock is unavailable.');
        }
        if (!chmod($lockPath, 0600)) {
            fclose($lock);
            throw new RuntimeException('Rollback export lock permissions could not be secured.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another production rollback export is already running.');
        }

        $backupId = 'rollback-' . $requestId;
        $temporaryDir = $outputRoot . '/.partial-' . $backupId;
        $finalDir = $outputRoot . '/' . $backupId;
        if (file_exists($temporaryDir) || file_exists($finalDir)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new RuntimeException('Rollback export request was already used or is incomplete.');
        }

        try {
            $result = $this->database->transaction(function () use (
                $temporaryDir,
                $finalDir,
                $backupId,
                $requestId,
                $expectedRevision,
                $expectedSha,
                $databaseIdentity,
                $plan,
                $source,
                $expectedOutputRoot,
                $reasonFingerprint,
                $gateReport
            ): array {
                $row = $this->lockedStateRow();
                $revision = (int)($row['revision'] ?? 0);
                $stateSha = $this->exactSha($row['state_sha256'] ?? null);
                if ($revision !== $expectedRevision || $stateSha === '') {
                    throw new RuntimeException(
                        'Production runtime state does not match the authorized rollback revision.'
                    );
                }
                if (!hash_equals($expectedSha, $stateSha)) {
                    throw new RuntimeException(
                        'Production runtime state fingerprint does not match rollback authorization.'
                    );
                }

                $snapshot = $this->decodeSnapshot((string)($row['state_json'] ?? ''));
                $actualStateSha = hash('sha256', $this->canonicalJson($snapshot));
                if (!hash_equals($stateSha, $actualStateSha)) {
                    throw new RuntimeException('Production runtime state fingerprint mismatch.');
                }

                $outbox = $this->outboxStatus($revision);
                $audit = $this->auditor->auditOnly($snapshot, $revision, $stateSha);
                $allModuleFingerprint = $this->assertAudit(
                    $audit,
                    $revision,
                    $stateSha
                );

                $this->createPrivateDirectory($temporaryDir);
                $this->createPrivateDirectory($temporaryDir . '/data');

                $createdAt = gmdate(DATE_ATOM);
                $dataBytes = 0;
                foreach (self::DATA_FILES as $key => $file) {
                    $raw = json_encode(
                        $snapshot[$key],
                        JSON_PRETTY_PRINT
                            | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                            | JSON_THROW_ON_ERROR
                    ) . "\n";
                    $this->writePrivateFile($temporaryDir . '/data/' . $file, $raw);
                    $dataBytes += strlen($raw);
                }

                $authorizationFingerprint = hash('sha256', $this->canonicalJson([
                    'contract_version' => ProductionPrimaryRollbackExportGate::CONTRACT_VERSION,
                    'request_id' => $requestId,
                    'expected_state_revision' => $expectedRevision,
                    'expected_state_sha256' => $expectedSha,
                    'database_identity_fingerprint' => $databaseIdentity,
                    'activation_plan_fingerprint' => $plan,
                    'activation_source_fingerprint' => $source,
                    'output_root_fingerprint' => $expectedOutputRoot,
                    'reason_fingerprint' => $reasonFingerprint,
                    'authorization_expires_at_utc' => (string)(
                        $gateReport['authorization_expires_at_utc'] ?? ''
                    ),
                ]));

                $rollback = [
                    'contract_version' => ProductionPrimaryRollbackExportGate::CONTRACT_VERSION,
                    'source' => 'database_primary',
                    'environment' => 'production',
                    'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
                    'request_id' => $requestId,
                    'created_at_utc' => $createdAt,
                    'state_revision' => $revision,
                    'state_sha256' => $stateSha,
                    'database_identity_fingerprint' => $databaseIdentity,
                    'activation_plan_fingerprint' => $plan,
                    'activation_source_fingerprint' => $source,
                    'authorization_fingerprint' => $authorizationFingerprint,
                    'all_module_fingerprint' => $allModuleFingerprint,
                    'outbox' => $outbox,
                    'maintenance_enabled' => true,
                    'financial_read_only' => true,
                    'live_json_changed' => false,
                    'production_config_changed' => false,
                    'database_write_executed' => false,
                    'isolated_restore_required' => true,
                ];
                $rollbackRaw = $this->prettyJson($rollback) . "\n";
                $this->writePrivateFile($temporaryDir . '/rollback.json', $rollbackRaw);

                $checksums = [];
                foreach (self::DATA_FILES as $file) {
                    $relative = 'data/' . $file;
                    $checksums[$relative] = $this->fileSha($temporaryDir . '/' . $relative);
                }
                $checksums['rollback.json'] = $this->fileSha(
                    $temporaryDir . '/rollback.json'
                );
                ksort($checksums, SORT_STRING);
                $checksumLines = [];
                foreach ($checksums as $relative => $sha) {
                    $checksumLines[] = $sha . '  ' . $relative;
                }
                $checksumsRaw = implode("\n", $checksumLines) . "\n";
                $this->writePrivateFile(
                    $temporaryDir . '/checksums.sha256',
                    $checksumsRaw
                );

                $manifest = [
                    'format_version' => ProductionPrimaryRollbackExportVerifier::FORMAT_VERSION,
                    'backup_id' => $backupId,
                    'created_at_utc' => $createdAt,
                    'environment' => 'production',
                    'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
                    'source' => 'database_primary',
                    'snapshot_sha256' => hash('sha256', $checksumsRaw),
                    'data' => [
                        'files' => count(self::DATA_FILES),
                        'bytes' => $dataBytes,
                    ],
                    'release' => [
                        'included' => false,
                        'files' => 0,
                        'bytes' => 0,
                    ],
                    'external_copy_requested' => false,
                    'rollback_export' => [
                        'contract_version' => ProductionPrimaryRollbackExportGate::CONTRACT_VERSION,
                        'state_revision' => $revision,
                        'state_sha256' => $stateSha,
                        'authorization_fingerprint' => $authorizationFingerprint,
                        'all_module_fingerprint' => $allModuleFingerprint,
                        'isolated_restore_required' => true,
                    ],
                ];
                $manifestRaw = $this->prettyJson($manifest) . "\n";
                $this->writePrivateFile($temporaryDir . '/manifest.json', $manifestRaw);

                $complete = [
                    'backup_id' => $backupId,
                    'manifest_sha256' => hash('sha256', $manifestRaw),
                    'completed_at_utc' => gmdate(DATE_ATOM),
                ];
                $this->writePrivateFile(
                    $temporaryDir . '/COMPLETE',
                    $this->prettyJson($complete) . "\n"
                );

                $verifiedTemporary = $this->verifier->verify($temporaryDir);
                if (($verifiedTemporary['state_revision'] ?? 0) !== $revision
                    || ($verifiedTemporary['state_sha256'] ?? '') !== $stateSha) {
                    throw new RuntimeException(
                        'Rollback export temporary verification did not preserve state identity.'
                    );
                }
                if (!rename($temporaryDir, $finalDir)) {
                    throw new RuntimeException('Rollback export could not be finalized.');
                }
                $verifiedFinal = $this->verifier->verify($finalDir);
                if (($verifiedFinal['snapshot_sha256'] ?? '')
                    !== ($verifiedTemporary['snapshot_sha256'] ?? '')) {
                    throw new RuntimeException(
                        'Rollback export identity changed during finalization.'
                    );
                }

                return [
                    'ok' => true,
                    'action' => 'production_db_to_json_rollback_export_created',
                    'backup_id' => $backupId,
                    'request_id' => $requestId,
                    'state_revision' => $revision,
                    'state_sha256' => $stateSha,
                    'snapshot_sha256' => (string)$verifiedFinal['snapshot_sha256'],
                    'all_module_fingerprint' => $allModuleFingerprint,
                    'authorization_fingerprint' => $authorizationFingerprint,
                    'data_files' => count(self::DATA_FILES),
                    'outbox' => $outbox,
                    'state_row_locked' => true,
                    'backup_manager_compatible' => true,
                    'isolated_restore_required' => true,
                    'database_contacted' => true,
                    'database_write_executed' => false,
                    'live_json_changed' => false,
                    'persistent_config_changed' => false,
                    'webhook_changed' => false,
                    'cron_changed' => false,
                    'production_changed' => false,
                    'sensitive_identifiers_exposed' => false,
                ];
            });

            $postCommit = $this->verifier->verify($finalDir);
            if (($postCommit['snapshot_sha256'] ?? '') !== ($result['snapshot_sha256'] ?? '')) {
                throw new RuntimeException('Rollback export post-commit verification failed.');
            }
            return $result;
        } catch (Throwable $error) {
            foreach ([$temporaryDir, $finalDir] as $path) {
                if (is_dir($path)) $this->removeDirectory($path);
            }
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function lockedStateRow(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT singleton_id, revision, state_json, state_sha256,
                    created_at_utc, updated_at_utc
             FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE . '
             WHERE singleton_id = 1 FOR UPDATE'
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Production runtime primary state singleton is unavailable.');
        }
        if ((int)($rows[0]['singleton_id'] ?? 0) !== 1) {
            throw new RuntimeException('Production runtime primary state singleton is invalid.');
        }
        return $rows[0];
    }

    private function decodeSnapshot(string $raw): array
    {
        try {
            $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Production runtime primary state JSON is invalid.', 0, $error);
        }
        if (!is_array($snapshot) || array_is_list($snapshot)) {
            throw new RuntimeException('Production runtime primary state must be an object.');
        }
        $keys = array_keys($snapshot);
        sort($keys, SORT_STRING);
        $expected = array_keys(self::DATA_FILES);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException(
                'Production runtime state cannot be represented by the exact JSON rollback schema.'
            );
        }
        foreach (self::DATA_FILES as $key => $_file) {
            if (!is_array($snapshot[$key] ?? null)) {
                throw new RuntimeException('Production runtime state module is not an array: ' . $key . '.');
            }
        }
        return $snapshot;
    }

    private function outboxStatus(int $revision): array
    {
        $rows = $this->database->fetchAll(
            'SELECT status, COUNT(*) AS event_count,
                    MIN(state_revision) AS min_revision,
                    MAX(state_revision) AS max_revision
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             GROUP BY status ORDER BY status'
        );
        $counts = [
            'completed' => 0,
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
        ];
        $completedMin = 0;
        $completedMax = 0;
        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if (!array_key_exists($status, $counts)) {
                throw new RuntimeException('Production projection outbox status is unsupported.');
            }
            $count = max(0, (int)($row['event_count'] ?? 0));
            $counts[$status] = $count;
            if ($status === 'completed') {
                $completedMin = max(0, (int)($row['min_revision'] ?? 0));
                $completedMax = max(0, (int)($row['max_revision'] ?? 0));
            }
        }
        if ($counts['completed'] !== $revision
            || $completedMin !== 1
            || $completedMax !== $revision
            || $counts['pending'] !== 0
            || $counts['processing'] !== 0
            || $counts['failed'] !== 0) {
            throw new RuntimeException(
                'Production projection outbox is not a contiguous completed revision chain.'
            );
        }
        return [
            'completed_event_count' => $counts['completed'],
            'min_revision' => $completedMin,
            'max_revision' => $completedMax,
            'pending_event_count' => $counts['pending'],
            'processing_event_count' => $counts['processing'],
            'failed_event_count' => $counts['failed'],
        ];
    }

    private function assertAudit(array $audit, int $revision, string $stateSha): string
    {
        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || (int)($audit['state_revision'] ?? 0) !== $revision
            || !hash_equals(
                $stateSha,
                strtolower(trim((string)($audit['state_sha256'] ?? '')))
            )) {
            throw new RuntimeException('Production all-module rollback audit failed.');
        }
        $modules = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($audit['projected_modules'] ?? [])
        )));
        sort($modules, SORT_STRING);
        $expected = self::MODULES;
        sort($expected, SORT_STRING);
        if ($modules !== $expected) {
            throw new RuntimeException('Production rollback audit is missing required modules.');
        }
        $fingerprint = $this->exactSha($audit['all_module_fingerprint'] ?? null);
        if ($fingerprint === '') {
            throw new RuntimeException('Production rollback all-module fingerprint is invalid.');
        }
        return $fingerprint;
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        if ($path === ''
            || str_contains($path, '\\')
            || !str_starts_with($path, '/')
            || ($path !== '/' && str_ends_with($path, '/'))
            || is_link($path)
            || !is_dir($path)) {
            throw new RuntimeException($label . ' must be an exact absolute directory.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($label . ' must use its exact canonical path.');
        }
        return $canonical;
    }

    private function assertDirectoryMode(string $path, int $expected, string $label): void
    {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== $expected) {
            throw new RuntimeException($label . ' must have exact mode ' . sprintf('%04o', $expected) . '.');
        }
    }

    private function createPrivateDirectory(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Rollback export directory already exists.');
        }
        if (!mkdir($path, 0700, false) || !chmod($path, 0700)) {
            throw new RuntimeException('Rollback export directory could not be created securely.');
        }
    }

    private function writePrivateFile(string $path, string $content): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Rollback export file already exists.');
        }
        $written = file_put_contents($path, $content, LOCK_EX);
        if ($written !== strlen($content) || !chmod($path, 0600)) {
            throw new RuntimeException('Rollback export file could not be written securely.');
        }
    }

    private function fileSha(string $path): string
    {
        $sha = hash_file('sha256', $path);
        if (!is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
            throw new RuntimeException('Rollback export file fingerprint is unavailable.');
        }
        return $sha;
    }

    private function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private function prettyJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
