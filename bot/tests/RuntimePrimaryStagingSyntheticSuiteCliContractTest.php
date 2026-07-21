<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/run-staging-db-primary-synthetic-suite.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Synthetic staging CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$argumentGuard = strpos($source, 'count($argv ?? []) !== 1');
$bootstrap = strpos($source, "require \$projectRoot . '/bot/core/bootstrap.php';");
$environmentGuard = strpos($source, "if (\$environment !== 'staging')");
$privateGuard = strpos($source, 'RuntimePrimaryPrivateConfigGuard::assertExternal(');
$lockOpen = strpos($source, '$lockHandle = fopen($lockPath');
$databaseOpen = strpos($source, 'PdoConnectionFactory::create($databaseConfig)');
$assertTrue(
    $cliGuard !== false
        && $argumentGuard !== false
        && $bootstrap !== false
        && $environmentGuard !== false
        && $privateGuard !== false
        && $lockOpen !== false
        && $databaseOpen !== false
        && $cliGuard < $argumentGuard
        && $argumentGuard < $bootstrap
        && $bootstrap < $environmentGuard
        && $environmentGuard < $privateGuard
        && $privateGuard < $lockOpen
        && $lockOpen < $databaseOpen,
    'Synthetic CLI must enforce CLI, no arguments, staging, private config and lock before DB access'
);
$assertTrue(
    str_contains($source, 'runtime-primary-synthetic-suite.lock')
        && str_contains($source, 'LOCK_EX | LOCK_NB')
        && str_contains($source, '@chmod($lockPath, 0600)')
        && str_contains($source, 'Another staging synthetic suite is already running.'),
    'Synthetic CLI must serialize through a private 0600 lock'
);
$assertTrue(
    str_contains($source, 'StorageFactory::createJson(')
        && str_contains($source, 'RuntimePrimaryRepositoryProjectorFactory(')
        && str_contains($source, 'RuntimePrimaryStagingStorageResolver(')
        && str_contains($source, 'RuntimePrimaryStagingSyntheticSuite(')
        && str_contains($source, '->run()'),
    'Synthetic CLI must use guarded resolution and the rollback-only suite'
);
$assertTrue(
    !str_contains($source, 'StorageFactory::createDatabasePrimary(')
        && !str_contains($source, 'bot/api.php')
        && !str_contains($source, 'WebhookHandler.php')
        && !str_contains($source, 'crontab')
        && !str_contains($source, 'production-cutover.php'),
    'Synthetic CLI must not route real entrypoints or touch production controls'
);
$assertTrue(
    str_contains($source, "'action' => 'synthetic_suite_blocked_or_failed'")
        && str_contains($source, "'transaction_rolled_back' => null")
        && str_contains($source, "'application_entrypoint_routed' => false")
        && str_contains($source, "'application_entrypoints_changed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false"),
    'Synthetic CLI failure report must remain explicit and non-routing'
);
$assertTrue(
    !str_contains($source, "'host' =>")
        && !str_contains($source, "'database' =>")
        && !str_contains($source, "'username' =>")
        && !str_contains($source, "'password' =>")
        && !str_contains($source, "'private_dir' =>")
        && !str_contains($source, "'evidence_file' =>"),
    'Synthetic CLI must not print connection identifiers, secrets or private paths'
);

fwrite(STDOUT, "RuntimePrimaryStagingSyntheticSuiteCliContractTest passed: {$assertions} assertions.\n");
