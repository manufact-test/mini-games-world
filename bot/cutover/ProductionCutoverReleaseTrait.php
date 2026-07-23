<?php
declare(strict_types=1);

trait ProductionCutoverReleaseTrait
{
    public function release(): array
    {
        $this->assertControlEnvironmentAndBuild();
        if (!$this->storage instanceof StorageAdapterInterface
            || !$this->database instanceof DatabaseConnectionInterface) {
            throw new RuntimeException('Production cutover release dependencies are unavailable.');
        }

        try {
            $state = $this->readState();
        } catch (Throwable $error) {
            return $this->recoveryBlockedReport(
                $error,
                'release_invalid_state_requires_review',
                'invalid'
            );
        }

        $stateName = strtolower(trim((string)($state['state'] ?? '')));
        if ($stateName === 'completed') return $this->completedNoop($state);
        if ($stateName !== 'awaiting_release') {
            return $this->releaseBlockedReport(
                new RuntimeException('Production cutover release requires the awaiting_release state.'),
                'release_state_not_ready',
                $stateName !== '' ? $stateName : 'not_started',
                (string)($state['started_at_utc'] ?? '')
            );
        }

        $startedAt = (string)($state['started_at_utc'] ?? '');
        $sourceFingerprint = strtolower(trim((string)($state['source_fingerprint'] ?? '')));
        $planFingerprint = strtolower(trim((string)($state['plan_fingerprint'] ?? '')));
        $backup = [
            'ok' => true,
            'backup_id' => (string)($state['backup_id'] ?? ''),
            'snapshot_sha256' => (string)($state['backup_snapshot_sha256'] ?? ''),
        ];
        $mutationStage = 'none';
        $receipt = null;

        try {
            $runtime = $this->readRuntime();
            $releaseConfig = $this->configWithRuntime($runtime);
            $router = new RuntimeStorageRouter($releaseConfig);
            $contractError = $this->statusStateContractError(
                $state,
                $router->enabled(),
                $router->enabledModules(),
                $runtime
            );
            if ($contractError !== '') throw new RuntimeException($contractError);

            $manifest = $this->packageManifest();
            $this->policy->assertPackage($manifest);
            $runtimeContract = ProductionRuntimePrimaryContract::inspect($this->projectRoot);
            if (($runtimeContract['ready'] ?? false) !== true) {
                throw new RuntimeException(
                    'Production runtime contract is not ready for release: '
                    . implode('; ', array_map('strval', (array)($runtimeContract['blockers'] ?? [])))
                );
            }

            $databaseConfig = DatabaseConfig::fromApplicationConfig($releaseConfig);
            if (!$databaseConfig->enabled()) {
                throw new RuntimeException('Production database is not enabled for release.');
            }
            $databaseIdentity = $databaseConfig->identityFingerprint();
            $receiptFile = $this->privateDir . '/production-cutover-release-receipt.json';
            $receipt = (new ProductionCutoverReleaseReceiptVerifier())->verify(
                $receiptFile,
                $state,
                $manifest,
                $runtimeContract,
                $databaseIdentity,
                $this->timestamp()
            );
            if (($receipt['ready'] ?? false) !== true) {
                throw new RuntimeException(
                    'Production release smoke receipt is blocked: '
                    . implode('; ', array_map('strval', (array)($receipt['blockers'] ?? [])))
                );
            }
            $this->policy->assertReleaseApproved(
                $planFingerprint,
                $sourceFingerprint,
                (string)($receipt['receipt_fingerprint'] ?? ''),
                $this->timestamp()
            );

            $snapshot = $this->snapshot();
            if ($sourceFingerprint === ''
                || !hash_equals($sourceFingerprint, $this->snapshotFingerprint($snapshot))) {
                throw new RuntimeException('Production JSON rollback snapshot changed before release.');
            }

            $regressionBeforeRelease = $this->fullRegression($releaseConfig);
            if (empty($regressionBeforeRelease['ok']) || !empty($regressionBeforeRelease['blockers'])) {
                throw new RuntimeException('Production DB regression failed before maintenance release.');
            }

            $originalRuntime = $this->readRuntimeBackup();
            $finalRuntime = $this->releaseMaintenance($originalRuntime, $runtime);
            $finalConfig = $this->configWithRuntime($finalRuntime);
            $candidateRouter = new RuntimeStorageRouter($finalConfig);
            if (!$candidateRouter->enabled()
                || array_values(array_diff(self::MODULES, $candidateRouter->enabledModules())) !== []) {
                throw new RuntimeException('Candidate production database route is incomplete.');
            }
            $candidateRegression = $this->fullRegression($finalConfig);
            if (empty($candidateRegression['ok']) || !empty($candidateRegression['blockers'])) {
                throw new RuntimeException('Candidate production DB regression failed before publication.');
            }

            // Publish the final runtime while the JSON seal and awaiting_release
            // state still keep public entrypoints fail-closed.
            $this->writeRuntime($finalRuntime);
            $mutationStage = 'release_runtime_published';
            $publishedRuntime = $this->readRuntime();
            $publishedRouter = new RuntimeStorageRouter($this->configWithRuntime($publishedRuntime));
            if (!$publishedRouter->enabled()
                || array_values(array_diff(self::MODULES, $publishedRouter->enabledModules())) !== []) {
                throw new RuntimeException('Published production database route is incomplete.');
            }

            $this->removeWriteBlock();
            $mutationStage = 'release_json_unsealed';
            if (is_file($this->writeBlockFile) || is_link($this->writeBlockFile)) {
                throw new RuntimeException('Production JSON write block remained after release.');
            }

            $finishedAt = $this->nowUtc();
            $this->writeState([
                'state' => 'completed',
                'build' => self::BUILD,
                'package_version' => self::PACKAGE_VERSION,
                'release_commit' => (string)($manifest['release_commit'] ?? ''),
                'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
                'runtime_contract_fingerprint' => (string)(
                    $runtimeContract['contract_fingerprint'] ?? ''
                ),
                'database_identity_fingerprint' => $databaseIdentity,
                'release_receipt_fingerprint' => (string)(
                    $receipt['receipt_fingerprint'] ?? ''
                ),
                'started_at_utc' => $startedAt,
                'completed_at_utc' => $finishedAt,
                'plan_fingerprint' => $planFingerprint,
                'source_fingerprint' => $sourceFingerprint,
                'backup_id' => (string)($state['backup_id'] ?? ''),
                'backup_snapshot_sha256' => (string)($state['backup_snapshot_sha256'] ?? ''),
                'runtime_backup_present' => is_file($this->runtimeBackupFile),
                'database_runtime_published' => true,
                'json_write_block_active' => false,
                'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'verified_live_rollback_required' => true,
            ]);
            $mutationStage = 'completed';

            return [
                'ok' => true,
                'report_type' => 'mvp-14.10e-production-cutover-package',
                'action' => 'cutover_completed',
                'state' => 'completed',
                'environment' => 'production',
                'build' => self::BUILD,
                'package_version' => self::PACKAGE_VERSION,
                'release_commit' => (string)($manifest['release_commit'] ?? ''),
                'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
                'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'runtime_route' => RuntimeStorageRouter::DRIVER_DATABASE,
                'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'enabled_modules' => $publishedRouter->enabledModules(),
                'maintenance_released' => true,
                'financial_read_only_released' => true,
                'json_write_block_removed' => true,
                'json_snapshot_unchanged' => true,
                'source_fingerprint' => $sourceFingerprint,
                'cutover_plan_fingerprint' => $planFingerprint,
                'release_receipt_fingerprint' => (string)(
                    $receipt['receipt_fingerprint'] ?? ''
                ),
                'backup' => $this->compactBackup($backup),
                'regression_before_release' => $this->compactRegression($regressionBeforeRelease),
                'candidate_regression' => $this->compactRegression($candidateRegression),
                'verified_live_rollback_required' => true,
                'rollback_export_command' => 'ops/runtime/run-production-primary-rollback-export.php',
                'live_rollback_command' => 'ops/runtime/run-production-primary-live-rollback.php',
                'production_changed' => true,
                'mutation_stage' => $mutationStage,
                'webhook_changed' => false,
                'cron_changed' => false,
                'sensitive_identifiers_exposed' => false,
                'started_at_utc' => $startedAt,
                'finished_at_utc' => $finishedAt,
                'next_step' => 'Monitor DB-primary production. Use only the verified export and live rollback package for recovery.',
            ];
        } catch (Throwable $error) {
            if ($mutationStage === 'none') {
                return $this->releaseBlockedReport(
                    $error,
                    'release_pre_mutation_gate_failed',
                    'awaiting_release',
                    $startedAt
                );
            }
            return $this->automaticRollbackReport(
                $error,
                'release_failed_after_publication_started',
                $mutationStage,
                null,
                $backup,
                $sourceFingerprint,
                $startedAt
            );
        }
    }

