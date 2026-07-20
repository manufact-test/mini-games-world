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
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    $error,
                    'release_invalid_state_recovered',
                    'awaiting_release',
                    null,
                    null,
                    ''
                );
            }
            return $this->recoveryBlockedReport(
                $error,
                'release_invalid_state_without_recovery_artifacts',
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

        try {
            $runtime = $this->readRuntime();
            $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
            $contractError = $this->statusStateContractError(
                $state,
                $router->enabled(),
                $router->enabledModules(),
                $runtime
            );
            if ($contractError !== '') throw new RuntimeException($contractError);

            $snapshot = $this->snapshot();
            if ($sourceFingerprint === ''
                || !hash_equals($sourceFingerprint, $this->snapshotFingerprint($snapshot))) {
                throw new RuntimeException('Production JSON rollback snapshot changed before release.');
            }

            $releaseConfig = $this->configWithRuntime($runtime);
            $regressionBeforeRelease = $this->fullRegression($releaseConfig);
            if (empty($regressionBeforeRelease['ok']) || !empty($regressionBeforeRelease['blockers'])) {
                throw new RuntimeException('Production DB regression failed before maintenance release.');
            }

            $originalRuntime = $this->readRuntimeBackup();
            $finalRuntime = $this->releaseMaintenance($originalRuntime, $runtime);

            $this->removeWriteBlock();
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
            $this->writeState([
                'state' => 'completed',
                'build' => self::BUILD,
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
            ]);

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
                'source_fingerprint' => $sourceFingerprint,
                'cutover_plan_fingerprint' => $planFingerprint,
                'backup' => $this->compactBackup($backup),
                'regression_before_release' => $this->compactRegression($regressionBeforeRelease),
                'final_regression' => $this->compactRegression($finalRegression),
                'automatic_rollback_available' => is_file($this->runtimeBackupFile),
                'production_changed' => true,
                'mutation_stage' => 'maintenance_released',
                'sensitive_identifiers_exposed' => false,
                'started_at_utc' => $startedAt,
                'finished_at_utc' => $finishedAt,
                'next_step' => 'Keep monitoring production. Roll back to JSON immediately if any discrepancy appears.',
            ];
        } catch (Throwable $error) {
            return $this->automaticRollbackReport(
                $error,
                'automatic_rollback_during_release',
                'maintenance_release',
                null,
                $backup,
                $sourceFingerprint,
                $startedAt
            );
        }
    }
}
