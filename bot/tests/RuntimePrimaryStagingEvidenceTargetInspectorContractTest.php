<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/inspect-staging-db-primary-evidence-target.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging evidence target inspector source is unavailable.');
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
    'Target inspector must remain CLI-only before bootstrap'
);
$assertTrue(
    str_contains($source, 'count($argv ?? []) !== 1')
        && str_contains($source, 'This read-only inspector accepts no arguments.'),
    'Target inspector must reject every argument through an unambiguous count check'
);
$environmentGuard = strpos($source, "if (\$environment !== 'staging')");
$privateGuard = strpos($source, 'RuntimePrimaryPrivateConfigGuard::assertExternal(');
$databaseConfig = strpos($source, 'DatabaseConfig::fromApplicationConfig($config)');
$assertTrue(
    $environmentGuard !== false
        && $privateGuard !== false
        && $databaseConfig !== false
        && $environmentGuard < $privateGuard
        && $privateGuard < $databaseConfig,
    'Target inspector must verify staging and external private config before DB target inspection'
);
$assertTrue(
    str_contains($source, '$databaseConfig->identityFingerprint()')
        && str_contains($source, 'RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot)')
        && str_contains($source, 'RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->safeSummary()')
        && str_contains($source, "'private_config_external'")
        && str_contains($source, "'private_config_fingerprint'"),
    'Target inspector must expose only non-sensitive commit, DB identity, config fingerprint and safe approval evidence'
);
$assertTrue(
    !str_contains($source, 'PdoConnectionFactory')
        && !str_contains($source, 'StorageFactory')
        && !str_contains($source, 'RuntimePrimaryProjectionWorker')
        && !str_contains($source, 'RuntimePrimaryStagingRehearsalBackend'),
    'Target inspector must not open DB/storage or run rehearsal work'
);
$assertTrue(
    !str_contains($source, "'host' =>")
        && !str_contains($source, "'database' =>")
        && !str_contains($source, "'username' =>")
        && !str_contains($source, "'password' =>")
        && !str_contains($source, "'private_dir' =>")
        && !str_contains($source, "'config_file' =>"),
    'Target inspector must not print DB connection identifiers, secrets or private paths'
);
$assertTrue(
    str_contains($source, "'database_connection_opened' => false")
        && str_contains($source, "'application_entrypoints_changed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false")
        && str_contains($source, "'sensitive_identifiers_exposed' => false"),
    'Target inspector must preserve the read-only safety contract'
);

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceTargetInspectorContractTest passed: {$assertions} assertions.\n");
