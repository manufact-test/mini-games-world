<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportGate.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryLiveRollbackGate.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$plan = str_repeat('a', 64);
$source = str_repeat('b', 64);
$database = str_repeat('c', 64);
$exportAuthorization = str_repeat('d', 64);
$exportDirectory = str_repeat('e', 64);
$liveDirectory = str_repeat('f', 64);
$runtimeFingerprint = str_repeat('1', 64);
$stateSha = str_repeat('2', 64);
$snapshotSha = str_repeat('3', 64);
$requestId = str_repeat('4', 32);
$backupId = 'rollback-' . $requestId;
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
    'database_runtime_published' => true,
    'json_write_block_active' => false,
];
$artifact = [
    'ok' => true,
    'backup_manager_compatible' => true,
    'isolated_restore_required' => true,
    'request_id' => $requestId,
    'backup_id' => $backupId,
    'state_revision' => 7,
    'state_sha256' => $stateSha,
    'snapshot_sha256' => $snapshotSha,
    'database_identity_fingerprint' => $database,
    'activation_plan_fingerprint' => $plan,
    'activation_source_fingerprint' => $source,
    'authorization_fingerprint' => $exportAuthorization,
    'export_directory_fingerprint' => $exportDirectory,
];
$authorization = [
    'contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
    'authorized' => true,
    'confirmation' => ProductionPrimaryLiveRollbackGate::CONFIRMATION,
    'environment' => 'production',
    'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
    'request_id' => $requestId,
    'requested_at_utc' => '2026-07-23T15:00:00+00:00',
    'expires_at_utc' => '2026-07-23T15:10:00+00:00',
    'reason' => 'Controlled production rollback after verified incident response.',
    'expected_state_revision' => 7,
    'expected_state_sha256' => $stateSha,
    'expected_snapshot_sha256' => $snapshotSha,
    'expected_backup_id' => $backupId,
    'database_identity_fingerprint' => $database,
    'activation_plan_fingerprint' => $plan,
    'activation_source_fingerprint' => $source,
    'export_authorization_fingerprint' => $exportAuthorization,
    'export_directory_fingerprint' => $exportDirectory,
    'live_data_directory_fingerprint' => $liveDirectory,
    'runtime_config_fingerprint' => $runtimeFingerprint,
];
$now = (new DateTimeImmutable('2026-07-23T15:05:00+00:00'))->getTimestamp();
$gate = new ProductionPrimaryLiveRollbackGate();
$report = $gate->inspect(
    $config,
    $cutover,
    $authorization,
    $artifact,
    $liveDirectory,
    $runtimeFingerprint,
    $now
);
$assertSame(true, $report['ready'], 'Exact live rollback gate must pass');
$assertSame([], $report['blockers'], 'Exact live rollback gate must have no blockers');
$assertSame($requestId, $report['request_id'], 'Gate request ID must be exact');
$assertSame(7, $report['expected_state_revision'], 'Gate revision must be exact');
$assertSame($stateSha, $report['expected_state_sha256'], 'Gate state SHA must be exact');
$assertSame($snapshotSha, $report['expected_snapshot_sha256'], 'Gate snapshot SHA must be exact');
$assertSame(false, $report['database_contacted'], 'Gate must not contact database');
$assertSame(false, $report['production_changed'], 'Gate must not change production');

$cases = [
    'maintenance' => static function () use ($config): array {
        $value = $config;
        $value['feature_flags']['maintenance_mode'] = false;
        return [$value, null, null, null, null];
    },
    'financial' => static function () use ($config): array {
        $value = $config;
        $value['feature_flags']['financial_read_only'] = false;
        return [$value, null, null, null, null];
    },
    'partial_modules' => static function () use ($config): array {
        $value = $config;
        $value['feature_flags']['database_runtime']['modules']['payments'] = false;
        return [$value, null, null, null, null];
    },
    'cutover_state' => static function () use ($cutover): array {
        $value = $cutover;
        $value['state'] = 'awaiting_release';
        return [null, $value, null, null, null];
    },
    'artifact_request' => static function () use ($artifact): array {
        $value = $artifact;
        $value['request_id'] = str_repeat('5', 32);
        return [null, null, null, $value, null];
    },
    'confirmation' => static function () use ($authorization): array {
        $value = $authorization;
        $value['confirmation'] = 'ROLLBACK';
        return [null, null, $value, null, null];
    },
    'expired' => static function () use ($authorization): array {
        $value = $authorization;
        $value['expires_at_utc'] = '2026-07-23T15:04:00+00:00';
        return [null, null, $value, null, null];
    },
    'runtime_fingerprint' => static function () use ($authorization): array {
        $value = $authorization;
        $value['runtime_config_fingerprint'] = str_repeat('6', 64);
        return [null, null, $value, null, null];
    },
];
foreach ($cases as $name => $factory) {
    [$caseConfig, $caseCutover, $caseAuthorization, $caseArtifact] = $factory();
    $case = $gate->inspect(
        $caseConfig ?? $config,
        $caseCutover ?? $cutover,
        $caseAuthorization ?? $authorization,
        $caseArtifact ?? $artifact,
        $liveDirectory,
        $runtimeFingerprint,
        $now
    );
    $assertSame(false, $case['ready'], 'Invalid gate case must block: ' . $name);
    $assertTrue($case['blockers'] !== [], 'Invalid gate case must expose blockers: ' . $name);
}

fwrite(STDOUT, "ProductionPrimaryLiveRollbackGateTest passed: {$assertions} assertions.\n");
