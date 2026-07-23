<?php
declare(strict_types=1);

final class ProductionPrimaryLiveRollbackService
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

    public function __construct(
        private DatabaseConnectionInterface $database,
        private RuntimePrimaryProjectionAuditorInterface $auditor,
        private ProductionPrimaryRollbackArtifactIdentity $artifactIdentity,
        private ProductionPrimaryRollbackExportVerifier $exportVerifier,
        private ProductionPrimaryRollbackRestoreService $restoreService,
        private ProductionPrimaryRuntimeOverlayWriter $runtimeWriter,
        private ProductionPrimaryLiveRollbackStateStore $stateStore,
        private string $privateDir
    ) {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Production live rollback requires MySQL/MariaDB.');
        }
        $this->privateDir = $this->canonicalDirectory(
            $this->privateDir,
            'Production live rollback private directory is unavailable.'
        );
    }

    public function execute(
        string $exportDir,
        string $liveDataDir,
        array $config,
        array $gateReport
    ): array {
        $this->assertGate($gateReport);
        $requestId = (string)$gateReport['request_id'];
        $expectedRevision = (int)$gateReport['expected_state_revision'];
        $expectedStateSha = (string)$gateReport['expected_state_sha256'];
        $expectedSnapshotSha = (string)$gateReport['expected_snapshot_sha256'];

        $artifact = $this->artifactIdentity->inspect($exportDir);
        $this->assertArtifactMatchesGate($artifact, $gateReport);
        $liveDataDir = $this->canonicalDirectory(
            $liveDataDir,
            'Production live JSON directory is unavailable.'
        );
        $this->assertDirectoryMode($liveDataDir, 0700, 'Production live JSON directory');
        $parent = $this->canonicalDirectory(
            dirname($liveDataDir),
            'Production live JSON parent directory is unavailable.'
        );
        if ($this->isInside($this->privateDir, $liveDataDir)
            || $this->isInside($liveDataDir, $this->privateDir)) {
            throw new RuntimeException(
                'Production live JSON and rollback control directories must be separate.'
            );
        }

        $candidateDir = $parent . '/.mgw-live-rollback-candidate-' . $requestId;
        $previousDir = $parent . '/.mgw-json-before-live-rollback-' . $requestId;
        $operationLockPath = $this->privateDir . '/production-live-rollback.lock';
        $recovery = $this->stateStore->recovery();
        if (($recovery['state'] ?? '') === 'completed') {
            $this->assertRecoveryMatchesGate($recovery, $gateReport);
            $verifiedLive = $this->exportVerifier->verifyRestoredDataDirectory(
                $liveDataDir,
                $expectedRevision,
                $expectedStateSha
            );
            if ($this->runtimeRouteEnabled()) {
                throw new RuntimeException(
                    'Completed live rollback recovery still has DB-primary routing enabled.'
                );
            }
            if (is_file($liveDataDir . '/.cutover-write-block')
                || is_link($liveDataDir . '/.cutover-write-block')) {
                throw new RuntimeException(
                    'Completed live rollback recovery still has a JSON write block.'
                );
            }
            return [
                'ok' => true,
                'action' => 'production_live_json_rollback_already_completed',
                'idempotent' => true,
                'request_id' => $requestId,
                'state_revision' => $expectedRevision,
                'state_sha256' => $expectedStateSha,
                'snapshot_sha256' => $expectedSnapshotSha,
                'data_files' => (int)($verifiedLive['data_files'] ?? 0),
                'database_route_enabled' => false,
                'json_write_block_active' => false,
                'maintenance_enabled' => false,
                'financial_read_only' => false,
                'database_contacted' => false,
                'database_write_executed' => false,
                'live_json_changed' => false,
                'persistent_config_changed' => false,
                'production_changed' => false,
                'resume_required' => false,
                'sensitive_identifiers_exposed' => false,
            ];
        }

        $runtimeBackup = $this->runtimeWriter->prepareBackup(
            $requestId,
            (string)$gateReport['runtime_config_fingerprint']
        );
        $cutoverBackup = $this->stateStore->prepareCutoverBackup(
            $requestId,
            $this->stateStore->cutoverFingerprint()
        );
        if ($recovery === []) {
            $this->stateStore->writeRecovery('prepared', $gateReport, [
                'runtime_backup_fingerprint' => (string)$runtimeBackup['backup_fingerprint'],
                'cutover_backup_fingerprint' => (string)$cutoverBackup['backup_fingerprint'],
                'previous_json_retained' => false,
            ]);
        } else {
            $this->assertRecoveryMatchesGate($recovery, $gateReport);
        }

        $operationLock = $this->openPrivateLock($operationLockPath);
        if (!flock($operationLock, LOCK_EX)) {
            fclose($operationLock);
            throw new RuntimeException('Production live rollback operation lock could not be acquired.');
        }

        $oldLock = null;
        $newLock = null;
        $swapped = false;
        $routeDisabled = !$this->runtimeRouteEnabled();
        try {
            $recovery = $this->stateStore->recovery();
            $recoveryState = (string)($recovery['state'] ?? 'prepared');

            if (in_array($recoveryState, ['json_route_sealed', 'sealed_resume_required'], true)) {
                if (!$routeDisabled) {
                    throw new RuntimeException(
                        'Sealed rollback recovery unexpectedly has DB-primary routing enabled.'
                    );
                }
                return $this->finishSealedRollback(
                    $liveDataDir,
                    $gateReport,
                    $expectedRevision,
                    $expectedStateSha,
                    $expectedSnapshotSha,
                    true
                );
            }

            if (file_exists($candidateDir) || is_link($candidateDir)) {
                throw new RuntimeException('Production live rollback candidate already exists unexpectedly.');
            }
            if (file_exists($previousDir) || is_link($previousDir)) {
                throw new RuntimeException('Production previous JSON directory already exists unexpectedly.');
            }

            $restored = $this->restoreService->restoreIsolated($exportDir, $candidateDir);
            if (($restored['ok'] ?? false) !== true
                || ($restored['state_revision'] ?? 0) !== $expectedRevision
                || ($restored['state_sha256'] ?? '') !== $expectedStateSha) {
                throw new RuntimeException('Production live rollback candidate restore identity is invalid.');
            }
            $this->assertDirectoryMode($candidateDir, 0700, 'Production live rollback candidate');
            $this->assertSameDevice($liveDataDir, $candidateDir);
            $this->writeCandidateSeal($candidateDir, $gateReport);

            $oldLock = $this->openDataLock($liveDataDir . '/app.lock');
            $newLock = $this->openDataLock($candidateDir . '/app.lock');
            if (!flock($oldLock, LOCK_EX) || !flock($newLock, LOCK_EX)) {
                throw new RuntimeException('Production live rollback JSON locks could not be acquired.');
            }

            $result = $this->database->transaction(function () use (
                $exportDir,
                $liveDataDir,
                $candidateDir,
                $previousDir,
                $config,
                $gateReport,
                $expectedRevision,
                $expectedStateSha,
                $expectedSnapshotSha,
                &$swapped,
                &$routeDisabled
            ): array {
                $this->verifyLockedDatabaseState(
                    $exportDir,
                    $expectedRevision,
                    $expectedStateSha
                );

                if (!rename($liveDataDir, $previousDir)) {
                    throw new RuntimeException('Production live JSON directory could not be retained.');
                }
                if (!rename($candidateDir, $liveDataDir)) {
                    @rename($previousDir, $liveDataDir);
                    throw new RuntimeException('Production rollback JSON could not be installed atomically.');
                }
                $swapped = true;
                $this->stateStore->writeRecovery(
                    'live_json_installed_db_active',
                    $gateReport,
                    ['previous_json_retained' => true]
                );

                $this->runtimeWriter->writeSealed($gateReport);
                $this->assertRuntimeJsonRoute($config, true, true);
                $routeDisabled = true;
                $this->stateStore->writeCutoverSealed($gateReport);
                $this->stateStore->writeRecovery(
                    'json_route_sealed',
                    $gateReport,
                    ['previous_json_retained' => true]
                );

                return $this->finishSealedRollback(
                    $liveDataDir,
                    $gateReport,
                    $expectedRevision,
                    $expectedStateSha,
                    $expectedSnapshotSha,
                    false,
                    $config
                );
            });

            return $result + [
                'previous_json_retained' => true,
                'previous_json_directory_fingerprint' => hash('sha256', $previousDir),
                'database_contacted' => true,
                'database_write_executed' => false,
            ];
        } catch (Throwable $error) {
            $routeDisabled = !$this->runtimeRouteEnabled();
            if ($swapped && !$routeDisabled) {
                $this->attemptPreDisableSwapRollback($liveDataDir, $candidateDir, $previousDir);
                try {
                    $this->runtimeWriter->restoreAuthorizedBackup(
                        $requestId,
                        (string)$runtimeBackup['backup_fingerprint']
                    );
                    $this->stateStore->restoreAuthorizedCutoverBackup(
                        $requestId,
                        (string)$cutoverBackup['backup_fingerprint']
                    );
                } catch (Throwable) {
                    // The original failure remains primary; recovery state is still recorded.
                }
                $this->stateStore->writeRecovery(
                    'failed_before_route_disable',
                    $gateReport,
                    ['error_fingerprint' => hash('sha256', $error->getMessage())]
                );
            } elseif ($routeDisabled) {
                try {
                    $this->ensureLiveSeal($liveDataDir, $gateReport);
                    $this->runtimeWriter->writeSealed($gateReport);
                    $this->stateStore->writeCutoverSealed($gateReport);
                    $this->stateStore->writeRecovery(
                        'sealed_resume_required',
                        $gateReport,
                        [
                            'previous_json_retained' => is_dir($previousDir),
                            'error_fingerprint' => hash('sha256', $error->getMessage()),
                        ]
                    );
                } catch (Throwable) {
                    // Keep the original failure and the most conservative state available.
                }
            }
            throw $error;
        } finally {
            if (is_resource($newLock)) {
                @flock($newLock, LOCK_UN);
                fclose($newLock);
            }
            if (is_resource($oldLock)) {
                @flock($oldLock, LOCK_UN);
                fclose($oldLock);
            }
            flock($operationLock, LOCK_UN);
            fclose($operationLock);
        }
    }

    private function finishSealedRollback(
        string $liveDataDir,
        array $gateReport,
        int $expectedRevision,
        string $expectedStateSha,
        string $expectedSnapshotSha,
        bool $resumed,
        ?array $originalConfig = null
    ): array {
        $this->exportVerifier->verifyRestoredDataDirectory(
            $liveDataDir,
            $expectedRevision,
            $expectedStateSha
        );
        if ($this->runtimeRouteEnabled()) {
            throw new RuntimeException('Production DB-primary route is still enabled in sealed rollback.');
        }
        $seal = $liveDataDir . '/.cutover-write-block';
        $this->verifySeal($seal, $gateReport);
        if (!unlink($seal)) {
            throw new RuntimeException('Production rollback JSON write block could not be released.');
        }
        clearstatcache(true, $seal);
        if (file_exists($seal) || is_link($seal)) {
            throw new RuntimeException('Production rollback JSON write block still exists.');
        }

        $this->runtimeWriter->writeReleased($gateReport);
        if ($originalConfig !== null) {
            $this->assertRuntimeJsonRoute($originalConfig, false, false);
        } elseif ($this->runtimeRouteEnabled()) {
            throw new RuntimeException('Production DB-primary route re-enabled during rollback release.');
        }
        $this->stateStore->writeCutoverCompleted($gateReport);
        $this->stateStore->writeRecovery(
            'completed',
            $gateReport,
            ['previous_json_retained' => true]
        );

        $verified = $this->exportVerifier->verifyRestoredDataDirectory(
            $liveDataDir,
            $expectedRevision,
            $expectedStateSha
        );
        return [
            'ok' => true,
            'action' => 'production_live_json_rollback_completed',
            'idempotent' => false,
            'resumed' => $resumed,
            'request_id' => (string)$gateReport['request_id'],
            'state_revision' => $expectedRevision,
            'state_sha256' => $expectedStateSha,
            'snapshot_sha256' => $expectedSnapshotSha,
            'data_files' => (int)($verified['data_files'] ?? 0),
            'database_route_enabled' => false,
            'json_write_block_active' => false,
            'maintenance_enabled' => false,
            'financial_read_only' => false,
            'live_json_changed' => true,
            'persistent_config_changed' => true,
            'production_changed' => true,
            'resume_required' => false,
            'webhook_changed' => false,
            'cron_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function verifyLockedDatabaseState(
        string $exportDir,
        int $expectedRevision,
        string $expectedStateSha
    ): void {
        $rows = $this->database->fetchAll(
            'SELECT revision, state_json, state_sha256 FROM '
            . RuntimePrimaryStateSchemaInstaller::TABLE
            . ' WHERE singleton_id = 1 FOR UPDATE'
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('Production live rollback state row is unavailable.');
        }
        $row = $rows[0];
        $revision = (int)($row['revision'] ?? 0);
        $stateSha = strtolower(trim((string)($row['state_sha256'] ?? '')));
        $stateRaw = (string)($row['state_json'] ?? '');
        try {
            $snapshot = json_decode($stateRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Production live rollback DB snapshot is invalid.', 0, $error);
        }
        if (!is_array($snapshot)
            || $revision !== $expectedRevision
            || !hash_equals($expectedStateSha, $stateSha)
            || !hash_equals($stateSha, hash('sha256', $this->canonicalJson($snapshot)))) {
            throw new RuntimeException('Production DB state changed after rollback export.');
        }

        $exportSnapshot = $this->exportVerifier->verifiedSnapshot($exportDir);
        if (!hash_equals(
            hash('sha256', $this->canonicalJson($exportSnapshot)),
            hash('sha256', $this->canonicalJson($snapshot))
        )) {
            throw new RuntimeException('Production rollback export no longer matches DB state.');
        }

        $outbox = $this->database->fetchAll(
            'SELECT status, COUNT(*) AS event_count, MIN(state_revision) AS min_revision, '
            . 'MAX(state_revision) AS max_revision FROM '
            . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
            . ' GROUP BY status ORDER BY status'
        );
        $completed = 0;
        $min = 0;
        $max = 0;
        foreach ($outbox as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $count = max(0, (int)($row['event_count'] ?? 0));
            if ($status !== 'completed' && $count > 0) {
                throw new RuntimeException('Production live rollback outbox is not fully completed.');
            }
            if ($status === 'completed') {
                $completed = $count;
                $min = (int)($row['min_revision'] ?? 0);
                $max = (int)($row['max_revision'] ?? 0);
            }
        }
        if ($completed !== $revision || $min !== 1 || $max !== $revision) {
            throw new RuntimeException('Production live rollback outbox chain is not contiguous.');
        }

        $audit = $this->auditor->auditOnly($snapshot, $revision, $stateSha);
        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || (int)($audit['state_revision'] ?? 0) !== $revision
            || ($audit['state_sha256'] ?? '') !== $stateSha) {
            throw new RuntimeException('Production live rollback all-module parity audit failed.');
        }
    }

    private function assertRuntimeJsonRoute(
        array $originalConfig,
        bool $maintenance,
        bool $financialReadOnly
    ): void {
        $overlay = $this->runtimeWriter->load();
        $flags = is_array($originalConfig['feature_flags'] ?? null)
            ? $originalConfig['feature_flags']
            : [];
        $config = $originalConfig;
        $config['feature_flags'] = array_replace_recursive($flags, $overlay);
        $router = new RuntimeStorageRouter($config);
        if ($router->enabled()
            || $router->enabledModules() !== []
            || ($config['feature_flags']['maintenance_mode'] ?? null) !== $maintenance
            || ($config['feature_flags']['financial_read_only'] ?? null) !== $financialReadOnly) {
            throw new RuntimeException('Production runtime did not resolve to the expected JSON route.');
        }
    }

    private function runtimeRouteEnabled(): bool
    {
        $runtime = $this->runtimeWriter->load();
        $databaseRuntime = is_array($runtime['database_runtime'] ?? null)
            ? $runtime['database_runtime']
            : [];
        return ($databaseRuntime['enabled'] ?? null) === true
            || ($databaseRuntime['production_activated'] ?? null) === true;
    }

    private function writeCandidateSeal(string $candidateDir, array $gateReport): void
    {
        $path = $candidateDir . '/.cutover-write-block';
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Production rollback candidate already contains a write block.');
        }
        $payload = [
            'state' => 'sealed',
            'environment' => 'production',
            'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
            'contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
            'request_id' => (string)$gateReport['request_id'],
            'state_revision' => (int)$gateReport['expected_state_revision'],
            'state_sha256' => (string)$gateReport['expected_state_sha256'],
            'snapshot_sha256' => (string)$gateReport['expected_snapshot_sha256'],
            'created_at_utc' => gmdate(DATE_ATOM),
        ];
        $this->writePrivateJsonFile($path, $payload);
        $this->verifySeal($path, $gateReport);
    }

    private function ensureLiveSeal(string $liveDataDir, array $gateReport): void
    {
        $path = $liveDataDir . '/.cutover-write-block';
        if (!file_exists($path) && !is_link($path)) {
            $this->writeCandidateSeal($liveDataDir, $gateReport);
            return;
        }
        $this->verifySeal($path, $gateReport);
    }

    private function verifySeal(string $path, array $gateReport): void
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Production rollback JSON write block is unavailable.');
        }
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Production rollback JSON write block must have mode 0600.');
        }
        $raw = file_get_contents($path);
        try {
            $payload = json_decode(is_string($raw) ? $raw : '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Production rollback JSON write block is invalid.', 0, $error);
        }
        if (!is_array($payload)
            || ($payload['state'] ?? null) !== 'sealed'
            || ($payload['environment'] ?? null) !== 'production'
            || ($payload['contract_version'] ?? null)
                !== ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION
            || ($payload['request_id'] ?? null) !== ($gateReport['request_id'] ?? null)
            || ($payload['state_revision'] ?? null) !== ($gateReport['expected_state_revision'] ?? null)
            || ($payload['state_sha256'] ?? null) !== ($gateReport['expected_state_sha256'] ?? null)) {
            throw new RuntimeException('Production rollback JSON write block identity is invalid.');
        }
    }

    private function attemptPreDisableSwapRollback(
        string $liveDataDir,
        string $candidateDir,
        string $previousDir
    ): void {
        if (is_dir($liveDataDir) && !file_exists($candidateDir)) {
            @rename($liveDataDir, $candidateDir);
        }
        if (is_dir($previousDir) && !file_exists($liveDataDir)) {
            @rename($previousDir, $liveDataDir);
        }
    }

    private function assertGate(array $gateReport): void
    {
        if (($gateReport['ready'] ?? false) !== true
            || ($gateReport['contract_version'] ?? '')
                !== ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION
            || preg_match('/\A[a-f0-9]{32}\z/', (string)($gateReport['request_id'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', (string)($gateReport['expected_state_sha256'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', (string)($gateReport['expected_snapshot_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('Production live rollback requires a passed exact gate.');
        }
    }

    private function assertArtifactMatchesGate(array $artifact, array $gateReport): void
    {
        foreach ([
            'request_id' => 'request_id',
            'state_revision' => 'expected_state_revision',
            'state_sha256' => 'expected_state_sha256',
            'snapshot_sha256' => 'expected_snapshot_sha256',
            'backup_id' => 'expected_backup_id',
        ] as $artifactField => $gateField) {
            if (($artifact[$artifactField] ?? null) !== ($gateReport[$gateField] ?? null)) {
                throw new RuntimeException('Production live rollback artifact changed after authorization.');
            }
        }
    }

    private function assertRecoveryMatchesGate(array $recovery, array $gateReport): void
    {
        foreach ([
            'request_id' => 'request_id',
            'state_revision' => 'expected_state_revision',
            'state_sha256' => 'expected_state_sha256',
            'snapshot_sha256' => 'expected_snapshot_sha256',
        ] as $recoveryField => $gateField) {
            if (($recovery[$recoveryField] ?? null) !== ($gateReport[$gateField] ?? null)) {
                throw new RuntimeException('Production live rollback recovery belongs to another request.');
            }
        }
    }

    private function openPrivateLock(string $path)
    {
        if (is_link($path)) {
            throw new RuntimeException('Production live rollback operation lock is unsafe.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !chmod($path, 0600)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Production live rollback operation lock is unavailable.');
        }
        return $handle;
    }

    private function openDataLock(string $path)
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Production live rollback app lock is unavailable.');
        }
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Production live rollback app lock must have mode 0600.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Production live rollback app lock could not be opened.');
        }
        return $handle;
    }

    private function writePrivateJsonFile(string $path, array $payload): void
    {
        $raw = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Production live rollback JSON marker could not be created.');
        }
        try {
            if (!chmod($path, 0600)) {
                throw new RuntimeException('Production live rollback JSON marker permissions failed.');
            }
            $written = fwrite($handle, $raw);
            if (!is_int($written) || $written !== strlen($raw) || !fflush($handle)) {
                throw new RuntimeException('Production live rollback JSON marker write failed.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Production live rollback JSON marker sync failed.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertSameDevice(string $left, string $right): void
    {
        $leftStat = stat($left);
        $rightStat = stat($right);
        if (!is_array($leftStat) || !is_array($rightStat)
            || ($leftStat['dev'] ?? null) !== ($rightStat['dev'] ?? null)) {
            throw new RuntimeException('Production live rollback requires same-filesystem atomic rename.');
        }
    }

    private function canonicalDirectory(string $path, string $message): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_dir($path)) {
            throw new RuntimeException($message);
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($message);
        }
        return $canonical;
    }

    private function assertDirectoryMode(string $path, int $expected, string $label): void
    {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== $expected) {
            throw new RuntimeException(
                $label . ' must have exact mode ' . sprintf('%04o', $expected) . '.'
            );
        }
    }

    private function isInside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function canonicalJson(array $value): string
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
