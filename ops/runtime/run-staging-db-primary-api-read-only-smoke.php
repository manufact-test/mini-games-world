<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'evidence' => '',
    'ttl' => '300',
];
$seen = [];
$prefixes = [
    '--evidence=' => 'evidence',
    '--ttl-seconds=' => 'ttl',
];
foreach (array_slice($argv ?? [], 1) as $argument) {
    $matchedName = '';
    $matchedPrefix = '';
    foreach ($prefixes as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $matchedName = $name;
        $matchedPrefix = $prefix;
        break;
    }
    if ($matchedName === '') {
        fwrite(STDERR, "Unknown read-only API smoke argument.\n");
        exit(2);
    }
    if (isset($seen[$matchedName])) {
        fwrite(STDERR, "Read-only API smoke option may be specified only once: {$matchedPrefix}\n");
        exit(2);
    }
    $seen[$matchedName] = true;
    $value = substr($argument, strlen($matchedPrefix));
    if ($matchedName === 'evidence' && str_contains($value, '\\')) {
        fwrite(STDERR, "Read-only API smoke evidence path must not contain backslashes.\n");
        exit(2);
    }
    $values[$matchedName] = $value;
}

if (!isset($seen['evidence'])
    || $values['evidence'] === ''
    || !str_starts_with($values['evidence'], '/')) {
    fwrite(STDERR, "Read-only API smoke requires --evidence=/absolute/private/evidence-v4.json.\n");
    exit(2);
}
if ($values['ttl'] === '' || preg_match('/^\d+$/', $values['ttl']) !== 1) {
    fwrite(STDERR, "--ttl-seconds must be an integer.\n");
    exit(2);
}
$evidenceFile = $values['evidence'];
$ttlSeconds = (int)$values['ttl'];
if ($ttlSeconds < 60 || $ttlSeconds > 600) {
    fwrite(STDERR, "--ttl-seconds must be between 60 and 600.\n");
    exit(2);
}

umask(0077);
set_error_handler(static function (int $severity): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed read-only API smoke warning.', 0, $severity);
    }
    throw new RuntimeException('Read-only API smoke filesystem operation failed.');
});

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
$originalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
$originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
$originalConfigExists = array_key_exists('config', $GLOBALS);
$originalConfig = $GLOBALS['config'] ?? null;
$originalConfigFileExists = array_key_exists('configFile', $GLOBALS);
$originalConfigFile = $GLOBALS['configFile'] ?? null;
$originalHooksExists = array_key_exists('mgw_api_success_hooks', $GLOBALS);
$originalHooks = $GLOBALS['mgw_api_success_hooks'] ?? null;
$originalFiltersExists = array_key_exists('mgw_api_data_filters', $GLOBALS);
$originalFilters = $GLOBALS['mgw_api_data_filters'] ?? null;
$originalFinalizerExists = array_key_exists('mgw_api_db_primary_finalization_hook', $GLOBALS);
$originalFinalizer = $GLOBALS['mgw_api_db_primary_finalization_hook'] ?? null;
$originalFinalizerReportExists = array_key_exists(
    'mgw_api_db_primary_finalization_report',
    $GLOBALS
);
$originalFinalizerReport = $GLOBALS['mgw_api_db_primary_finalization_report'] ?? null;
$lockHandle = null;
$exitCode = 1;
$outputPayload = [];

