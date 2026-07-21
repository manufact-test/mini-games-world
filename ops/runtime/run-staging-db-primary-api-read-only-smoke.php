<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$evidenceFile = '';
$ttlSeconds = 300;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--evidence=')) {
        if ($evidenceFile !== '') {
            fwrite(STDERR, "--evidence may be specified only once.\n");
            exit(2);
        }
        $evidenceFile = str_replace(
            '\\',
            '/',
            trim(substr($argument, strlen('--evidence=')))
        );
        continue;
    }
    if (str_starts_with($argument, '--ttl-seconds=')) {
        $raw = trim(substr($argument, strlen('--ttl-seconds=')));
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            fwrite(STDERR, "--ttl-seconds must be an integer.\n");
            exit(2);
        }
        $ttlSeconds = (int)$raw;
        continue;
    }
    fwrite(STDERR, "Unknown read-only API smoke argument.\n");
    exit(2);
}
if ($evidenceFile === '' || !str_starts_with($evidenceFile, '/')) {
    fwrite(STDERR, "Read-only API smoke requires --evidence=/absolute/private/evidence-v4.json.\n");
    exit(2);
}
if (is_link($evidenceFile)) {
    fwrite(STDERR, "Read-only API smoke evidence must not be a symbolic link.\n");
    exit(2);
}
if ($ttlSeconds < 60 || $ttlSeconds > 600) {
    fwrite(STDERR, "--ttl-seconds must be between 60 and 600.\n");
    exit(2);
}

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
$lockPath = '';
$exitCode = 1;
$outputPayload = [];

try {
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
    @chmod($lockPath, 0600);
    clearstatcache(true, $lockPath);
    if (is_link($lockPath)) {
        throw new RuntimeException('Read-only API smoke lock became a symbolic link.');
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

    $outputPayload = [
        'ok' => true,
        'report_type' => 'mvp-14.8.6n-staging-api-read-only-smoke',
        'action' => (string)($report['action'] ?? ''),
        'state_revision' => (int)($report['state_revision'] ?? 0),
        'state_sha256' => (string)($report['state_sha256'] ?? ''),
        'projection_contract_version' => (string)(
            $report['projection_contract_version'] ?? ''
        ),
        'outbox_event_count' => (int)($report['outbox_event_count'] ?? 0),
        'outbox_fingerprint' => (string)($report['outbox_fingerprint'] ?? ''),
        'top_level_count' => (int)($report['top_level_count'] ?? 0),
        'top_level_keys_fingerprint' => (string)(
            $report['top_level_keys_fingerprint'] ?? ''
        ),
        'evidence_manifest_version' => (string)(
            $overlayReport['evidence_manifest_version'] ?? ''
        ),
        'evidence_fingerprint' => (string)(
            $overlayReport['evidence_fingerprint'] ?? ''
        ),
        'repository_commit' => (string)($overlayReport['repository_commit'] ?? ''),
        'database_identity_fingerprint' => (string)(
            $overlayReport['database_identity_fingerprint'] ?? ''
        ),
        'ttl_seconds' => (int)($overlayReport['ttl_seconds'] ?? 0),
        'json_default_verified' => ($overlayReport['json_default_verified'] ?? false) === true,
        'rollback_data_dir_external' => (
            $overlayReport['rollback_data_dir_external'] ?? false
        ) === true,
        'rollback_data_dir_canonical' => (
            $overlayReport['rollback_data_dir_canonical'] ?? false
        ) === true,
        'bootstrap_legacy_hook_count' => $bootstrapHookCount,
        'bootstrap_legacy_filter_count' => $bootstrapFilterCount,
        'api_bootstrap_hooks_preserved' => true,
        'api_bootstrap_filters_preserved' => true,
        'worker_tick_count' => (int)($report['worker_tick_count'] ?? -1),
        'context_state_matched' => ($report['context_state_matched'] ?? false) === true,
        'lifecycle_v4_verified' => ($report['lifecycle_v4_verified'] ?? false) === true,
        'legacy_json_bridges_suppressed' => (
            $report['legacy_json_bridges_suppressed'] ?? false
        ) === true,
        'completed_events_lease_free' => (
            $report['completed_events_lease_free'] ?? false
        ) === true,
        'state_unchanged' => ($report['state_unchanged'] ?? false) === true,
        'snapshot_unchanged' => ($report['snapshot_unchanged'] ?? false) === true,
        'outbox_unchanged' => ($report['outbox_unchanged'] ?? false) === true,
        'data_filters_unchanged' => ($report['data_filters_unchanged'] ?? false) === true,
        'request_finalizer_completed' => ($report['request_finalizer_completed'] ?? false) === true,
        'persistent_config_changed' => false,
        'selector_enabled_in_memory_only' => true,
        'request_session_enabled_in_memory_only' => true,
        'activation_enabled_in_memory_only' => true,
        'http_route_added' => false,
        'api_only' => true,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    $exitCode = 0;
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv)/[^\\s'\"]+~",
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
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
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
