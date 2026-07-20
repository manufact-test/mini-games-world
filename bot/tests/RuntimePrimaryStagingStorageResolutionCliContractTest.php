<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/inspect-staging-db-primary-storage-resolution.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging storage resolution inspector source is unavailable.');
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
$databaseOpen = strpos($source, 'PdoConnectionFactory::create($databaseConfig)');
$assertTrue(
    $cliGuard !== false
        && $argumentGuard !== false
        && $bootstrap !== false
        && $environmentGuard !== false
        && $privateGuard !== false
        && $databaseOpen !== false
        && $cliGuard < $argumentGuard
        && $argumentGuard < $bootstrap
        && $bootstrap < $environmentGuard
        && $environmentGuard < $privateGuard
        && $privateGuard < $databaseOpen,
    'Resolution inspector must enforce CLI, no arguments, staging and private config before DB access'
);
$assertTrue(
    str_contains($source, 'StorageFactory::createJson(')
        && str_contains($source, 'RuntimePrimaryRepositoryProjectorFactory(')
        && str_contains($source, 'RuntimePrimaryStagingStorageResolver(')
        && str_contains($source, '->resolve()')
        && str_contains($source, '->safeReport()'),
    'Resolution inspector must use JSON rollback, real audits and the guarded resolver'
);
$assertTrue(
    !str_contains($source, 'StorageFactory::createDatabasePrimary(')
        && !str_contains($source, '->transaction(')
        && !str_contains($source, '->execute(')
        && !str_contains($source, 'crontab')
        && !str_contains($source, 'bot/api.php')
        && !str_contains($source, 'WebhookHandler.php'),
    'Resolution inspector must not mutate or route application entrypoints'
);
$assertTrue(
    str_contains($source, "'action' => 'staging_db_primary_storage_resolution_blocked'")
        && str_contains($source, "'resolved' => false")
        && str_contains($source, "'application_entrypoint_routed' => false")
        && str_contains($source, "'application_entrypoints_changed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false"),
    'Blocked resolution report must remain explicit and non-mutating'
);
$assertTrue(
    !str_contains($source, "'host' =>")
        && !str_contains($source, "'database' =>")
        && !str_contains($source, "'username' =>")
        && !str_contains($source, "'password' =>")
        && !str_contains($source, "'evidence_file' =>")
        && !str_contains($source, "'private_dir' =>"),
    'Resolution inspector must not print connection identifiers, secrets or private paths'
);

fwrite(STDOUT, "RuntimePrimaryStagingStorageResolutionCliContractTest passed: {$assertions} assertions.\n");
