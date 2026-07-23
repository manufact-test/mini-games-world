<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$paths = [
    'service' => 'bot/runtime/ProductionPrimaryLiveRollbackService.php',
    'gate' => 'bot/runtime/ProductionPrimaryLiveRollbackGate.php',
    'writer' => 'bot/runtime/ProductionPrimaryRuntimeOverlayWriter.php',
    'state' => 'bot/runtime/ProductionPrimaryLiveRollbackStateStore.php',
    'loader' => 'bot/runtime/ProductionPrimaryLiveRollbackInputLoader.php',
    'bootstrap' => 'bot/runtime/ProductionPrimaryLiveRollbackBootstrap.php',
    'cli' => 'ops/runtime/run-production-primary-live-rollback.php',
];
$sources = [];
foreach ($paths as $name => $relative) {
    $source = file_get_contents($projectRoot . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Live rollback source is unavailable: ' . $name . '.');
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$service = $sources['service'];
$transaction = strpos($service, '$this->database->transaction(function () use (');
$lockedState = strpos($service, '$this->verifyLockedDatabaseState(');
$retainLive = strpos($service, 'rename($liveDataDir, $previousDir)');
$installJson = strpos($service, 'rename($candidateDir, $liveDataDir)');
$runtimeSealed = strpos($service, '$this->runtimeWriter->writeSealed($gateReport);');
$routeCheck = strpos($service, '$this->assertRuntimeJsonRoute($config, true, true);');
$cutoverSealed = strpos($service, '$this->stateStore->writeCutoverSealed($gateReport);');
$releaseSeal = strpos($service, 'if (!unlink($seal))');
$runtimeReleased = strpos($service, '$this->runtimeWriter->writeReleased($gateReport);');
$cutoverCompleted = strpos($service, '$this->stateStore->writeCutoverCompleted($gateReport);');
$recoveryCompleted = strpos($service, "'completed',\n            \$gateReport");
$assertTrue(
    $transaction !== false && $lockedState !== false && $retainLive !== false
        && $installJson !== false && $runtimeSealed !== false && $routeCheck !== false
        && $cutoverSealed !== false && $releaseSeal !== false
        && $runtimeReleased !== false && $cutoverCompleted !== false,
    'Live rollback ordering anchors must exist'
);
$assertTrue(
    $transaction < $lockedState
        && $lockedState < $retainLive
        && $retainLive < $installJson
        && $installJson < $runtimeSealed
        && $runtimeSealed < $routeCheck
        && $routeCheck < $cutoverSealed,
    'DB lock, live retention, JSON install and DB-route disablement must be ordered atomically'
);
$assertTrue(
    $cutoverSealed < $releaseSeal
        && $releaseSeal < $runtimeReleased
        && $runtimeReleased < $cutoverCompleted,
    'JSON seal must remain until DB route is disabled and be released before maintenance ends'
);
$assertTrue(
    str_contains($service, "'live_json_installed_db_active'")
        && str_contains($service, "'json_route_sealed'")
        && str_contains($service, "'sealed_resume_required'")
        && str_contains($service, "'failed_before_route_disable'")
        && str_contains($service, "'completed'"),
    'Live rollback must expose all fail-closed recovery states'
);
$assertTrue(
    str_contains($service, 'flock($operationLock, LOCK_EX)')
        && str_contains($service, 'flock($oldLock, LOCK_EX)')
        && str_contains($service, 'flock($newLock, LOCK_EX)')
        && str_contains($service, 'WHERE singleton_id = 1 FOR UPDATE'),
    'Live rollback must hold operation, old/new JSON and DB state locks'
);
$assertTrue(
    str_contains($service, "previous_json_retained' => true")
        && str_contains($service, 'previous_json_directory_fingerprint')
        && !str_contains($service, 'removeDirectory($previousDir')
        && !str_contains($service, 'unlink($previousDir'),
    'Previous live JSON must be retained and never deleted by the rollback service'
);
$assertTrue(
    !preg_match('/\b(?:UPDATE|INSERT|DELETE|REPLACE)\s+/i', $service)
        && !str_contains($service, '->execute('),
    'Live rollback service must execute no SQL writes'
);
$assertTrue(
    str_contains($service, 'attemptPreDisableSwapRollback(')
        && str_contains($service, 'restoreAuthorizedBackup(')
        && str_contains($service, 'restoreAuthorizedCutoverBackup(')
        && str_contains($service, 'ensureLiveSeal('),
    'Live rollback must revert before route disable and seal after route disable failure'
);

$assertTrue(
    str_contains($sources['gate'], "public const CONFIRMATION = 'ROLL BACK PRODUCTION TO VERIFIED JSON'")
        && str_contains($sources['gate'], "'authorization_expiry_valid'")
        && str_contains($sources['gate'], "'authorization_runtime_config_matches'")
        && str_contains($sources['gate'], "'authorization_export_directory_fingerprint_matches'"),
    'Live rollback gate must require exact confirmation, TTL and immutable fingerprints'
);
$assertTrue(
    str_contains($sources['loader'], "'production-live-rollback-authorization.json'")
        && str_contains($sources['loader'], "basename(\$databaseFile) !== 'database.php'")
        && str_contains($sources['loader'], "basename(\$runtimeFile) !== 'runtime.php'")
        && str_contains($sources['loader'], "'.cutover-write-block'"),
    'Live rollback loader must require exact private inputs and an initially released JSON seal'
);
$assertTrue(
    str_contains($sources['writer'], "\$databaseRuntime['enabled'] = false")
        && str_contains($sources['writer'], "\$databaseRuntime['production_activated'] = false")
        && str_contains($sources['writer'], "array_fill_keys(self::MODULES, false)")
        && str_contains($sources['writer'], 'rename($temporary, $this->runtimeFile)'),
    'Runtime overlay writer must atomically disable all DB-primary routing'
);
$assertTrue(
    str_contains($sources['state'], "'rolled_back_json_sealed'")
        && str_contains($sources['state'], "'rolled_back'")
        && str_contains($sources['state'], "'database_runtime_published'] = false")
        && str_contains($sources['state'], 'production-live-rollback.cutover.before-'),
    'Cutover state must record sealed and completed rollback states with a retained backup'
);
$assertTrue(
    str_contains($sources['cli'], "PHP_SAPI !== 'cli'")
        && str_contains($sources['cli'], "'request-id' => null")
        && str_contains($sources['cli'], "'confirm' => null")
        && str_contains($sources['cli'], 'ProductionPrimaryLiveRollbackGate::CONFIRMATION')
        && str_contains($sources['cli'], 'ERROR_FINGERPRINT=')
        && !str_contains($sources['cli'], 'getMessage() . "\\n"'),
    'Live rollback CLI must be CLI-only, request-bound and avoid raw error disclosure'
);
foreach (['service', 'gate', 'writer', 'state', 'loader', 'bootstrap', 'cli'] as $name) {
    foreach (['curl', 'setWebhook', 'deleteWebhook', 'crontab'] as $forbidden) {
        $assertTrue(
            !str_contains($sources[$name], $forbidden),
            'Live rollback code must not alter HTTP integrations or Cron: ' . $name . ': ' . $forbidden
        );
    }
}

fwrite(STDOUT, "ProductionPrimaryLiveRollbackContractTest passed: {$assertions} assertions.\n");
