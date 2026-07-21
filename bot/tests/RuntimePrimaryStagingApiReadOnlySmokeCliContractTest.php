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
$prefixMap = strpos($source, "'--evidence=' => 'evidence'");
$duplicateGuard = strpos($source, 'if (isset($seen[$matchedName]))');
$requiredEvidence = strpos($source, "if (!isset(\$seen['evidence'])");
$numericTtl = strpos($source, "preg_match('/^\\d+$/', \$values['ttl'])");
$scriptOverride = strpos($source, "\$_SERVER['SCRIPT_FILENAME'] = \$projectRoot . '/bot/api.php';");
$bootstrap = strpos($source, "require \$projectRoot . '/bot/core/bootstrap.php';");
$environmentGuard = strpos($source, "if (strtolower(trim((string)(\$config['environment'] ?? ''))) !== 'staging')");
$privateGuard = strpos($source, 'RuntimePrimaryPrivateConfigGuard::assertExternal(');
$lockOpen = strpos($source, '$lockHandle = fopen($lockPath');
$overlayBuild = strpos($source, 'RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(');
$factory = strpos($source, 'StorageFactory::createJson(');
$inspector = strpos($source, '$inspectorDatabase = PdoConnectionFactory::create($databaseConfig);');
$smoke = strpos($source, 'RuntimePrimaryStagingApiReadOnlySmoke(');
$assertTrue(
    $cliGuard !== false
        && $prefixMap !== false
        && $duplicateGuard !== false
        && $requiredEvidence !== false
        && $numericTtl !== false
        && $scriptOverride !== false
        && $bootstrap !== false
        && $environmentGuard !== false
        && $privateGuard !== false
        && $lockOpen !== false
        && $overlayBuild !== false
        && $factory !== false
        && $inspector !== false
        && $smoke !== false
        && $cliGuard < $prefixMap
        && $prefixMap < $duplicateGuard
        && $duplicateGuard < $requiredEvidence
        && $requiredEvidence < $numericTtl
        && $numericTtl < $scriptOverride
        && $scriptOverride < $bootstrap
        && $bootstrap < $environmentGuard
        && $environmentGuard < $privateGuard
        && $privateGuard < $lockOpen
        && $lockOpen < $overlayBuild
        && $overlayBuild < $factory
        && $factory < $inspector
        && $inspector < $smoke,
    'Read-only API smoke CLI must validate exact arguments before API bootstrap and resolve only after overlay'
);
$assertTrue(
    str_contains($source, "'--ttl-seconds=' => 'ttl'")
        && str_contains($source, 'Read-only API smoke option may be specified only once')
        && str_contains($source, '$value = substr($argument, strlen($matchedPrefix));')
        && !str_contains($source, 'trim(substr($argument')
        && !str_contains($source, 'strtolower(substr($argument')
        && str_contains($source, '--ttl-seconds must be between 60 and 600'),
    'Read-only API smoke CLI must reject duplicate and normalized option values'
);
$assertTrue(
    str_contains($source, 'umask(0077);')
        && str_contains($source, 'if (!chmod($lockPath, 0600))')
        && str_contains($source, '$lockMode = fileperms($lockPath);')
        && str_contains($source, '($lockMode & 0o077) !== 0')
        && str_contains($source, 'lock is not a private regular lock file')
        && strpos($source, 'fileperms($lockPath)') < strpos($source, 'flock($lockHandle, LOCK_EX | LOCK_NB)'),
    'Read-only API smoke lock must be private before acquisition'
);
$assertTrue(
    str_contains($source, "\$bootstrapHooks = \$GLOBALS['mgw_api_success_hooks'] ?? [];")
        && str_contains($source, "\$bootstrapFilters = \$GLOBALS['mgw_api_data_filters'] ?? [];")
        && str_contains($source, '$bootstrapHookCount = count($bootstrapHooks);')
        && str_contains($source, '$bootstrapFilterCount = count($bootstrapFilters);')
        && str_contains($source, 'count($hooks) !== $bootstrapHookCount + 1')
        && str_contains($source, 'count($filters) !== $bootstrapFilterCount')
        && str_contains($source, 'did not preserve the real API bootstrap hook contour'),
    'Read-only API smoke must preserve actual API bootstrap hooks and filters while adding one finalizer'
);
$assertTrue(
    str_contains($source, "\$GLOBALS['config'] = \$overlay;")
        && str_contains($source, "\$GLOBALS['configFile'] = (string)\$configFile;")
        && str_contains($source, "'selector_enabled_in_memory_only'")
        && str_contains($source, "'request_session_enabled_in_memory_only'")
        && str_contains($source, "'activation_enabled_in_memory_only'"),
    'Read-only API smoke must use in-memory latches only'
);
$assertTrue(
    str_contains($source, 'Read-only API smoke report proof is invalid: ')
        && str_contains($source, 'Read-only API smoke report safety flag is invalid: ')
        && str_contains($source, "'private_config_changed',")
        && str_contains($source, "'http_route_added',")
        && str_contains($source, "'production_changed',")
        && str_contains($source, "(\$report['ok'] ?? null) !== true")
        && str_contains($source, "!is_int(\$report['state_revision'] ?? null)")
        && str_contains($source, "!is_int(\$report['outbox_event_count'] ?? null)")
        && str_contains($source, "(\$report['worker_tick_count'] ?? null) !== 0")
        && str_contains($source, 'result schema is invalid'),
    'Read-only API smoke CLI must validate every smoke proof and safety flag before publishing evidence'
);
$assertTrue(
    str_contains($source, 'Read-only API smoke overlay proof is invalid: ')
        && str_contains($source, 'Read-only API smoke overlay safety flag is invalid: ')
        && str_contains($source, "'persistent_config_changed',")
        && str_contains($source, "'selector_enabled_in_memory_only',")
        && str_contains($source, "(\$overlayReport['action'] ?? '') !== 'read_only_api_smoke_overlay_built'")
        && str_contains($source, "!is_int(\$overlayReport['baseline_state_revision'] ?? null)")
        && str_contains($source, "(\$overlayReport['ttl_seconds'] ?? null) !== \$ttlSeconds")
        && str_contains($source, 'overlay report schema is invalid'),
    'Read-only API smoke CLI must validate overlay identity, types, proofs and safety flags'
);
$assertTrue(
    str_contains($source, "'bootstrap_legacy_hook_count'")
        && str_contains($source, "'bootstrap_legacy_filter_count'")
        && str_contains($source, "'api_bootstrap_hooks_preserved' => true")
        && str_contains($source, "'api_bootstrap_filters_preserved' => true")
        && str_contains($source, "'projection_contract_version'")
        && str_contains($source, "'completed_events_lease_free'")
        && str_contains($source, "'json_default_verified'")
        && str_contains($source, "'rollback_data_dir_external'")
        && str_contains($source, "'rollback_data_dir_canonical'")
        && str_contains($source, "'worker_tick_count'")
        && str_contains($source, "'context_state_matched'")
        && str_contains($source, "'lifecycle_v4_verified'")
        && str_contains($source, "'legacy_json_bridges_suppressed'")
        && str_contains($source, "'state_unchanged'")
        && str_contains($source, "'snapshot_unchanged'")
        && str_contains($source, "'outbox_unchanged'")
        && str_contains($source, "'data_filters_unchanged'"),
    'Read-only API smoke output must prove real API bootstrap preservation, rollback safety and no mutation'
);
$assertTrue(
    str_contains($source, "'production_changed' => \$report['production_changed']")
        && str_contains($source, "'persistent_config_changed' => \$overlayReport['persistent_config_changed']")
        && str_contains($source, "'selector_enabled_in_memory_only' => \$overlayReport['selector_enabled_in_memory_only']")
        && str_contains($source, "'state_unchanged' => \$report['state_unchanged']"),
    'Published evidence must copy already-validated proofs instead of inventing safe constants'
);
$assertTrue(
    !str_contains($source, "require \$projectRoot . '/bot/api.php'")
        && !str_contains($source, "include \$projectRoot . '/bot/api.php'")
        && !str_contains($source, 'file_put_contents(')
        && !str_contains($source, 'rename(')
        && !str_contains($source, 'copy('),
    'Read-only API smoke must not execute an HTTP route or write config/report files'
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
    str_contains($source, '~/(?:home|var|tmp|srv|opt)/')
        && !str_contains($source, 'crontab')
        && !str_contains($source, 'production-cutover.php')
        && !str_contains($source, 'WebhookHandler'),
    'Read-only API smoke must sanitize private paths and avoid Cron, cutover and webhook code'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeCliContractTest passed: {$assertions} assertions.\n");
