<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/collect-staging-db-primary-evidence.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Staging evidence collector CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$outputParse = strpos($source, '$outputPath =');
$assertTrue(
    $cliGuard !== false && $outputParse !== false && $cliGuard < $outputParse,
    'CLI-only guard must run before collector arguments'
);
$assertTrue(
    str_contains($source, "str_starts_with(\$argument, '--output=')")
        && str_contains($source, "str_starts_with(\$argument, '--max-events=')")
        && str_contains($source, '--max-events must be between 1 and 100.'),
    'Collector CLI must require explicit output and bounded event count'
);
$writerRequire = strpos($source, "RuntimePrimaryStagingEvidenceWriter.php");
$validateOutput = strpos($source, '$writer->validateOutputPath($outputPath)');
$bootstrap = strpos($source, "require \$projectRoot . '/bot/core/bootstrap.php';");
$assertTrue(
    $writerRequire !== false
        && $validateOutput !== false
        && $bootstrap !== false
        && $writerRequire < $validateOutput
        && $validateOutput < $bootstrap,
    'Private output path must be validated before application bootstrap or DB work'
);
$environmentGuard = strpos($source, "if (\$environment !== 'staging')");
$privateConfigGuard = strpos($source, 'RuntimePrimaryPrivateConfigGuard::assertExternal(');
$approval = strpos($source, 'RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->assertApproved(');
$lockOpen = strpos($source, '$lockHandle = fopen($rehearsalLockPath');
$databaseOpen = strpos($source, '$database = PdoConnectionFactory::create($databaseConfig);');
$assertTrue(
    $environmentGuard !== false
        && $privateConfigGuard !== false
        && $approval !== false
        && $lockOpen !== false
        && $databaseOpen !== false
        && $environmentGuard < $privateConfigGuard
        && $privateConfigGuard < $approval
        && $approval < $lockOpen
        && $approval < $databaseOpen,
    'Staging environment, external private config and exact approval must precede lock and database connections'
);
$assertTrue(
    str_contains($source, "(string)(\$configFile ?? '')")
        && str_contains($source, "\$privateDir = (string)\$privateConfig['private_dir'];")
        && !str_contains($source, "dirname((string)\$configFile)"),
    'Collector must derive lock paths only from the verified external private config directory'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot)')
        && str_contains($source, '$databaseConfig,')
        && str_contains($source, '$currentCommit,')
        && str_contains($source, 'time()'),
    'Collector approval must bind the exact DB identity, checkout commit and short time window'
);
$assertTrue(
    substr_count($source, 'PdoConnectionFactory::create($databaseConfig)') === 2,
    'Collector must open two independent MySQL connections for lease evidence'
);
$assertTrue(
    str_contains($source, 'runtime-primary-rehearsal.lock')
        && str_contains($source, 'LOCK_EX | LOCK_NB')
        && str_contains($source, '@chmod($rehearsalLockPath, 0600)')
        && str_contains($source, 'Another DB-primary rehearsal or evidence collection is already running.'),
    'Collector must serialize through a private 0600 rehearsal lock'
);
$assertTrue(
    str_contains($source, 'new RuntimePrimaryStagingConcurrencyProbe(')
        && str_contains($source, 'runtime-primary-cli-lock-probe.lock')
        && str_contains($source, 'new RuntimePrimaryStagingEvidenceCollector('),
    'Collector must run isolated lock/lease probes and strict manifest collection'
);
$assertTrue(
    str_contains($source, '$writer->write($outputPath, $manifest)')
        && str_contains($source, 'new RuntimePrimaryStagingEvidenceGate($projectRoot)')
        && str_contains($source, 'Written staging evidence failed verification')
        && str_contains($source, 'if ($outputWritten && is_file($outputPath)) @unlink($outputPath);'),
    'Collector must atomically write, re-verify and remove unverified output'
);
$assertTrue(
    str_contains($source, "'publish_mode' => (string)(\$written['publish_mode'] ?? '')")
        && str_contains($source, "'private_config_external' => true"),
    'Collector success report must expose no-clobber publication and external private config evidence'
);
$assertTrue(
    !str_contains($source, 'crontab')
        && !str_contains($source, 'bot/api.php')
        && !str_contains($source, 'WebhookHandler.php')
        && !str_contains($source, 'production-cutover.php'),
    'Collector CLI must not change Cron, application entrypoints or production cutover state'
);
$assertTrue(
    str_contains($source, "'path_exposed' => false")
        && str_contains($source, "'application_entrypoints_changed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false")
        && str_contains($source, "'sensitive_identifiers_exposed' => false"),
    'Collector reports must not expose private paths or claim production mutations'
);
$assertTrue(
    !str_contains($source, "'manifest' =>")
        && !str_contains($source, "'state_json' =>")
        && !str_contains($source, "'snapshot' => \$"),
    'Collector stdout must not include manifest or state payloads'
);

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceCollectorCliContractTest passed: {$assertions} assertions.\n");
