<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$files = [
    'export_cli' => 'ops/runtime/run-production-primary-rollback-export.php',
    'export_gate' => 'bot/runtime/ProductionPrimaryRollbackExportGate.php',
    'export_service' => 'bot/runtime/ProductionPrimaryRollbackExportService.php',
    'export_verifier' => 'bot/runtime/ProductionPrimaryRollbackExportVerifier.php',
    'restore_service' => 'bot/runtime/ProductionPrimaryRollbackRestoreService.php',
    'live_cli' => 'ops/runtime/run-production-primary-live-rollback.php',
    'live_gate' => 'bot/runtime/ProductionPrimaryLiveRollbackGate.php',
    'live_service' => 'bot/runtime/ProductionPrimaryLiveRollbackService.php',
    'state_store' => 'bot/runtime/ProductionPrimaryLiveRollbackStateStore.php',
    'runtime_writer' => 'bot/runtime/ProductionPrimaryRuntimeOverlayWriter.php',
    'readiness' => 'ops/runtime/inspect-mvp14r1-recovery-readiness.php',
    'runbook' => 'ops/runtime/MVP14R1_DB_JSON_RECOVERY.md',
    'focused_check' => 'ops/checks/mvp14r1-db-json-recovery-local.sh',
];

$sources = [];
foreach ($files as $name => $relative) {
    $source = file_get_contents($projectRoot . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('MVP-14R.1 package source is unavailable: ' . $relative);
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'EXPORT_DB_PRIMARY_TO_JSON_ROLLBACK',
    'DATABASE_WRITE_EXECUTED=false',
    'LIVE_JSON_CHANGED=false',
    'PERSISTENT_CONFIG_CHANGED=false',
    'WEBHOOK_CHANGED=false',
    'CRON_CHANGED=false',
    'PRODUCTION_CHANGED=false',
] as $required) {
    $assertTrue(
        str_contains($sources['export_cli'], $required),
        'Export CLI is missing the MVP-14R.1 read-only contract: ' . $required
    );
}

foreach ([
    "maintenance_mode'] ?? null) === true",
    "financial_read_only'] ?? null) === true",
    "rollback_driver'] ?? null) === 'json'",
    '($expiresAt - $requestedAt) <= 900',
    'all_modules_enabled_exact',
    'authorization_expected_revision_valid',
    'authorization_expected_sha_valid',
] as $required) {
    $assertTrue(
        str_contains($sources['export_gate'], $required),
        'Export gate is missing a required fail-closed precondition: ' . $required
    );
}

foreach ([
    'WHERE singleton_id = 1 FOR UPDATE',
    'Production projection outbox is not a contiguous completed revision chain.',
    '($audit[\'read_only\'] ?? false) !== true',
    "'database_write_executed' => false",
    "'live_json_changed' => false",
    "'persistent_config_changed' => false",
    "'webhook_changed' => false",
    "'cron_changed' => false",
    "'production_changed' => false",
] as $required) {
    $assertTrue(
        str_contains($sources['export_service'], $required),
        'Export service is missing a required consistency or non-mutation guard: ' . $required
    );
}

foreach ([
    'users.json',
    'games.json',
    'queue.json',
    'transactions.json',
    'support.json',
    'shop_orders.json',
    'payments.json',
    'notifications.json',
    'invites.json',
    'system.json',
] as $dataFile) {
    $assertTrue(
        str_contains($sources['export_service'], $dataFile),
        'Export package is missing a required JSON data file: ' . $dataFile
    );
}

foreach ([
    'checksums.sha256',
    'manifest.json',
    'COMPLETE',
    'snapshot_sha256',
    'verifyRestoredDataDirectory',
] as $required) {
    $combined = $sources['export_verifier'] . "\n" . $sources['restore_service'];
    $assertTrue(
        str_contains($combined, $required),
        'Export verification or isolated restore is missing: ' . $required
    );
}

foreach ([
    'ROLL BACK PRODUCTION TO VERIFIED JSON',
    'ProductionPrimaryRollbackExportVerifier',
    'ProductionPrimaryLiveRollbackInputLoader',
    'ProductionPrimaryLiveRollbackBootstrap',
    'RESUME_STATE_REQUIRED=true',
] as $required) {
    $assertTrue(
        str_contains($sources['live_cli'], $required),
        'Temporary JSON runtime CLI is missing a required guarded contract: ' . $required
    );
}

foreach ([
    "maintenance_mode'] ?? null) === true",
    "financial_read_only'] ?? null) === true",
    'artifact_verified',
    'artifact_backup_compatible',
    'artifact_isolated_restore_required',
    'authorization_confirmation_exact',
    'authorization_live_data_directory_matches',
    'authorization_runtime_config_matches',
] as $required) {
    $assertTrue(
        str_contains($sources['live_gate'], $required),
        'Temporary JSON runtime gate is missing a required exact binding: ' . $required
    );
}

foreach ([
    'restoreIsolated',
    'live_json_installed_db_active',
    'writeSealed',
    'json_route_sealed',
    'sealed_resume_required',
    'previous_json_retained',
    'WHERE singleton_id = 1 FOR UPDATE',
    'writeReleased',
    "'database_route_enabled' => false",
    "'json_write_block_active' => false",
    "'webhook_changed' => false",
    "'cron_changed' => false",
] as $required) {
    $assertTrue(
        str_contains($sources['live_service'], $required),
        'Temporary JSON runtime service is missing required ordering or recovery behavior: ' . $required
    );
}

$assertTrue(
    str_contains($sources['state_store'], 'sealed_resume_required')
        || str_contains($sources['live_service'], 'sealed_resume_required'),
    'Recovery package must persist or handle sealed resume state.'
);
$assertTrue(
    str_contains($sources['runtime_writer'], 'writeSealed')
        && str_contains($sources['runtime_writer'], 'writeReleased'),
    'Runtime overlay writer must expose sealed and released JSON-route states.'
);

foreach ([
    'INSPECT_READ_ONLY_MVP14R1_RECOVERY_READINESS',
    'DATABASE_CONTACTED=false',
    'DB_TO_JSON_EXPORT_EXECUTED=false',
    'PRODUCTION_CHANGED=false',
] as $required) {
    $assertTrue(
        str_contains($sources['readiness'], $required),
        'Static readiness inspector is missing a non-execution marker: ' . $required
    );
}

foreach ([
    'MVP-14R.0 must already be complete',
    'This branch is code and CI only.',
    'No step may be combined implicitly with another step.',
    'Production acceptance remains pending',
] as $required) {
    $assertTrue(
        str_contains($sources['runbook'], $required),
        'MVP-14R.1 runbook is missing an execution boundary: ' . $required
    );
}

foreach ([
    'Mvp14r1RecoveryReadinessInspectorTest.php',
    'ProductionPrimaryRollbackExportMySqlIntegrationTest.php',
    'ProductionPrimaryLiveRollbackServiceTest.php',
    'ProductionPrimaryLiveRollbackStateStoreTest.php',
    'ProductionPrimaryRuntimeOverlayWriterTest.php',
    'MGW_MVP14R1_RECOVERY_PACKAGE=PASSED',
    'DB_TO_JSON_EXPORT_EXECUTED=false',
    'PRODUCTION_CHANGED=false',
] as $required) {
    $assertTrue(
        str_contains($sources['focused_check'], $required),
        'Focused MVP-14R.1 check is missing required coverage or marker: ' . $required
    );
}

fwrite(STDOUT, "Mvp14r1RecoveryPackageContractTest passed: {$assertions} assertions.\n");
