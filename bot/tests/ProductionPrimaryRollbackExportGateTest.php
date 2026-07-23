<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportGate.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
        );
    }
};

$now = (new DateTimeImmutable('2026-07-23T15:00:00+00:00'))->getTimestamp();
$plan = str_repeat('a', 64);
$source = str_repeat('b', 64);
$databaseIdentity = str_repeat('c', 64);
$outputRootFingerprint = str_repeat('d', 64);
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
    'request_id' => str_repeat('f', 32),
    'requested_at_utc' => '2026-07-23T14:59:00+00:00',
    'expires_at_utc' => '2026-07-23T15:09:00+00:00',
    'expected_state_revision' => 17,
    'expected_state_sha256' => $stateSha,
    'database_identity_fingerprint' => $databaseIdentity,
    'activation_plan_fingerprint' => $plan,
    'activation_source_fingerprint' => $source,
    'output_root_fingerprint' => $outputRootFingerprint,
    'reason' => 'Emergency rollback export before controlled JSON restoration.',
];

$gate = new ProductionPrimaryRollbackExportGate();
$report = $gate->inspect(
    $config,
    $cutover,
    $authorization,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(true, $report['ready'], 'Exact rollback authorization must pass');
$assertSame([], $report['blockers'], 'Exact rollback authorization must have no blockers');
$assertSame(17, $report['expected_state_revision'], 'Expected revision must be preserved');
$assertSame($stateSha, $report['expected_state_sha256'], 'Expected state SHA must be preserved');
$assertSame(str_repeat('f', 32), $report['request_id'], 'Request ID must be normalized');
$assertTrue(
    preg_match('/\A[a-f0-9]{64}\z/', (string)$report['reason_fingerprint']) === 1,
    'Reason must be represented only by a fingerprint'
);
$encodedReport = json_encode($report, JSON_THROW_ON_ERROR);
$assertTrue(
    !str_contains($encodedReport, 'Emergency rollback export'),
    'Safe gate report must not expose authorization reason text'
);
$assertSame(false, $report['database_contacted'], 'Gate inspection must not contact database');
$assertSame(false, $report['production_changed'], 'Gate inspection must not change production');

$maintenanceOff = $config;
$maintenanceOff['feature_flags']['maintenance_mode'] = false;
$blocked = $gate->inspect(
    $maintenanceOff,
    $cutover,
    $authorization,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Maintenance-off rollback export must block');
$assertTrue(
    in_array('Rollback export requires maintenance mode.', $blocked['blockers'], true),
    'Maintenance blocker must be explicit'
);

$readOnlyOff = $config;
$readOnlyOff['feature_flags']['financial_read_only'] = false;
$blocked = $gate->inspect(
    $readOnlyOff,
    $cutover,
    $authorization,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Financial writes must block rollback export');

$awaitingRelease = $cutover;
$awaitingRelease['state'] = 'awaiting_release';
$awaitingRelease['json_write_block_active'] = true;
$blocked = $gate->inspect(
    $config,
    $awaitingRelease,
    $authorization,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Incomplete cutover must block rollback export');

$expired = $authorization;
$expired['requested_at_utc'] = '2026-07-23T14:40:00+00:00';
$expired['expires_at_utc'] = '2026-07-23T14:50:00+00:00';
$blocked = $gate->inspect(
    $config,
    $cutover,
    $expired,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Expired authorization must block');
$assertSame(false, $blocked['checks']['authorization_expiry_valid'], 'Expiry check must fail');

$longTtl = $authorization;
$longTtl['requested_at_utc'] = '2026-07-23T14:59:00+00:00';
$longTtl['expires_at_utc'] = '2026-07-23T15:30:00+00:00';
$blocked = $gate->inspect(
    $config,
    $cutover,
    $longTtl,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Authorization TTL above fifteen minutes must block');

$wrongRoot = $authorization;
$wrongRoot['output_root_fingerprint'] = str_repeat('0', 64);
$blocked = $gate->inspect(
    $config,
    $cutover,
    $wrongRoot,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Wrong output root must block');

$wrongIdentity = $authorization;
$wrongIdentity['database_identity_fingerprint'] = str_repeat('1', 64);
$blocked = $gate->inspect(
    $config,
    $cutover,
    $wrongIdentity,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Wrong database identity must block');

$weakModules = $config;
$weakModules['feature_flags']['database_runtime']['modules']['payments'] = 'true';
$blocked = $gate->inspect(
    $weakModules,
    $cutover,
    $authorization,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'String module booleans must block production rollback export');

$extraModule = $config;
$extraModule['feature_flags']['database_runtime']['modules']['unexpected'] = true;
$blocked = $gate->inspect(
    $extraModule,
    $cutover,
    $authorization,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Unknown runtime modules must block rollback export');

$future = $authorization;
$future['requested_at_utc'] = '2026-07-23T15:01:00+00:00';
$future['expires_at_utc'] = '2026-07-23T15:10:00+00:00';
$blocked = $gate->inspect(
    $config,
    $cutover,
    $future,
    $databaseIdentity,
    $outputRootFingerprint,
    $now
);
$assertSame(false, $blocked['ready'], 'Future authorization request must block');

fwrite(
    STDOUT,
    "ProductionPrimaryRollbackExportGateTest passed: {$assertions} assertions.\n"
);
