<?php
declare(strict_types=1);

trait ProductionCutoverPerformTrait
{
    private function performApprovedCutover(
        array $preflight,
        string $planFingerprint,
        string $startedAt,
        array &$context
    ): array {
        $originalRuntime = $this->readRuntime();
        $this->assertOriginalRuntimeSafe($originalRuntime);
        $this->createRuntimeBackup();
        $context['mutation_stage'] = 'runtime_backup_created';
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
        $context['mutation_stage'] = 'maintenance_published';
        $maintenanceConfig = $this->configWithRuntime($maintenanceRuntime);
        $this->assertMaintenanceActive($maintenanceConfig);

        $queueCleanup = $this->cleanupQueue();
        $context['mutation_stage'] = 'queue_drained';
        $drain = $this->inspectDrain();
        $this->assertDrainReady($drain);

        $this->activateWriteBlock($planFingerprint);
        $context['mutation_stage'] = 'json_sealed';
        $snapshot = $this->snapshot();
        $frozenSourceFingerprint = $this->snapshotFingerprint($snapshot);
        $context['frozen_source_fingerprint'] = $frozenSourceFingerprint;
        $expectedSourceFingerprint = strtolower(trim((string)($preflight['source_inventory']['source_fingerprint'] ?? '')));
        if ($expectedSourceFingerprint === '' || !hash_equals($expectedSourceFingerprint, $frozenSourceFingerprint)) {
            throw new RuntimeException('Production JSON source changed after the approved preflight.');
        }

        $backup = $this->backupManager->create('production', self::BUILD);
        $context['backup'] = $backup;
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
        $context['mutation_stage'] = 'database_imported';
        $this->assertImportReportComplete($import);

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
        $context['mutation_stage'] = 'database_route_published';
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
        if (!hash_equals($frozenSourceFingerprint, $this->snapshotFingerprint($publishedSnapshot))) {
            throw new RuntimeException('JSON rollback snapshot changed during the sealed cutover.');
        }
        $regressionAfterPublish = $this->fullRegression($publishedConfig);
        if (empty($regressionAfterPublish['ok']) || !empty($regressionAfterPublish['blockers'])) {
            throw new RuntimeException('Production DB regression failed after publishing the route.');
        }

        $finalRuntime = $this->releaseMaintenance($originalRuntime, $activatedRuntime);
        $this->writeRuntime($finalRuntime);
        $context['mutation_stage'] = 'maintenance_released';
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
            'source_fingerprint' => $frozenSourceFingerprint,
            'backup_id' => (string)($backup['backup_id'] ?? ''),
            'backup_snapshot_sha256' => (string)($backup['snapshot_sha256'] ?? ''),
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
            'mutation_stage' => (string)$context['mutation_stage'],
            'sensitive_identifiers_exposed' => false,
            'started_at_utc' => $startedAt,
            'finished_at_utc' => $finishedAt,
            'next_step' => 'Run the manual production smoke checklist. Roll back to JSON immediately if any check fails.',
        ];
    }

    private function assertImportReportComplete(array $import): void
    {
        if (($import['ok'] ?? false) !== true) {
            throw new RuntimeException('Production JSON to database import did not complete cleanly.');
        }

        foreach ([
            'realtime_shadow',
            'economy_shadow',
            'accounts',
            'opening_balances',
            'ownership',
            'realtime_normalized',
            'financial_archive',
        ] as $section) {
            $report = is_array($import[$section] ?? null) ? $import[$section] : [];
            if (($report['ok'] ?? false) !== true) {
                throw new RuntimeException('Production import section failed: ' . $section . '.');
            }
        }

        if ((int)($import['financial_archive']['unknown_status_count'] ?? 0) !== 0) {
            throw new RuntimeException('Production financial archive contains unknown statuses.');
        }

        $schemas = is_array($import['runtime_schemas'] ?? null) ? $import['runtime_schemas'] : [];
        foreach (['shop_ok', 'payments_ok', 'weekly_bonus_ok'] as $schemaFlag) {
            if (($schemas[$schemaFlag] ?? false) !== true) {
                throw new RuntimeException('Production runtime schema verification failed: ' . $schemaFlag . '.');
            }
        }
    }
}
