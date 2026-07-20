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

$assertTrue(str_contains($runner, "private const BUILD = 'v103-mvp14-production-cutover'"), 'Runner must be bound to the exact production cutover build');
foreach (['accounts', 'realtime', 'invites', 'notifications', 'economy', 'history', 'shop', 'payments', 'weekly_bonus'] as $module) {
    $assertTrue(str_contains($runner, "'{$module}'"), 'Runner must include module ' . $module);
}
$assertTrue(str_contains($runner, 'ProductionPreflightRunner(') && str_contains($runner, 'assertApproved(self::BUILD, $planFingerprint'), 'Cutover must require a fresh preflight and exact short-lived approval');
$assertTrue(str_contains($runner, '$runtime = $this->readRuntime();') && str_contains($runner, '$preflightConfig = $this->configWithRuntime($runtime);') && str_contains($runner, '$preflightConfig,'), 'Cutover preflight must explicitly merge the runtime after recovery-state checks');
$assertTrue(str_contains($runner, "'maintenance_mode'] = true") && str_contains($runner, "'financial_read_only'] = true"), 'Cutover must freeze new work and financial writes before import');
$assertTrue(str_contains($runner, 'activateWriteBlock(') && str_contains($runner, 'removeWriteBlock()'), 'Cutover must seal and release the JSON write barrier');
$assertTrue(str_contains($runner, 'LegacyAccountImportService(') && str_contains($runner, 'LegacyOpeningBalanceImportService(') && str_contains($runner, 'LegacyAccountOwnershipLinkService(') && str_contains($runner, 'LegacyRealtimeNormalizedImportService(') && str_contains($runner, 'LegacyFinancialArchiveImportService('), 'Cutover must perform the complete ordered production import');
$assertTrue(substr_count($runner, 'fullRegression(') >= 4, 'Cutover must run regression before and after publishing the DB route');
$assertTrue(str_contains($runner, "'action' => 'automatic_rollback'") && str_contains($runner, 'rollbackInternal(') && str_contains($runner, 'database_rows_preserved_for_analysis'), 'Mutating cutover failures must restore JSON without deleting DB evidence');
$assertTrue(str_contains($runner, "'action' => 'cutover_blocked'") && str_contains($runner, "'attempted' => false") && str_contains($runner, "'production_changed' => false"), 'Pre-mutation gate failures must remain retryable without false rolled_back state');
$assertTrue(str_contains($runner, "'action' => 'recovery_blocked'") && str_contains($runner, "'production_change_status' => 'unknown'") && str_contains($runner, "'production_changed' => null") && str_contains($runner, 'active_state_without_recovery_artifacts'), 'Uncertain state without exact recovery evidence must fail closed without false claims');
$assertTrue(str_contains($runner, "'action' => 'rollback_noop'") && str_contains($runner, "'state_written' => false"), 'Rollback before any mutation must be a state-free no-op');
$assertTrue(str_contains($runner, '$stateWritten = false;') && str_contains($runner, '$stateWritten = true;') && str_contains($runner, "'state_written' => \$stateWritten"), 'Rollback report must state whether the recovery state was actually persisted');
$assertTrue(str_contains($runner, "'rollback_succeeded' => \$rollbackSucceeded") && str_contains($runner, "\$rollbackAction === 'rollback_to_json'") && str_contains($runner, '&& $statePersisted;') && str_contains($runner, "'storage_driver' => \$rollbackSucceeded") && str_contains($runner, "'production_db_runtime_enabled' => \$databaseRuntimeDisabled ? false : null"), 'Automatic rollback success and JSON reporting must require complete persisted recovery');
$assertTrue(str_contains($runner, 'exact runtime backup is missing') && str_contains($runner, "'storage_driver' => \$routerDisabled && \$runtimeRestored") && !str_contains($runner, 'elseif (!$routerInitiallyEnabled)'), 'Non-noop rollback must require the exact runtime backup even when DB routing appears disabled');
$assertTrue(str_contains($runner, "'runtime_error' => \$runtimeError") && str_contains($runner, "'ok' => \$routerError === ''") && str_contains($runner, "'state_contract_error' => \$stateContractError") && str_contains($runner, 'statusStateContractError('), 'Emergency status must report malformed runtime and inconsistent state contracts');
$assertTrue(str_contains($runner, 'Rolled-back state is missing the preserved exact runtime backup.') && str_contains($runner, 'Completed cutover does not have database runtime routing enabled.') && str_contains($runner, 'rollback is incomplete and requires immediate operator review'), 'Status must fail unhealthy terminal and rollback_failed states');
$assertTrue(str_contains($runner, 'rolled_back_state_contract_recovered') && str_contains($runner, 'completed_state_contract_recovered') && str_contains($runner, 'rolled_back_state_runtime_validation_failed'), 'Inconsistent terminal states must repair from exact runtime backup or block recovery');
$assertTrue(str_contains($runner, "if (\$stateName !== 'rolled_back')") && str_contains($runner, "'runtime_restored' => 'runtime restore'") && str_contains($runner, "'json_write_block_removed' => 'JSON write-block removal'") && str_contains($runner, "'database_runtime_disabled' => 'database runtime disablement'") && str_contains($runner, 'rearm requires the preserved exact runtime backup'), 'Rearm must reject rollback_failed and require complete persisted recovery evidence');
$assertTrue(str_contains($runner, 'public function rearm(): array') && str_contains($runner, 'Disable the private production cutover approval before rearming') && str_contains($runner, "'fresh_approval_required' => true"), 'Reviewed rollback must have an explicit safe rearm path with approval disabled');
$assertTrue(str_contains($runner, 'private ?StorageAdapterInterface $storage') && str_contains($runner, 'private ?DatabaseConnectionInterface $database') && str_contains($runner, 'private ?BackupManager $backupManager'), 'Emergency controls must be constructible without DB and backup dependencies');
$assertTrue(substr_count($runner, '$this->assertControlEnvironmentAndBuild();') >= 3 && str_contains($runner, 'private function assertControlEnvironmentAndBuild(): void'), 'Status, rollback and rearm must use the DB-independent control guard');
$assertTrue(str_contains($entrypoint, "['run', 'status', 'rollback', 'rearm']") && str_contains($entrypoint, "if (\$environment !== 'production')"), 'Entrypoint must expose controlled modes and remain production-only');
$skipRuntimeDefine = strpos($entrypoint, "define('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP', true)");
$bootstrapRequire = strpos($entrypoint, "require \$projectRoot . '/bot/core/bootstrap.php';");
$assertTrue($skipRuntimeDefine !== false && $bootstrapRequire !== false && $skipRuntimeDefine < $bootstrapRequire && str_contains($runtimeLoader, "defined('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP')") && str_contains($runtimeLoader, 'return $config;'), 'Cutover bootstrap must bypass runtime overrides before normal bootstrap can execute a broken runtime file');
$assertTrue(str_contains($entrypoint, 'production-cutover.lock') && str_contains($entrypoint, 'managed-migrations.lock'), 'Cutover must serialize the mutating run against itself and managed migrations');
$cutoverLockScope = strpos($entrypoint, "if (\$requestedMode !== 'status')");
$cutoverLockOpen = strpos($entrypoint, '$cutoverLockHandle = fopen');
$assertTrue($cutoverLockScope !== false && $cutoverLockOpen !== false && $cutoverLockScope < $cutoverLockOpen, 'Read-only status must remain available without acquiring the exclusive cutover lock');
$assertTrue(str_contains($entrypoint, '$safeNoop = $requestedMode === \'run\';') && str_contains($entrypoint, "$requestedMode . '_blocked'") && str_contains($entrypoint, "'manual_intervention_required' => !\$safeNoop") && str_contains($entrypoint, "exit((\$lockResult['ok'] ?? false) ? 0 : 2);"), 'Busy rollback and rearm controls must fail closed instead of reporting a successful no-op');
$assertTrue(str_contains($entrypoint, "if (\$requestedMode === 'run')") && str_contains($entrypoint, '$storage = null;') && str_contains($entrypoint, '$database = null;') && str_contains($entrypoint, '$backupManager = null;') && strpos($entrypoint, '$migrationLockHandle = fopen') > strpos($entrypoint, "if (\$requestedMode === 'run')"), 'DB, storage, backup and migration-lock initialization must be limited to the mutating run mode');
$assertTrue(str_contains($router, "'production_activated'") && str_contains($router, "'activation_build'") && str_contains($router, 'requires every approved module'), 'Production routing must fail closed without exact build and all-module markers');

fwrite(STDOUT, "ProductionCutoverRunnerContractTest passed: {$assertions} assertions.\n");
