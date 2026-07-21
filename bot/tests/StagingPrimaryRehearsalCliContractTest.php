<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/staging-db-primary-rehearsal.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging rehearsal CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$bootstrap = strpos($source, "require \$projectRoot . '/bot/core/bootstrap.php';");
$assertTrue(
    $cliGuard !== false && $bootstrap !== false && $cliGuard < $bootstrap,
    'CLI-only guard must run before application bootstrap'
);
$assertTrue(
    str_contains($source, "['--status', '--install', '--seed', '--run-once', '--rehearse']"),
    'CLI must expose only the five explicit rehearsal modes'
);
$assertTrue(
    str_contains($source, "if (\$mode !== 'rehearse' && \$maxEvents !== 20)")
        && str_contains($source, '--max-events is supported only with --rehearse.'),
    'Max event override must be limited to full rehearsal mode'
);
$assertTrue(
    str_contains($source, "if (!in_array(\$environment, ['local', 'staging'], true))")
        && str_contains($source, 'DB-primary rehearsal is forbidden outside local/staging.'),
    'CLI must reject production before opening the database or lock'
);
$environmentGuard = strpos($source, "if (!in_array(\$environment, ['local', 'staging'], true))");
$databaseOpen = strpos($source, '$database = PdoConnectionFactory::create($databaseConfig);');
$lockOpen = strpos($source, '$lockHandle = fopen');
$assertTrue(
    $environmentGuard !== false
        && $databaseOpen !== false
        && $lockOpen !== false
        && $environmentGuard < $databaseOpen
        && $environmentGuard < $lockOpen,
    'Environment guard must precede database and exclusive lock acquisition'
);
$assertTrue(
    str_contains($source, "if (\$mode !== 'status')")
        && str_contains($source, 'runtime-primary-rehearsal.lock')
        && str_contains($source, 'LOCK_EX | LOCK_NB'),
    'Mutating modes must use a non-blocking exclusive rehearsal lock while status stays lock-free'
);
$projectionBootstrap = strpos($source, "RuntimePrimaryProjectionBootstrap.php");
$workerRequire = strpos($source, "RuntimePrimaryProjectionWorker.php");
$assertTrue(
    $projectionBootstrap !== false
        && $workerRequire !== false
        && $projectionBootstrap < $workerRequire,
    'Projection interface/bootstrap must load before the worker implementation'
);
$assertTrue(
    str_contains($source, "'status' => \$operation->status()")
        && str_contains($source, "'install' => \$operation->install()")
        && str_contains($source, "'seed' => \$operation->seed()")
        && str_contains($source, "'run-once' => \$operation->runOnce()")
        && str_contains($source, "'rehearse' => \$operation->rehearse()"),
    'Every CLI mode must map to exactly one explicit operation method'
);
$assertTrue(
    str_contains($source, "'application_entrypoints_changed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false")
        && str_contains($source, "'sensitive_identifiers_exposed' => false"),
    'Failure reports must preserve the non-production and non-sensitive contract'
);
$assertTrue(
    !str_contains($source, 'crontab')
        && !str_contains($source, 'bot/api.php')
        && !str_contains($source, 'WebhookHandler.php')
        && !str_contains($source, 'production-cutover.php'),
    'Rehearsal CLI must not change Cron, application entrypoints or production cutover state'
);

fwrite(STDOUT, "StagingPrimaryRehearsalCliContractTest passed: {$assertions} assertions.\n");
