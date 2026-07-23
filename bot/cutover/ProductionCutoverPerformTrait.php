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
        $manifest = $this->packageManifest();
        $this->policy->assertPackage($manifest);
        $originalRuntime = $this->readRuntime();
        $this->assertOriginalRuntimeSafe($originalRuntime);
        $this->createRuntimeBackup();
        $context['mutation_stage'] = 'runtime_backup_created';
        $this->writeState([
            'state' => 'running',
            'build' => self::BUILD,
            'package_version' => self::PACKAGE_VERSION,
            'release_commit' => (string)($manifest['release_commit'] ?? ''),
            'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
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
        $expectedSourceFingerprint = strtolower(trim((string)(
            $preflight['source_inventory']['source_fingerprint'] ?? ''
        )));
        if ($expectedSourceFingerprint === ''
            || !hash_equals($expectedSourceFingerprint, $frozenSourceFingerprint)) {
            throw new RuntimeException('Production JSON source changed after the approved preflight.');
        }

        $backup = $this->backupManager->create('production', self::BUILD);
        $context['backup'] = $backup;
        $this->assertBackup($backup);
        $this->writeState([
            'state' => 'switching',
            'build' => self::BUILD,
            'package_version' => self::PACKAGE_VERSION,
            'release_commit' => (string)($manifest['release_commit'] ?? ''),
            'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
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
        if (!$router->enabled()
            || array_values(array_diff(self::MODULES, $router->enabledModules())) !== []) {
            throw new RuntimeException('Production database runtime activation contract is incomplete.');
        }
        $databaseConfig = DatabaseConfig::fromApplicationConfig($activatedConfig);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Production database identity is unavailable during cutover.');
        }
        $databaseIdentity = $databaseConfig->identityFingerprint();

        $primaryState = (new ProductionCutoverPrimaryStateSeeder(
            $activatedConfig,
            $this->database
        ))->seed($snapshot);
        $context['mutation_stage'] = 'primary_state_seeded';
        if (($primaryState['ok'] ?? false) !== true
            || (int)($primaryState['state_revision'] ?? 0) !== 1
            || !hash_equals(
                $frozenSourceFingerprint,
                (string)($primaryState['state_sha256'] ?? '')
            )
            || ($primaryState['projection_event_status'] ?? '') !== 'completed'
            || ($primaryState['projected_modules'] ?? []) !== self::MODULES) {
            throw new RuntimeException('Production DB-primary state/outbox seed is incomplete.');
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
            'package_version' => self::PACKAGE_VERSION,
            'release_commit' => (string)($manifest['release_commit'] ?? ''),
            'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
            'started_at_utc' => $startedAt,
            'plan_fingerprint' => $planFingerprint,
            'source_fingerprint' => $frozenSourceFingerprint,
            'database_identity_fingerprint' => $databaseIdentity,
            'state_revision' => (int)$primaryState['state_revision'],
            'state_sha256' => (string)$primaryState['state_sha256'],
            'outbox_fingerprint' => hash(
                'sha256',
                json_encode(
                    $primaryState['queue'] ?? [],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            ),
            'all_module_fingerprint' => (string)(
                $primaryState['all_module_fingerprint'] ?? ''
            ),
            'backup_id' => (string)($backup['backup_id'] ?? ''),
            'backup_snapshot_sha256' => (string)($backup['snapshot_sha256'] ?? ''),
            'runtime_backup_present' => true,
            'database_runtime_published' => true,
            'json_write_block_active' => true,
        ]);

        $publishedConfig = $this->configWithRuntime($this->readRuntime());
        $publishedRouter = new RuntimeStorageRouter($publishedConfig);
        if (!$publishedRouter->enabled()
            || array_values(array_diff(self::MODULES, $publishedRouter->enabledModules())) !== []) {
            throw new RuntimeException('Published production database runtime did not activate completely.');
        }

        $publishedSnapshot = $this->snapshot();
        if (!hash_equals(
            $frozenSourceFingerprint,
            $this->snapshotFingerprint($publishedSnapshot)
        )) {
            throw new RuntimeException('JSON rollback snapshot changed during the sealed cutover.');
        }
        $regressionAfterPublish = $this->fullRegression($publishedConfig);
        if (empty($regressionAfterPublish['ok']) || !empty($regressionAfterPublish['blockers'])) {
            throw new RuntimeException('Production DB regression failed after publishing the protected route.');
        }

        $readyAt = $this->nowUtc();
        $context['mutation_stage'] = 'awaiting_release';
        $this->writeState([
            'state' => 'awaiting_release',
            'build' => self::BUILD,
            'package_version' => self::PACKAGE_VERSION,
            'release_commit' => (string)($manifest['release_commit'] ?? ''),
            'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
            'started_at_utc' => $startedAt,
            'release_ready_at_utc' => $readyAt,
            'plan_fingerprint' => $planFingerprint,
            'source_fingerprint' => $frozenSourceFingerprint,
            'database_identity_fingerprint' => $databaseIdentity,
            'state_revision' => (int)$primaryState['state_revision'],
            'state_sha256' => (string)$primaryState['state_sha256'],
            'outbox_fingerprint' => hash(
                'sha256',
                json_encode(
                    $primaryState['queue'] ?? [],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            ),
            'all_module_fingerprint' => (string)(
                $primaryState['all_module_fingerprint'] ?? ''
            ),
            'backup_id' => (string)($backup['backup_id'] ?? ''),
            'backup_snapshot_sha256' => (string)($backup['snapshot_sha256'] ?? ''),
            'runtime_backup_present' => true,
            'database_runtime_published' => true,
            'json_write_block_active' => true,
            'maintenance_active' => true,
            'financial_read_only_active' => true,
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'verified_live_rollback_required_after_release' => true,
        ]);

        return [
            'ok' => true,
            'report_type' => 'mvp-14.10e-production-cutover-package',
            'action' => 'cutover_awaiting_release',
            'state' => 'awaiting_release',
            'environment' => 'production',
            'build' => self::BUILD,
            'package_version' => self::PACKAGE_VERSION,
            'release_commit' => (string)($manifest['release_commit'] ?? ''),
            'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'runtime_route' => RuntimeStorageRouter::DRIVER_DATABASE,
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'enabled_modules' => $publishedRouter->enabledModules(),
            'maintenance_released' => false,
            'financial_read_only_released' => false,
            'json_write_block_removed' => false,
            'json_snapshot_unchanged' => true,
            'source_fingerprint' => $frozenSourceFingerprint,
            'cutover_plan_fingerprint' => $planFingerprint,
            'backup' => $this->compactBackup($backup),
            'drain' => $drain,
            'queue_cleanup' => $queueCleanup,
            'import' => $import,
            'primary_state' => $primaryState,
            'regression_before_publish' => $this->compactRegression($regressionBeforePublish),
            'regression_after_publish' => $this->compactRegression($regressionAfterPublish),
            'manual_smoke_required' => true,
            'release_required' => true,
            'separate_release_approval_required' => true,
            'verified_live_rollback_required_after_release' => true,
            'production_changed' => true,
            'mutation_stage' => 'awaiting_release',
            'webhook_changed' => false,
            'cron_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'started_at_utc' => $startedAt,
            'release_ready_at_utc' => $readyAt,
            'next_step' => 'Run ops/deploy/production-cutover-smoke.php, bind its receipt fingerprint to a separate release approval, then use --release.',
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
