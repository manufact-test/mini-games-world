<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/collect-staging-db-primary-lifecycle-evidence.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Lifecycle evidence collector CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$outputParse = strpos($source, "str_starts_with(\$argument, '--output=')");
$writerValidate = strpos($source, '$writer->validateOutputPath($outputPath)');
$bootstrap = strpos($source, "require \$projectRoot . '/bot/core/bootstrap.php';");
$environmentGuard = strpos($source, "if (\$environment !== 'staging')");
$privateGuard = strpos($source, 'RuntimePrimaryPrivateConfigGuard::assertExternal(');
$approval = strpos($source, 'RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->assertApproved(');
$lockOpen = strpos($source, '$lockHandle = fopen($lockPath');
$databaseOpen = strpos($source, 'PdoConnectionFactory::create($databaseConfig)');
$assertTrue(
    $cliGuard !== false
        && $outputParse !== false
        && $writerValidate !== false
        && $bootstrap !== false
        && $environmentGuard !== false
        && $privateGuard !== false
        && $approval !== false
        && $lockOpen !== false
        && $databaseOpen !== false
        && $cliGuard < $outputParse
        && $outputParse < $writerValidate
        && $writerValidate < $bootstrap
        && $bootstrap < $environmentGuard
        && $environmentGuard < $privateGuard
        && $privateGuard < $approval
        && $approval < $lockOpen
        && $lockOpen < $databaseOpen,
    'Lifecycle CLI must validate output, staging, private approval and lock before DB access'
);
$assertTrue(
    substr_count($source, 'PdoConnectionFactory::create($databaseConfig)') === 2
        && str_contains($source, 'RuntimePrimaryStagingConcurrencyProbe('),
    'Lifecycle CLI must use two independent DB connections for lease evidence'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryStagingLifecycleEvidenceCollector(')
        && str_contains($source, 'RuntimePrimaryStagingEvidenceV4Gate(')
        && str_contains($source, "'manifest_version' => RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION")
        && str_contains($source, "'request_session_evidence_fingerprint'"),
    'Lifecycle CLI must collect and reverify exact evidence v4'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryStagingEvidenceWriter(')
        && str_contains($source, '$writer->write($outputPath, $manifest)')
        && str_contains($source, "'publish_mode'"),
    'Lifecycle CLI must use atomic private no-clobber writer'
);
$assertTrue(
    str_contains($source, "if (\$outputWritten && is_file(\$outputPath) && !is_link(\$outputPath))")
        && str_contains($source, '@unlink($outputPath)'),
    'Lifecycle CLI must remove output that fails post-write verification'
);
$assertTrue(
    str_contains($source, "'session_enabled_by_evidence' => false")
        && str_contains($source, "'finalizer_registered_by_evidence' => false"),
    'Lifecycle evidence collection must not enable session or register finalizer'
);
$assertTrue(
    !str_contains($source, "'host' =>")
        && !str_contains($source, "'database' =>")
        && !str_contains($source, "'username' =>")
        && !str_contains($source, "'password' =>")
        && !str_contains($source, "'output_path' =>")
        && !str_contains($source, "'private_dir' =>"),
    'Lifecycle CLI must not print DB identifiers, secrets or private paths'
);
$assertTrue(
    str_contains($source, "'application_entrypoints_changed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false")
        && str_contains($source, "'sensitive_identifiers_exposed' => false"),
    'Lifecycle CLI must preserve explicit safety flags'
);
$assertTrue(
    !str_contains($source, 'crontab')
        && !str_contains($source, 'production-cutover.php')
        && !str_contains($source, 'WebhookHandler'),
    'Lifecycle evidence collection must not touch Cron, webhook routing or production cutover'
);

fwrite(STDOUT, "RuntimePrimaryStagingLifecycleEvidenceCollectorCliContractTest passed: {$assertions} assertions.\n");
