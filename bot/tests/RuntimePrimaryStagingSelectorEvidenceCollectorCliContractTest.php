<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/collect-staging-db-primary-selector-evidence.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Selector-aware evidence collector CLI source is unavailable.');
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
    'Selector evidence CLI must validate output, staging, private config, approval and lock before DB access'
);
$assertTrue(
    substr_count($source, 'PdoConnectionFactory::create($databaseConfig)') === 2
        && str_contains($source, 'RuntimePrimaryStagingConcurrencyProbe('),
    'Selector evidence CLI must use two independent staging DB connections for lease evidence'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryStagingSelectorEvidenceCollector(')
        && str_contains($source, 'RuntimePrimaryStagingEvidenceV3Gate(')
        && str_contains($source, "'manifest_version' => RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION")
        && str_contains($source, "'selector_evidence_fingerprint'"),
    'Selector evidence CLI must collect, store and reverify exact evidence v3'
);
$assertTrue(
    str_contains($source, 'RuntimePrimaryStagingEvidenceWriter(')
        && str_contains($source, '$writer->write($outputPath, $manifest)')
        && str_contains($source, "'publish_mode'"),
    'Selector evidence CLI must use the atomic no-clobber private writer'
);
$assertTrue(
    str_contains($source, "if (\$outputWritten && is_file(\$outputPath) && !is_link(\$outputPath))")
        && str_contains($source, '@unlink($outputPath)'),
    'Selector evidence CLI must remove an output that fails post-write verification'
);
$assertTrue(
    !str_contains($source, "'host' =>")
        && !str_contains($source, "'database' =>")
        && !str_contains($source, "'username' =>")
        && !str_contains($source, "'password' =>")
        && !str_contains($source, "'output_path' =>")
        && !str_contains($source, "'private_dir' =>"),
    'Selector evidence CLI must not print DB identifiers, secrets or private paths'
);
$assertTrue(
    str_contains($source, "'application_entrypoints_changed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false")
        && str_contains($source, "'sensitive_identifiers_exposed' => false"),
    'Selector evidence CLI must preserve explicit safety flags'
);
$assertTrue(
    !str_contains($source, 'bot/api.php')
        && !str_contains($source, 'WebhookHandler.php')
        && !str_contains($source, 'crontab')
        && !str_contains($source, 'production-cutover.php'),
    'Selector evidence collection must not route entrypoints or touch production controls'
);

fwrite(STDOUT, "RuntimePrimaryStagingSelectorEvidenceCollectorCliContractTest passed: {$assertions} assertions.\n");
