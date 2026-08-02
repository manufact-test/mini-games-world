<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportGate.php';

$plan = str_repeat('a', 64);
$source = str_repeat('b', 64);
$databaseIdentity = str_repeat('c', 64);
$outputRoot = str_repeat('d', 64);
$stateSha = str_repeat('e', 64);
$modules = array_fill_keys([
    'accounts', 'realtime', 'invites', 'notifications', 'economy',
    'history', 'shop', 'payments', 'weekly_bonus',
], true);
$config = [
    'environment' => 'production',
    'storage_driver' => 'json',
    'feature_flags' => [
        'maintenance_mode' => true,
        'financial_read_only' => true,
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'activation_build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
            'activation_plan_fingerprint' => $plan,
            'activation_source_fingerprint' => $source,
            'rollback_driver' => 'json',
            'modules' => $modules,
        ],
    ],
];
$cutover = [
    'state' => 'completed',
    'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
    'plan_fingerprint' => $plan,
    'source_fingerprint' => $source,
    'runtime_backup_present' => true,
    'database_runtime_published' => true,
    'json_write_block_active' => false,
    'rollback_driver' => 'json',
];
$authorization = [
    'contract_version' => ProductionPrimaryRollbackExportGate::CONTRACT_VERSION,
    'authorized' => true,
    'environment' => 'production',
    'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
    'request_id' => str_repeat('F', 32),
    'requested_at_utc' => '2026-07-23T14:59:00+00:00',
    'expires_at_utc' => '2026-07-23T15:09:00+00:00',
    'expected_state_revision' => 1,
    'expected_state_sha256' => $stateSha,
    'database_identity_fingerprint' => $databaseIdentity,
    'activation_plan_fingerprint' => $plan,
    'activation_source_fingerprint' => $source,
    'output_root_fingerprint' => $outputRoot,
    'reason' => 'Verify exact request ID byte contract.',
];

$report = (new ProductionPrimaryRollbackExportGate())->inspect(
    $config,
    $cutover,
    $authorization,
    $databaseIdentity,
    $outputRoot,
    (new DateTimeImmutable('2026-07-23T15:00:00+00:00'))->getTimestamp()
);
if (($report['ready'] ?? true) !== false
    || ($report['checks']['authorization_request_id_valid'] ?? true) !== false
    || ($report['request_id'] ?? '') !== str_repeat('F', 32)) {
    throw new RuntimeException('Uppercase rollback request ID must be rejected without normalization.');
}

fwrite(STDOUT, "ProductionPrimaryRollbackExportRequestIdCaseTest passed: 3 assertions.\n");
