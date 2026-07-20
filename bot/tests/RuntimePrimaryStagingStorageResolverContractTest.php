<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$resolver = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingStorageResolver.php');
$resolution = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingStorageResolution.php');
if (!is_string($resolver) || !is_string($resolution)) {
    throw new RuntimeException('Staging storage resolver sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($resolver, "if (\$environment !== 'staging')")
        && str_contains($resolver, 'DB-primary storage resolution is staging-only.'),
    'Resolver must remain staging-only'
);
$assertTrue(
    str_contains($resolver, 'RuntimePrimaryStagingStorageResolverConfig::fromApplicationConfig(')
        && str_contains($resolver, '->assertEnabled()'),
    'Resolver must require its independent latch'
);
$assertTrue(
    str_contains($resolver, 'RuntimePrimaryStagingActivationGuard(')
        && str_contains($resolver, '->assertReady()'),
    'Resolver must require the complete readiness guard'
);
$assertTrue(
    str_contains($resolver, 'new DatabasePrimaryStateStorageAdapter(')
        && str_contains($resolver, '$this->database,')
        && str_contains($resolver, 'new RuntimePrimaryProjectionOutboxWriter()'),
    'Resolver must use the audited DB connection and outbox writer'
);
$assertTrue(
    str_contains($resolver, '$storage->status()')
        && str_contains($resolver, 'DB-primary storage changed after staging activation readiness.'),
    'Resolver must re-check revision and SHA after readiness'
);
$assertTrue(
    !str_contains($resolver, 'StorageFactory::createDatabasePrimary(')
        && !str_contains($resolver, 'PdoConnectionFactory::create(')
        && !str_contains($resolver, '->execute(')
        && !str_contains($resolver, '->transaction('),
    'Resolver must not open another connection or mutate state'
);
$assertTrue(
    str_contains($resolution, "'application_entrypoint_routed' => false")
        && str_contains($resolution, "'application_entrypoints_changed' => false")
        && str_contains($resolution, "'cron_changed' => false")
        && str_contains($resolution, "'production_changed' => false"),
    'Resolution report must state that nothing is routed or changed'
);
$assertTrue(
    str_contains($resolution, "'rollback_driver' => 'json'")
        && str_contains($resolution, "'projection_outbox_enabled' => true")
        && str_contains($resolution, "'read_only_readiness_audit' => true")
        && str_contains($resolution, "'drift_check_passed' => true"),
    'Resolution report must preserve rollback and readiness evidence'
);
$assertTrue(
    !str_contains($resolver, 'bot/api.php')
        && !str_contains($resolver, 'WebhookHandler.php')
        && !str_contains($resolver, 'crontab')
        && !str_contains($resolution, 'bot/api.php')
        && !str_contains($resolution, 'WebhookHandler.php'),
    'Resolver must remain disconnected from application entrypoints'
);

fwrite(STDOUT, "RuntimePrimaryStagingStorageResolverContractTest passed: {$assertions} assertions.\n");