try {
    if (is_link($evidenceFile)) {
        throw new RuntimeException('Read-only API smoke evidence must not be a symbolic link.');
    }

    $_SERVER['SCRIPT_FILENAME'] = $projectRoot . '/bot/api.php';
    $_SERVER['PHP_SELF'] = '/bot/api.php';
    unset(
        $GLOBALS['mgw_api_success_hooks'],
        $GLOBALS['mgw_api_data_filters'],
        $GLOBALS['mgw_api_db_primary_finalization_hook'],
        $GLOBALS['mgw_api_db_primary_finalization_report']
    );

    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmoke.php';

    $bootstrapHooks = $GLOBALS['mgw_api_success_hooks'] ?? [];
    $bootstrapFilters = $GLOBALS['mgw_api_data_filters'] ?? [];
    if (!is_array($bootstrapHooks) || !is_array($bootstrapFilters)) {
        throw new RuntimeException('Read-only API smoke bootstrap hook or filter registry is invalid.');
    }
    $bootstrapHookCount = count($bootstrapHooks);
    $bootstrapFilterCount = count($bootstrapFilters);

    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        throw new RuntimeException('Read-only API smoke is staging-only.');
    }
    $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
        (string)($configFile ?? ''),
        $projectRoot
    );
    $privateDir = (string)($private['private_dir'] ?? '');
    $lockPath = $privateDir . '/runtime-primary-rehearsal.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Read-only API smoke lock must not be a symbolic link.');
    }
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle)) {
        throw new RuntimeException('Read-only API smoke lock is unavailable.');
    }
    if (!chmod($lockPath, 0600)) {
        throw new RuntimeException('Read-only API smoke lock permissions could not be secured.');
    }
    clearstatcache(true, $lockPath);
    $lockMode = fileperms($lockPath);
    if (is_link($lockPath)
        || !is_int($lockMode)
        || ($lockMode & 0777) !== 0600) {
        throw new RuntimeException('Read-only API smoke lock must have exact mode 0600.');
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another rehearsal, evidence collector or API smoke is already running.');
    }

    $overlayResult = (new RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(
        $projectRoot,
        $config,
        (string)$configFile,
        $evidenceFile,
        $ttlSeconds
    ))->build(time());
    $overlay = is_array($overlayResult['config'] ?? null)
        ? $overlayResult['config']
        : [];
    if ($overlay === []) {
        throw new RuntimeException('Read-only API smoke config overlay is empty.');
    }

    $GLOBALS['config'] = $overlay;
    $GLOBALS['configFile'] = (string)$configFile;
    unset(
        $GLOBALS['mgw_api_db_primary_finalization_hook'],
        $GLOBALS['mgw_api_db_primary_finalization_report']
    );

    $storage = StorageFactory::createJson((string)($overlay['data_dir'] ?? ''));
    if (!$storage instanceof DatabasePrimaryStateStorageAdapter
        || $storage->driver() !== 'database') {
        throw new RuntimeException('Read-only API smoke did not resolve DB-primary API storage.');
    }
    $hooks = $GLOBALS['mgw_api_success_hooks'] ?? [];
    $filters = $GLOBALS['mgw_api_data_filters'] ?? [];
    if (!is_array($hooks) || !is_array($filters)
        || count($hooks) !== $bootstrapHookCount + 1
        || count($filters) !== $bootstrapFilterCount) {
        throw new RuntimeException('Read-only API smoke did not preserve the real API bootstrap hook contour.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($overlay);
    $inspectorDatabase = PdoConnectionFactory::create($databaseConfig);
    $report = (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage,
        $inspectorDatabase,
        $hooks,
        $filters
    ))->run();
    $overlayReport = is_array($overlayResult['report'] ?? null)
        ? $overlayResult['report']
        : [];

    foreach ([
        'context_state_matched',
        'lifecycle_v4_verified',
        'legacy_json_bridges_suppressed',
        'completed_events_lease_free',
        'state_unchanged',
        'snapshot_unchanged',
        'outbox_unchanged',
        'data_filters_unchanged',
        'request_finalizer_completed',
    ] as $field) {
        if (($report[$field] ?? null) !== true) {
            throw new RuntimeException('Read-only API smoke report proof is invalid: ' . $field . '.');
        }
    }
    foreach ([
        'private_config_changed',
        'http_route_added',
        'webhook_allowed',
        'cron_changed',
        'production_changed',
        'sensitive_identifiers_exposed',
    ] as $field) {
        if (($report[$field] ?? null) !== false) {
            throw new RuntimeException('Read-only API smoke report safety flag is invalid: ' . $field . '.');
        }
    }
    if (($report['ok'] ?? null) !== true
        || ($report['action'] ?? '') !== 'staging_api_read_only_smoke_passed'
        || !is_int($report['state_revision'] ?? null)
        || $report['state_revision'] < 1
        || preg_match('/^[a-f0-9]{64}$/', (string)($report['state_sha256'] ?? '')) !== 1
        || ($report['projection_contract_version'] ?? '')
            !== RuntimePrimaryAllModuleProjector::CONTRACT_VERSION
        || !is_int($report['outbox_event_count'] ?? null)
        || $report['outbox_event_count'] !== $report['state_revision']
        || preg_match('/^[a-f0-9]{64}$/', (string)($report['outbox_fingerprint'] ?? '')) !== 1
        || !is_int($report['top_level_count'] ?? null)
        || $report['top_level_count'] < 1
        || $report['top_level_count'] > 256
        || preg_match('/^[a-f0-9]{64}$/', (string)($report['top_level_keys_fingerprint'] ?? '')) !== 1
        || ($report['worker_tick_count'] ?? null) !== 0) {
        throw new RuntimeException('Read-only API smoke result schema is invalid.');
    }

    foreach ([
        'json_default_verified',
        'rollback_data_dir_external',
        'rollback_data_dir_canonical',
        'selector_enabled_in_memory_only',
        'request_session_enabled_in_memory_only',
        'activation_enabled_in_memory_only',
        'api_only',
    ] as $field) {
        if (($overlayReport[$field] ?? null) !== true) {
            throw new RuntimeException('Read-only API smoke overlay proof is invalid: ' . $field . '.');
        }
    }
    foreach ([
        'persistent_config_changed',
        'webhook_allowed',
        'cron_changed',
        'production_changed',
        'sensitive_identifiers_exposed',
    ] as $field) {
        if (($overlayReport[$field] ?? null) !== false) {
            throw new RuntimeException('Read-only API smoke overlay safety flag is invalid: ' . $field . '.');
        }
    }
    if (($overlayReport['ok'] ?? null) !== true
        || ($overlayReport['action'] ?? '') !== 'read_only_api_smoke_overlay_built'
        || ($overlayReport['evidence_manifest_version'] ?? '')
            !== RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION
        || preg_match('/^[a-f0-9]{64}$/', (string)($overlayReport['evidence_fingerprint'] ?? '')) !== 1
        || preg_match('/^[a-f0-9]{64}$/', (string)($overlayReport['database_identity_fingerprint'] ?? '')) !== 1
        || preg_match('/^[a-f0-9]{40}$/', (string)($overlayReport['repository_commit'] ?? '')) !== 1
        || !is_int($overlayReport['baseline_state_revision'] ?? null)
        || $overlayReport['baseline_state_revision'] < 1
        || preg_match('/^[a-f0-9]{64}$/', (string)($overlayReport['baseline_state_sha256'] ?? '')) !== 1
        || ($overlayReport['ttl_seconds'] ?? null) !== $ttlSeconds) {
        throw new RuntimeException('Read-only API smoke overlay report schema is invalid.');
    }

    $outputPayload = [
        'ok' => true,
        'report_type' => 'mvp-14.8.6n-staging-api-read-only-smoke',
        'action' => $report['action'],
        'state_revision' => $report['state_revision'],
        'state_sha256' => $report['state_sha256'],
        'projection_contract_version' => $report['projection_contract_version'],
        'outbox_event_count' => $report['outbox_event_count'],
        'outbox_fingerprint' => $report['outbox_fingerprint'],
        'top_level_count' => $report['top_level_count'],
        'top_level_keys_fingerprint' => $report['top_level_keys_fingerprint'],
        'evidence_manifest_version' => $overlayReport['evidence_manifest_version'],
        'evidence_fingerprint' => $overlayReport['evidence_fingerprint'],
        'repository_commit' => $overlayReport['repository_commit'],
        'database_identity_fingerprint' => $overlayReport['database_identity_fingerprint'],
        'ttl_seconds' => $overlayReport['ttl_seconds'],
        'json_default_verified' => $overlayReport['json_default_verified'],
        'rollback_data_dir_external' => $overlayReport['rollback_data_dir_external'],
        'rollback_data_dir_canonical' => $overlayReport['rollback_data_dir_canonical'],
        'bootstrap_legacy_hook_count' => $bootstrapHookCount,
        'bootstrap_legacy_filter_count' => $bootstrapFilterCount,
        'api_bootstrap_hooks_preserved' => true,
        'api_bootstrap_filters_preserved' => true,
        'worker_tick_count' => $report['worker_tick_count'],
        'context_state_matched' => $report['context_state_matched'],
        'lifecycle_v4_verified' => $report['lifecycle_v4_verified'],
        'legacy_json_bridges_suppressed' => $report['legacy_json_bridges_suppressed'],
        'completed_events_lease_free' => $report['completed_events_lease_free'],
        'state_unchanged' => $report['state_unchanged'],
        'snapshot_unchanged' => $report['snapshot_unchanged'],
        'outbox_unchanged' => $report['outbox_unchanged'],
        'data_filters_unchanged' => $report['data_filters_unchanged'],
        'request_finalizer_completed' => $report['request_finalizer_completed'],
        'persistent_config_changed' => $overlayReport['persistent_config_changed'],
        'selector_enabled_in_memory_only' => $overlayReport['selector_enabled_in_memory_only'],
        'request_session_enabled_in_memory_only' => $overlayReport['request_session_enabled_in_memory_only'],
        'activation_enabled_in_memory_only' => $overlayReport['activation_enabled_in_memory_only'],
        'http_route_added' => $report['http_route_added'],
        'api_only' => $overlayReport['api_only'],
        'webhook_allowed' => $report['webhook_allowed'],
        'cron_changed' => $report['cron_changed'],
        'production_changed' => $report['production_changed'],
        'sensitive_identifiers_exposed' => $report['sensitive_identifiers_exposed'],
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    $exitCode = 0;
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    $outputPayload = [
        'ok' => false,
        'report_type' => 'mvp-14.8.6n-staging-api-read-only-smoke',
        'action' => 'staging_api_read_only_smoke_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'persistent_config_changed' => false,
        'http_route_added' => false,
        'api_only' => true,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    $exitCode = 1;
} finally {
    if (is_resource($lockHandle)) {
        try {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        } catch (Throwable) {
            // Process exit is the final release fallback; never expose the private lock path.
        }
    }
    if ($originalScriptFilename === null) unset($_SERVER['SCRIPT_FILENAME']);
    else $_SERVER['SCRIPT_FILENAME'] = $originalScriptFilename;
    if ($originalPhpSelf === null) unset($_SERVER['PHP_SELF']);
    else $_SERVER['PHP_SELF'] = $originalPhpSelf;
    if ($originalConfigExists) $GLOBALS['config'] = $originalConfig;
    else unset($GLOBALS['config']);
    if ($originalConfigFileExists) $GLOBALS['configFile'] = $originalConfigFile;
    else unset($GLOBALS['configFile']);
    if ($originalHooksExists) $GLOBALS['mgw_api_success_hooks'] = $originalHooks;
    else unset($GLOBALS['mgw_api_success_hooks']);
    if ($originalFiltersExists) $GLOBALS['mgw_api_data_filters'] = $originalFilters;
    else unset($GLOBALS['mgw_api_data_filters']);
    if ($originalFinalizerExists) {
        $GLOBALS['mgw_api_db_primary_finalization_hook'] = $originalFinalizer;
    } else {
        unset($GLOBALS['mgw_api_db_primary_finalization_hook']);
    }
    if ($originalFinalizerReportExists) {
        $GLOBALS['mgw_api_db_primary_finalization_report'] = $originalFinalizerReport;
    } else {
        unset($GLOBALS['mgw_api_db_primary_finalization_report']);
    }
    restore_error_handler();
}

try {
    $encoded = json_encode(
        $outputPayload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
} catch (Throwable) {
    $encoded = '{"ok":false,"action":"staging_api_read_only_smoke_output_failed"}';
    $exitCode = 1;
}
fwrite(STDOUT, $encoded . PHP_EOL);
exit($exitCode);
