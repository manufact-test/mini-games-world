<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$cli = file_get_contents(
    $projectRoot . '/ops/runtime/run-staging-db-primary-api-read-only-smoke.php'
);
$bootstrap = file_get_contents($projectRoot . '/bot/core/bootstrap.php');
$requestGuard = file_get_contents($projectRoot . '/bot/core/RuntimeRequestGuard.php');
$bridgeGuard = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php'
);
if (!is_string($cli) || !is_string($bootstrap)
    || !is_string($requestGuard) || !is_string($bridgeGuard)) {
    throw new RuntimeException('Read-only API bootstrap contract sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$scriptOverride = strpos(
    $cli,
    "\$_SERVER['SCRIPT_FILENAME'] = \$projectRoot . '/bot/api.php';"
);
$bootstrapRequire = strpos(
    $cli,
    "require \$projectRoot . '/bot/core/bootstrap.php';"
);
$assertTrue(
    $scriptOverride !== false
        && $bootstrapRequire !== false
        && $scriptOverride < $bootstrapRequire,
    'Smoke CLI must select the API basename before loading the real bootstrap'
);
$assertTrue(
    str_contains($requestGuard, "if (\$method === '' || PHP_SAPI === 'cli') return;")
        && str_contains($requestGuard, "if (!in_array(\$script, ['api.php', 'invites.php'], true)) return;"),
    'Runtime request guard must explicitly avoid HTTP enforcement in CLI'
);
$assertTrue(
    str_contains($bootstrap, "\$runtimeScript === 'api.php'")
        && substr_count(
            $bootstrap,
            'RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()'
        ) >= 8,
    'Real API bootstrap bridges and filters must be guarded at invocation time'
);
$assertTrue(
    str_contains($bootstrap, "\$GLOBALS['mgw_api_success_hooks'] = \$runtimeApiSuccessHooks;")
        && str_contains($bootstrap, "\$GLOBALS['mgw_api_data_filters'] = \$runtimeApiDataFilters;"),
    'Real API bootstrap must publish its hook and filter registries'
);
$assertTrue(
    str_contains($cli, "\$bootstrapHooks = \$GLOBALS['mgw_api_success_hooks'] ?? [];")
        && str_contains($cli, "\$bootstrapFilters = \$GLOBALS['mgw_api_data_filters'] ?? [];")
        && str_contains($cli, 'count($hooks) !== $bootstrapHookCount + 1')
        && str_contains($cli, 'count($filters) !== $bootstrapFilterCount'),
    'Smoke CLI must preserve bootstrap registries and add exactly one finalizer'
);
$assertTrue(
    str_contains(
        $bridgeGuard,
        "!class_exists('RuntimePrimaryEntrypointStorageContext', false)"
    ) && str_contains(
        $bridgeGuard,
        '!RuntimePrimaryEntrypointStorageContext::installed()'
    ) && str_contains(
        $bridgeGuard,
        "return !class_exists('RuntimePrimaryEntrypointStorageContext', false)"
    ),
    'Legacy bridge guard must allow unloaded context and disable bridges after DB context installation'
);
$assertTrue(
    !str_contains($cli, "require \$projectRoot . '/bot/api.php'")
        && !str_contains($cli, "include \$projectRoot . '/bot/api.php'"),
    'Smoke CLI must not execute the API endpoint itself'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeBootstrapContractTest passed: {$assertions} assertions.\n");
