<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$runnerPaths = [
    $projectRoot . '/bot/cutover/ProductionCutoverRunner.php',
    $projectRoot . '/bot/cutover/ProductionCutoverRunTrait.php',
    $projectRoot . '/bot/cutover/ProductionCutoverPerformTrait.php',
    $projectRoot . '/bot/cutover/ProductionCutoverControlTrait.php',
    $projectRoot . '/bot/cutover/ProductionCutoverNoopTrait.php',
    $projectRoot . '/bot/cutover/ProductionCutoverDataTrait.php',
    $projectRoot . '/bot/cutover/ProductionCutoverRuntimeTrait.php',
    $projectRoot . '/bot/cutover/ProductionCutoverReportTrait.php',
];
$entrypointPath = $projectRoot . '/ops/deploy/production-cutover.php';
$routerPath = $projectRoot . '/bot/storage/RuntimeStorageRouter.php';
$runtimeLoaderPath = $projectRoot . '/bot/core/RuntimeConfigLoader.php';

$runnerParts = array_map(static fn(string $path): string|false => file_get_contents($path), $runnerPaths);
$runner = !in_array(false, $runnerParts, true) ? implode("\n", $runnerParts) : false;
$entrypoint = file_get_contents($entrypointPath);
$router = file_get_contents($routerPath);
$runtimeLoader = file_get_contents($runtimeLoaderPath);
if (!is_string($runner) || !is_string($entrypoint) || !is_string($router) || !is_string($runtimeLoader)) {
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
    str_contains($runner, '$runtime = $this->readRuntime();')
        && str_contains($runner, '$preflightConfig = $this->configWithRuntime($runtime);')
        && str_contains($runner, '$preflightConfig,'),
    'Cutover preflight must explicitly merge the runtime after recovery-state checks'
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
    'Mutating cutover failures must restore JSON without deleting DB evidence'
);
$assertTrue(
    str_contains($runner, "'action' => 'cutover_blocked'")
        && str_contains($runner, "'attempted' => false")
        && str_contains($runner, "'production_changed' => false"),
    'Pre-mutation gate failures must remain retryable without false rolled_back state'
);
$assertTrue(
    str_contains($runner, "'action' => 'rollback_noop'")
        && str_contains($runner, "'state_written' => false"),
    'Rollback before any mutation must be a state-free no-op'
);
$assertTrue(
    str_contains($runner, '$stateWritten = false;')
        && str_contains($runner, '$stateWritten = true;')
        && str_contains($runner, "'state_written' => \$stateWritten"),
    'Rollback report must state whether the recovery state was actually persisted'
);
$assertTrue(
    str_contains($runner, "'rollback_succeeded' => \$rollbackSucceeded")
        && str_contains($runner, "'storage_driver' => \$databaseRuntimeDisabled")
        && str_contains($runner, "'production_db_runtime_enabled' => !\$databaseRuntimeDisabled"),
    'Automatic rollback report must not claim JSON success after partial recovery failure'
);
$assertTrue(
    str_contains($runner, "'runtime_error' => \$runtimeError")
        && str_contains($runner, "'ok' => \$routerError === '' && \$runtimeError === '' && \$stateError === ''"),
    'Emergency status must return a structured failure when runtime config is malformed'
);
$assertTrue(
    str_contains($runner, 'public function rearm(): array')
        && str_contains($runner, 'Disable the private production cutover approval before rearming')
        && str_contains($runner, "'fresh_approval_required' => true"),
    'Reviewed rollback must have an explicit safe rearm path with approval disabled'
);
$assertTrue(
    str_contains($runner, 'private ?StorageAdapterInterface $storage')
        && str_contains($runner, 'private ?DatabaseConnectionInterface $database')
        && str_contains($runner, 'private ?BackupManager $backupManager'),
    'Emergency controls must be constructible without DB and backup dependencies'
);
$assertTrue(
    substr_count($runner, '$this->assertControlEnvironmentAndBuild();') >= 3
        && str_contains($runner, 'private function assertControlEnvironmentAndBuild(): void'),
    'Status, rollback and rearm must use the DB-independent control guard'
);
$assertTrue(
    str_contains($entrypoint, "['run', 'status', 'rollback', 'rearm']")
        && str_contains($entrypoint, "if (\$environment !== 'production')"),
    'Entrypoint must expose controlled modes and remain production-only'
);
$skipRuntimeDefine = strpos($entrypoint, "define('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP', true)");
$bootstrapRequire = strpos($entrypoint, "require \$projectRoot . '/bot/core/bootstrap.php';");
$assertTrue(
    $skipRuntimeDefine !== false
        && $bootstrapRequire !== false
        && $skipRuntimeDefine < $bootstrapRequire
        && str_contains($runtimeLoader, "defined('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP')")
        && str_contains($runtimeLoader, 'return $config;'),
    'Cutover bootstrap must bypass runtime overrides before normal bootstrap can execute a broken runtime file'
);
$assertTrue(
    str_contains($entrypoint, 'production-cutover.lock')
        && str_contains($entrypoint, 'managed-migrations.lock'),
    'Cutover must serialize the mutating run against itself and managed migrations'
);
$cutoverLockScope = strpos($entrypoint, "if (\$requestedMode !== 'status')");
$cutoverLockOpen = strpos($entrypoint, '$cutoverLockHandle = fopen');
$assertTrue(
    $cutoverLockScope !== false
        && $cutoverLockOpen !== false
        && $cutoverLockScope < $cutoverLockOpen,
    'Read-only status must remain available without acquiring the exclusive cutover lock'
);
$assertTrue(
    str_contains($entrypoint, '$safeNoop = $requestedMode === \'run\';')
        && str_contains($entrypoint, "$requestedMode . '_blocked'")
        && str_contains($entrypoint, "'manual_intervention_required' => !\$safeNoop")
        && str_contains($entrypoint, "exit((\$lockResult['ok'] ?? false) ? 0 : 2);"),
    'Busy rollback and rearm controls must fail closed instead of reporting a successful no-op'
);
$assertTrue(
    str_contains($entrypoint, "if (\$requestedMode === 'run')")
        && str_contains($entrypoint, '$storage = null;')
        && str_contains($entrypoint, '$database = null;')
        && str_contains($entrypoint, '$backupManager = null;')
        && strpos($entrypoint, '$migrationLockHandle = fopen') > strpos($entrypoint, "if (\$requestedMode === 'run')"),
    'DB, storage, backup and migration-lock initialization must be limited to the mutating run mode'
);
$assertTrue(
    str_contains($router, "'production_activated'")
        && str_contains($router, "'activation_build'")
        && str_contains($router, 'requires every approved module'),
    'Production routing must fail closed without exact build and all-module markers'
);

fwrite(STDOUT, "ProductionCutoverRunnerContractTest passed: {$assertions} assertions.\n");
