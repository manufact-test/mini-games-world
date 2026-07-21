<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/run-staging-db-primary-api-read-only-smoke.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Read-only API smoke CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$evidenceParse = strpos($source, "str_starts_with(\$argument, '--evidence=')");
$ttlParse = strpos($source, "str_starts_with(\$argument, '--ttl-seconds=')");
$bootstrap = strpos($source, "require \$projectRoot . '/bot/core/bootstrap.php';");
$environmentGuard = strpos($source, "if (strtolower(trim((string)(\$config['environment'] ?? ''))) !== 'staging')");
$privateGuard = strpos($source, 'RuntimePrimaryPrivateConfigGuard::assertExternal(');
$lockOpen = strpos($source, '$lockHandle = fopen($lockPath');
$overlayBuild = strpos($source, 'RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(');
$scriptOverride = strpos($source, "\$_SERVER['SCRIPT_FILENAME'] = \$projectRoot . '/bot/api.php';");
$factory = strpos($source, 'StorageFactory::createJson(');
$inspector = strpos($source, '$inspectorDatabase = PdoConnectionFactory::create($databaseConfig);');
$smoke = strpos($source, 'RuntimePrimaryStagingApiReadOnlySmoke(');
$assertTrue(
    $cliGuard !== false
        && $evidenceParse !== false
        && $ttlParse !== false
        && $bootstrap !== false
        && $environmentGuard !== false
        && $privateGuard !== false
        && $lockOpen !== false
        && $overlayBuild !== false
        && $scriptOverride !== false
        && $factory !== false
        && $inspector !== false
        && $smoke !== false
        && $cliGuard < $evidenceParse
        && $evidenceParse < $bootstrap
        && $ttlParse < $bootstrap
        && $bootstrap < $environmentGuard
        && $environmentGuard < $privateGuard
        && $privateGuard < $lockOpen
        && $lockOpen < $overlayBuild
        && $overlayBuild < $scriptOverride
        && $scriptOverride < $factory
        && $factory < $inspector
        && $inspector < $smoke,
    'Read-only API smoke CLI must validate, lock, overlay and resolve before smoke execution'
);
$assertTrue(
    str_contains($source, "\$GLOBALS['config'] = \$overlay;")
        && str_contains($source, "\$GLOBALS['configFile'] = (string)\$configFile;")
        && str_contains($source, "'selector_enabled_in_memory_only' => true")
        && str_contains($source, "'request_session_enabled_in_memory_only' => true")
        && str_contains($source, "'activation_enabled_in_memory_only' => true"),
    'Read-only API smoke must use in-memory latches only'
);
$assertTrue(
    str_contains($source, "unset(\n        \$GLOBALS['mgw_api_success_hooks']")
        && str_contains($source, "\$hooks = \$GLOBALS['mgw_api_success_hooks'] ?? [];")
        && str_contains($source, "\$filters = \$GLOBALS['mgw_api_data_filters'] ?? [];")
        && str_contains($source, 'DatabasePrimaryStateStorageAdapter'),
    'Read-only API smoke must exercise fresh lazy selector hooks and DB-primary storage'
);
$assertTrue(
    str_contains($source, "'worker_tick_count'")
        && str_contains($source, "'state_unchanged'")
        && str_contains($source, "'snapshot_unchanged'")
        && str_contains($source, "'outbox_unchanged'")
        && str_contains($source, "'data_filters_unchanged'"),
    'Read-only API smoke output must prove no mutation and zero-worker behavior'
);
$assertTrue(
    !str_contains($source, "require \$projectRoot . '/bot/api.php'")
        && !str_contains($source, 'include $projectRoot . \'/bot/api.php\'')
        && !str_contains($source, 'file_put_contents(')
        && !str_contains($source, 'rename(')
        && !str_contains($source, 'copy('),
    'Read-only API smoke must not execute an HTTP route or write config files'
);
$assertTrue(
    !str_contains($source, "'host' =>")
        && !str_contains($source, "'database' =>")
        && !str_contains($source, "'username' =>")
        && !str_contains($source, "'password' =>")
        && !str_contains($source, "'evidence_file' =>")
        && !str_contains($source, "'private_dir' =>"),
    'Read-only API smoke output must not expose DB identifiers, secrets or private paths'
);
$assertTrue(
    str_contains($source, "'persistent_config_changed' => false")
        && str_contains($source, "'http_route_added' => false")
        && str_contains($source, "'api_only' => true")
        && str_contains($source, "'webhook_allowed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false"),
    'Read-only API smoke must preserve explicit safety flags'
);
$assertTrue(
    !str_contains($source, 'crontab')
        && !str_contains($source, 'production-cutover.php')
        && !str_contains($source, 'WebhookHandler'),
    'Read-only API smoke must not touch Cron, production cutover or webhook handler'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeCliContractTest passed: {$assertions} assertions.\n");
