<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$paths = [
    'storage_factory' => 'bot/storage/StorageFactory.php',
    'bridge_guard' => 'bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php',
    'activation' => 'bot/runtime/ProductionPrimaryRuntimeActivationContract.php',
    'coordinator' => 'bot/runtime/ProductionPrimaryRuntimeCoordinator.php',
    'bootstrap' => 'bot/runtime/ProductionPrimaryEntrypointBootstrap.php',
    'context' => 'bot/runtime/ProductionPrimaryEntrypointStorageContext.php',
    'atomic' => 'bot/runtime/ProductionPrimaryAtomicStorageAdapter.php',
    'projector_factory' => 'bot/runtime/ProductionPrimaryProjectorFactory.php',
    'router' => 'bot/storage/RuntimeStorageRouter.php',
    'api' => 'bot/api.php',
    'webhook' => 'bot/webhook.php',
    'webhook_handler' => 'bot/handlers/WebhookHandler.php',
];
$sources = [];
foreach ($paths as $name => $relative) {
    $source = file_get_contents($projectRoot . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Production wiring source is unavailable: ' . $name . '.');
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$factory = $sources['storage_factory'];
$productionContext = strpos($factory, "class_exists('ProductionPrimaryEntrypointStorageContext', false)");
$stagingContext = strpos($factory, "class_exists('RuntimePrimaryEntrypointStorageContext', false)");
$productionBootstrap = strpos($factory, "require_once __DIR__ . '/../runtime/ProductionPrimaryEntrypointBootstrap.php';");
$stagingBootstrap = strpos($factory, "require_once __DIR__ . '/../runtime/RuntimePrimaryStagingEntrypointBootstrap.php';");
$assertTrue(
    $productionContext !== false && $stagingContext !== false
        && $productionContext < $stagingContext,
    'StorageFactory must prefer the completed production atomic context before staging context'
);
$assertTrue(
    $productionBootstrap !== false && $stagingBootstrap !== false
        && $productionBootstrap < $stagingBootstrap,
    'StorageFactory must branch production bootstrap before the staging selector'
);
$assertTrue(
    str_contains($factory, "'api.php' => 'api'")
        && str_contains($factory, "'webhook.php' => 'webhook'")
        && str_contains($factory, 'ProductionPrimaryEntrypointBootstrap::installIfEnabled('),
    'StorageFactory must wire both real entrypoint basenames through production bootstrap'
);

$assertTrue(
    str_contains($sources['api'], 'StorageFactory::createJson(')
        && str_contains($sources['webhook_handler'], 'StorageFactory::createJson(')
        && !str_contains($sources['api'], 'new JsonStorageAdapter(')
        && !str_contains($sources['webhook_handler'], 'new JsonStorageAdapter('),
    'Real API and webhook handler must pass all legacy storage construction through StorageFactory'
);
$assertTrue(
    str_contains($sources['webhook'], '$mgw_webhook_success_hook')
        && str_contains($sources['bridge_guard'], "'ProductionPrimaryEntrypointStorageContext'")
        && str_contains($sources['bridge_guard'], 'ProductionPrimaryEntrypointStorageContext::installed()')
        && str_contains($sources['bridge_guard'], 'return !$productionInstalled && !$stagingInstalled;'),
    'Legacy success bridges must be suppressed for both production and staging DB-primary contexts'
);

$bootstrap = $sources['bootstrap'];
$activationPosition = strpos($bootstrap, '))->inspect();');
$completedPosition = strpos($bootstrap, "(\$activation['state'] ?? '') !== 'completed'");
$databasePosition = strpos($bootstrap, 'PdoConnectionFactory::create($databaseConfig)');
$contextPosition = strpos($bootstrap, 'ProductionPrimaryEntrypointStorageContext::install(');
$assertTrue(
    $activationPosition !== false && $completedPosition !== false
        && $databasePosition !== false && $contextPosition !== false
        && $activationPosition < $completedPosition
        && $completedPosition < $databasePosition
        && $databasePosition < $contextPosition,
    'Production bootstrap must verify completed activation before database contact and context installation'
);
$assertTrue(
    str_contains($bootstrap, "if (\$enabled !== true && \$activated !== true)")
        && str_contains($bootstrap, 'enablement and activation markers are inconsistent')
        && str_contains($bootstrap, "'api', 'webhook'")
        && str_contains($bootstrap, "(int)\$database->fetchValue('SELECT 1') !== 1")
        && str_contains($bootstrap, 'ProductionPrimaryAtomicStorageAdapter('),
    'Production bootstrap must remain opt-in, exact, dual-entrypoint and readiness-probed'
);

$atomic = $sources['atomic'];
$outer = strpos($atomic, 'return $this->database->transaction(function (');
$state = strpos($atomic, '$result = $this->stateStorage->transaction(');
$worker = strpos($atomic, '$tick = $this->worker->runOnce();');
$final = strpos($atomic, "$final = \$this->captureAndAudit('final');");
$assertTrue(
    $outer !== false && $state !== false && $worker !== false && $final !== false
        && $outer < $state && $state < $worker && $worker < $final,
    'Production state write, outbox worker and final audit must execute inside one outer transaction'
);
$assertTrue(
    str_contains($atomic, '$baseline = $this->captureLockedBaseline($data);')
        && str_contains($atomic, "'baseline_locked' => true")
        && str_contains($atomic, "'worker_tick_count' => 1")
        && str_contains($atomic, "'rollback_requires_fresh_db_export' => true"),
    'Atomic adapter must lock baseline, require one exact worker tick and expose rollback export requirement'
);

$assertTrue(
    str_contains($sources['context'], "['api', 'webhook']")
        && str_contains($sources['context'], "(\$activationReport['state'] ?? '') !== 'completed'")
        && str_contains($sources['context'], 'ProductionPrimaryAtomicStorageAdapter')
        && str_contains($sources['context'], "'atomic_projection' => true"),
    'Production context must accept only completed API/webhook atomic storage'
);
$assertTrue(
    str_contains($sources['coordinator'], 'public const EXECUTION_ENABLED = false')
        && str_contains($sources['coordinator'], 'public const ENTRYPOINT_WIRING_ENABLED = true')
        && str_contains($sources['coordinator'], "'atomic_state_and_projections_required' => true")
        && str_contains($sources['coordinator'], 'Direct production API execution is forbidden')
        && str_contains($sources['coordinator'], 'Direct production webhook execution is forbidden'),
    'Coordinator must enable guarded wiring while forbidding direct execution bypasses'
);

$assertTrue(
    str_contains($sources['router'], "PRODUCTION_ACTIVATION_BUILD = 'v103-mvp14-production-cutover'")
        && str_contains($sources['router'], 'private function productionAllowed(): bool')
        && str_contains($sources['router'], "(\$settings['production_activated'] ?? null) !== true")
        && str_contains($sources['router'], "(\$modules[\$module] ?? null) !== true"),
    'Runtime router must require exact protected production activation and strict module booleans'
);
$assertTrue(
    str_contains($sources['projector_factory'], "(\$this->activationReport['state'] ?? '') !== 'completed'")
        && str_contains($sources['projector_factory'], "\$projectionConfig['environment'] = 'staging';")
        && str_contains($sources['projector_factory'], 'The production activation contract above is the')
        && str_contains($sources['projector_factory'], 'RuntimePrimaryRepositoryProjectorFactory('),
    'Production projector wrapper must bind completed activation before reusing proven compatibility projectors'
);

foreach (['bootstrap', 'context', 'atomic', 'projector_factory'] as $name) {
    foreach (['file_put_contents(', 'rename(', 'unlink(', 'crontab', 'production-cutover.php'] as $forbidden) {
        $assertTrue(
            !str_contains($sources[$name], $forbidden),
            'Production entrypoint runtime code must not change files, Cron or cutover state: '
                . $name . ': ' . $forbidden
        );
    }
}
$assertTrue(
    !str_contains($bootstrap, 'curl')
        && !str_contains($atomic, 'JsonStorageAdapter')
        && !str_contains($context, 'JsonStorageAdapter'),
    'Production entrypoint wiring must not call HTTP or instantiate a hidden JSON fallback'
);

fwrite(
    STDOUT,
    "ProductionPrimaryEntrypointWiringContractTest passed: {$assertions} assertions.\n"
);
