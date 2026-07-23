<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$paths = [
    'factory' => 'bot/storage/StorageFactory.php',
    'guard' => 'bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php',
    'coordinator' => 'bot/runtime/ProductionPrimaryRuntimeCoordinator.php',
    'bootstrap' => 'bot/runtime/ProductionPrimaryEntrypointBootstrap.php',
    'context' => 'bot/runtime/ProductionPrimaryEntrypointStorageContext.php',
    'atomic' => 'bot/runtime/ProductionPrimaryAtomicStorageAdapter.php',
    'projector' => 'bot/runtime/ProductionPrimaryProjectorFactory.php',
    'router' => 'bot/storage/RuntimeStorageRouter.php',
    'api' => 'bot/api.php',
    'webhook' => 'bot/webhook.php',
    'handler' => 'bot/handlers/WebhookHandler.php',
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
$containsAll = static function (string $source, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) return false;
    }
    return true;
};

$assertTrue(
    $containsAll($sources['factory'], [
        "'api.php' => 'api'",
        "'webhook.php' => 'webhook'",
        'ProductionPrimaryEntrypointBootstrap::installIfEnabled(',
        'ProductionPrimaryEntrypointStorageContext::installed()',
        'RuntimePrimaryEntrypointStorageContext::installed()',
    ]),
    'StorageFactory must support both guarded production and staging request contexts'
);
$assertTrue(
    $containsAll($sources['api'], ['StorageFactory::createJson('])
        && $containsAll($sources['webhook'], ['StorageFactory::createJson('])
        && $containsAll($sources['handler'], ['StorageFactory::createJson('])
        && !str_contains($sources['api'], 'new JsonStorageAdapter(')
        && !str_contains($sources['webhook'], 'new JsonStorageAdapter(')
        && !str_contains($sources['handler'], 'new JsonStorageAdapter('),
    'API and webhook storage paths must not bypass StorageFactory'
);

$webhookStorage = strpos($sources['webhook'], 'StorageFactory::createJson(');
$webhookTelegram = strpos($sources['webhook'], 'new TelegramService(');
$assertTrue(
    $webhookStorage !== false
        && $webhookTelegram !== false
        && $webhookStorage < $webhookTelegram,
    'Webhook must install request storage before any guard or handler'
);
$assertTrue(
    $containsAll($sources['webhook'], [
        'mgw_webhook_success_hook',
        '$productionDbPrimaryRequested',
        'http_response_code($productionDbPrimaryRequested ? 503 : 200)',
        "'temporary failure' : 'ok'",
    ]),
    'Active production DB-primary webhook failures must remain retryable'
);
$assertTrue(
    $containsAll($sources['guard'], [
        "RuntimePrimaryEntrypointStorageContext', false",
        'RuntimePrimaryEntrypointStorageContext::installed()',
        "ProductionPrimaryEntrypointStorageContext', false",
        'ProductionPrimaryEntrypointStorageContext::installed()',
    ]),
    'Legacy bridges must be suppressed by either installed DB-primary context'
);

$activation = strpos($sources['bootstrap'], '))->inspect();');
$completed = strpos($sources['bootstrap'], "['state'] ?? '') !== 'completed'");
$database = strpos($sources['bootstrap'], 'PdoConnectionFactory::create($databaseConfig)');
$install = strpos($sources['bootstrap'], 'ProductionPrimaryEntrypointStorageContext::install(');
$assertTrue(
    $activation !== false
        && $completed !== false
        && $database !== false
        && $install !== false
        && $activation < $completed
        && $completed < $database
        && $database < $install,
    'Completed activation must be verified before DB contact and context installation'
);
$assertTrue(
    $containsAll($sources['bootstrap'], [
        'enablement and activation markers are inconsistent',
        "'api', 'webhook'",
        "fetchValue('SELECT 1')",
        'ProductionPrimaryAtomicStorageAdapter(',
        'RuntimePrimaryProjectionWorkerInterface.php',
        'RuntimePrimaryProjectionAuditorInterface.php',
    ]),
    'Production bootstrap must remain exact, dependency-complete and readiness-probed'
);

$outer = strpos($sources['atomic'], 'return $this->database->transaction(function (');
$state = strpos($sources['atomic'], '$this->stateStorage->transaction(');
$worker = strpos($sources['atomic'], '$this->worker->runOnce();');
$finalAudit = strpos($sources['atomic'], "captureAndAudit('final')");
$assertTrue(
    $outer !== false
        && $state !== false
        && $worker !== false
        && $finalAudit !== false
        && $outer < $state
        && $state < $worker
        && $worker < $finalAudit,
    'State, outbox projection and final audit must share one outer transaction'
);
$assertTrue(
    $containsAll($sources['atomic'], [
        'captureLockedBaseline($data)',
        "'baseline_locked' => true",
        "'worker_tick_count' => 1",
        "'rollback_requires_fresh_db_export' => true",
    ]),
    'Atomic adapter must prove lock, exact worker tick and rollback export requirement'
);
$assertTrue(
    $containsAll($sources['context'], [
        "['api', 'webhook']",
        "['state'] ?? '') !== 'completed'",
        'ProductionPrimaryAtomicStorageAdapter',
        "'atomic_projection' => true",
    ]),
    'Request context must accept only completed atomic API/webhook storage'
);
$assertTrue(
    $containsAll($sources['coordinator'], [
        'public const EXECUTION_ENABLED = false',
        'public const ENTRYPOINT_WIRING_ENABLED = true',
        "'atomic_state_and_projections_required' => true",
        'Direct production API execution is forbidden',
        'Direct production webhook execution is forbidden',
    ]),
    'Direct coordinator execution must remain forbidden'
);
$assertTrue(
    $containsAll($sources['router'], [
        "PRODUCTION_ACTIVATION_BUILD = 'v103-mvp14-production-cutover'",
        'private function productionAllowed(): bool',
        'production_activated',
        'activation_plan_fingerprint',
        'activation_source_fingerprint',
    ]),
    'Production runtime router must require the exact protected activation contract'
);
$assertTrue(
    $containsAll($sources['projector'], [
        "['state'] ?? '') !== 'completed'",
        "['environment'] = 'staging'",
        'RuntimePrimaryRepositoryProjectorFactory(',
    ]),
    'Production projector wrapper must bind completed activation before compatibility projection'
);

foreach (['bootstrap', 'context', 'atomic', 'projector'] as $name) {
    foreach (['file_put_contents(', 'rename(', 'unlink(', 'crontab', 'production-cutover.php', 'curl'] as $forbidden) {
        $assertTrue(
            !str_contains($sources[$name], $forbidden),
            'Production request runtime must not mutate files, Cron or cutover state: '
                . $name . ': ' . $forbidden
        );
    }
}
$assertTrue(
    !str_contains($sources['atomic'], 'JsonStorageAdapter')
        && !str_contains($sources['context'], 'JsonStorageAdapter'),
    'Production atomic runtime must not hide a JSON fallback'
);

fwrite(
    STDOUT,
    "ProductionPrimaryEntrypointWiringContractTest passed: {$assertions} assertions.\n"
);
