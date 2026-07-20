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
    str_contains($source, 'This read-only inspector accepts no arguments.'),
    'Target inspector must reject every argument'
);
$assertTrue(
    str_contains($source, "if (\$environment !== 'staging')")
        && str_contains($source, 'target inspection is staging-only'),
    'Target inspector must reject non-staging environments'
);
$assertTrue(
    str_contains($source, 'DatabaseConfig::fromApplicationConfig($config)')
        && str_contains($source, '$databaseConfig->identityFingerprint()')
        && str_contains($source, 'RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot)')
        && str_contains($source, 'RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->safeSummary()'),
    'Target inspector must expose only commit, DB identity fingerprint and safe approval summary'
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
        && !str_contains($source, "'password' =>"),
    'Target inspector must not print DB connection identifiers or secrets'
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