    private function readRuntimeBackup(): array
    {
        if (is_link($this->runtimeBackupFile)
            || !is_file($this->runtimeBackupFile)
            || !is_readable($this->runtimeBackupFile)) {
            throw new RuntimeException('Production cutover runtime backup is unavailable for release.');
        }
        clearstatcache(true, $this->runtimeBackupFile);
        $mode = fileperms($this->runtimeBackupFile);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Production cutover runtime backup must have exact mode 0600.');
        }
        $payload = file_get_contents($this->runtimeBackupFile);
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Production cutover runtime backup is unreadable for release.');
        }
        if ($payload === '__MGW_RUNTIME_ABSENT__') return [];

        $runtime = require $this->runtimeBackupFile;
        if (!is_array($runtime)) {
            throw new RuntimeException('Production cutover runtime backup must return an array.');
        }
        return $runtime;
    }

    private function releaseBlockedReport(
        Throwable $error,
        string $reason,
        string $state,
        ?string $startedAt = null
    ): array {
        return [
            'ok' => false,
            'report_type' => 'mvp-14.10e-production-cutover-package',
            'action' => 'release_blocked',
            'reason' => $reason,
            'state' => $state,
            'environment' => 'production',
            'build' => self::BUILD,
            'package_version' => self::PACKAGE_VERSION,
            'error_class' => get_class($error),
            'error_message' => $this->safeMessage($error->getMessage()),
            'production_changed' => false,
            'maintenance_released' => false,
            'financial_read_only_released' => false,
            'json_write_block_must_remain_active' => true,
            'release_receipt_required' => true,
            'separate_release_approval_required' => true,
            'manual_review_required' => true,
            'webhook_changed' => false,
            'cron_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'started_at_utc' => $startedAt,
            'failed_at_utc' => $this->nowUtc(),
        ];
    }
}
