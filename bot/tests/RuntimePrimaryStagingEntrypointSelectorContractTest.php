<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$bootstrap = file_get_contents($projectRoot . '/bot/core/bootstrap.php');
$selectorBootstrap = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php');
$bridgeGuard = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php');
$factory = file_get_contents($projectRoot . '/bot/storage/StorageFactory.php');
$selector = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php');
$context = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php');
$coordinator = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php');
$hook = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php');
$v4 = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Verifier.php');
if (!is_string($bootstrap) || !is_string($selectorBootstrap) || !is_string($bridgeGuard)
    || !is_string($factory) || !is_string($selector) || !is_string($context)
    || !is_string($coordinator) || !is_string($hook) || !is_string($v4)) {
    throw new RuntimeException('Staging API lifecycle selector sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($factory, 'installGuardedEntrypointContextIfEligible()')
        && str_contains($factory, "'api.php' => 'api'")
        && str_contains($factory, "'webhook.php' => 'webhook'")
        && str_contains($factory, "default => ''"),
    'StorageFactory must bound lazy selection to API or webhook script names'
);
$selectorMethodStart = strpos(
    $factory,
    'private static function installGuardedEntrypointContextIfEligible()'
);
$stickyFailureGuard = $selectorMethodStart === false
    ? false
    : strpos($factory, 'if (isset($failures[$entrypoint]))', $selectorMethodStart);
$contextReuseGuard = $selectorMethodStart === false
    ? false
    : strpos(
        $factory,
        'RuntimePrimaryEntrypointStorageContext::installed()',
        $selectorMethodStart
    );
$assertTrue(
    $selectorMethodStart !== false
        && $stickyFailureGuard !== false
        && $contextReuseGuard !== false
        && $stickyFailureGuard < $contextReuseGuard,
    'Sticky selector failures must be checked before request context reuse inside the guarded selector method'
);
$assertTrue(
    str_contains($selector, "if (\$environment !== 'staging')")
        && str_contains($selector, "if (\$this->entrypoint === 'webhook')")
        && str_contains($selector, 'DB-primary webhook routing is not allowed')
        && str_contains($selector, 'RuntimePrimaryStagingApiSessionCoordinator('),
    'Selector must remain staging-only, API-only and delegate to coordinator'
);
$assertTrue(
    str_contains($selectorBootstrap, 'RuntimePrimaryStagingEvidenceV4Verifier.php')
        && str_contains($selectorBootstrap, 'RuntimePrimaryStagingRequestSessionReadiness.php')
        && str_contains($selectorBootstrap, 'RuntimePrimaryStagingRequestFinalizer.php')
        && str_contains($selectorBootstrap, 'RuntimePrimaryStagingApiSessionCoordinator.php'),
    'Inert selector bootstrap must load the complete lifecycle contour'
);
$assertTrue(
    str_contains($coordinator, 'RuntimePrimaryStagingEvidenceV4Gate(')
        && strpos($coordinator, 'RuntimePrimaryStagingEvidenceV4Gate(')
            < strpos($coordinator, 'PdoConnectionFactory::create($databaseConfig)')
        && str_contains($coordinator, 'RuntimePrimaryStagingRequestSessionReadiness('),
    'Coordinator must verify lifecycle v4 and dynamic readiness before DB routing'
);
$hookPrepared = strpos($coordinator, 'array_unshift($hooks, $hook)');
$contextInstalled = strpos($coordinator, 'RuntimePrimaryEntrypointStorageContext::install(');
$hooksPublished = strpos($coordinator, "\$GLOBALS['mgw_api_success_hooks'] = \$hooks;");
$assertTrue(
    $hookPrepared !== false
        && $contextInstalled !== false
        && $hooksPublished !== false
        && $hookPrepared < $contextInstalled
        && $contextInstalled < $hooksPublished,
    'Coordinator must prepare finalizer, install context and then publish hooks'
);
$assertTrue(
    str_contains($context, 'requires lifecycle evidence v4')
        && str_contains($context, 'request_finalizer_registered')
        && str_contains($context, 'dynamic_session_readiness')
        && str_contains($context, "if (\$entrypoint !== 'api')")
        && str_contains($context, "'request_session_evidence_fingerprint'"),
    'Request context must independently require API-only lifecycle v4'
);
$assertTrue(
    str_contains($hook, 'was invoked more than once')
        && str_contains($hook, 'lost its guarded storage context')
        && str_contains($hook, "(\$report['projection_event_status'] ?? '') !== 'completed'")
        && str_contains($hook, "(\$report['webhook_allowed'] ?? true) !== false")
        && str_contains($hook, "(\$report['production_changed'] ?? true) !== false"),
    'Finalization hook must be once-only and fail closed on incomplete completion'
);
$assertTrue(
    str_contains($context, "'legacy_json_bridges_suppressed' => true")
        && str_contains($bridgeGuard, 'legacyJsonBridgeAllowed()')
        && substr_count($bootstrap, 'RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()') >= 8,
    'DB-primary API requests must suppress every legacy JSON bridge'
);
$assertTrue(
    str_contains($v4, "public const MANIFEST_VERSION = 'v4-staging-db-primary-api-lifecycle-evidence'")
        && str_contains($v4, 'RuntimePrimaryStagingRequestLifecycleEvidence::inspect(')
        && str_contains($v4, 'does not match the current repository sources'),
    'Evidence v4 must recompute lifecycle evidence from current checkout'
);
$assertTrue(
    !str_contains($selector, 'crontab')
        && !str_contains($selector, 'production-cutover.php')
        && !str_contains($coordinator, 'crontab')
        && !str_contains($coordinator, 'production-cutover.php')
        && !str_contains($hook, 'WebhookHandler'),
    'API lifecycle integration must not touch Cron, webhook handler or production cutover'
);

fwrite(STDOUT, "RuntimePrimaryStagingEntrypointSelectorContractTest passed: {$assertions} assertions.\n");
