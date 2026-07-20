<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$runnerPath = $projectRoot . '/bot/cutover/ProductionCutoverRunner.php';
$entrypointPath = $projectRoot . '/ops/deploy/production-cutover.php';
$routerPath = $projectRoot . '/bot/storage/RuntimeStorageRouter.php';

$runner = file_get_contents($runnerPath);
$entrypoint = file_get_contents($entrypointPath);
$router = file_get_contents($routerPath);
if (!is_string($runner) || !is_string($entrypoint) || !is_string($router)) {
    throw new RuntimeException('Production cutover sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($runner, "private const BUILD = 'v103-mvp14-production-cutover'"),
    'Runner must be bound to the exact production cutover build'
);
foreach ([
    'accounts', 'realtime', 'invites', 'notifications', 'economy',
    'history', 'shop', 'payments', 'weekly_bonus',
] as $module) {
    $assertTrue(str_contains($runner, "'{$module}'"), 'Runner must include module ' . $module);
}
$assertTrue(
    str_contains($runner, 'ProductionPreflightRunner(')
        && str_contains($runner, 'assertApproved(self::BUILD, $planFingerprint'),
    'Cutover must require a fresh preflight and exact short-lived approval'
);
$assertTrue(
    str_contains($runner, "'maintenance_mode'] = true")
        && str_contains($runner, "'financial_read_only'] = true"),
    'Cutover must freeze new work and financial writes before import'
);
$assertTrue(
    str_contains($runner, 'activateWriteBlock(')
        && str_contains($runner, 'removeWriteBlock()'),
    'Cutover must seal and release the JSON write barrier'
);
$assertTrue(
    str_contains($runner, 'LegacyAccountImportService(')
        && str_contains($runner, 'LegacyOpeningBalanceImportService(')
        && str_contains($runner, 'LegacyAccountOwnershipLinkService(')
        && str_contains($runner, 'LegacyRealtimeNormalizedImportService(')
        && str_contains($runner, 'LegacyFinancialArchiveImportService('),
    'Cutover must perform the complete ordered production import'
);
$assertTrue(
    substr_count($runner, 'fullRegression(') >= 4,
    'Cutover must run regression before and after publishing the DB route'
);
$assertTrue(
    str_contains($runner, "'action' => 'automatic_rollback'")
        && str_contains($runner, 'rollbackInternal(')
        && str_contains($runner, 'database_rows_preserved_for_analysis'),
    'Every cutover failure must restore JSON without deleting DB evidence'
);
$assertTrue(
    str_contains($entrypoint, "['run', 'status', 'rollback']")
        && str_contains($entrypoint, "if (\$environment !== 'production')"),
    'Entrypoint must expose controlled modes and remain production-only'
);
$assertTrue(
    str_contains($entrypoint, 'production-cutover.lock')
        && str_contains($entrypoint, 'managed-migrations.lock'),
    'Cutover must serialize against itself and managed migrations'
);
$assertTrue(
    str_contains($router, "'production_activated'")
        && str_contains($router, 'requires every approved module'),
    'Production routing must fail closed without the durable all-module marker'
);

fwrite(STDOUT, "ProductionCutoverRunnerContractTest passed: {$assertions} assertions.\n");
