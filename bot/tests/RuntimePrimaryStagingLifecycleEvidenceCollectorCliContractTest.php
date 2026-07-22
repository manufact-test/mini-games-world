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
$errorHandler = strpos($source, 'set_error_handler(static function');
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
        && $errorHandler !== false
        && $writerValidate !== false
        && $bootstrap !== false
        && $environmentGuard !== false
        && $privateGuard !== false
        && $approval !== false
        && $lockOpen !== false
        && $databaseOpen !== false
        && $cliGuard < $outputParse
        && $outputParse < $errorHandler
        && $errorHandler < $writerValidate
        && $writerValidate < $bootstrap
        && $bootstrap < $environmentGuard
        && $environmentGuard < $privateGuard
        && $privateGuard < $approval
        && $approval < $lockOpen
        && $lockOpen < $databaseOpen,
    'Lifecycle CLI must validate exact inputs and install path-safe failures before bootstrap and DB access'
);
$assertTrue(
    str_contains($source, '$seenOutput = false;')
        && str_contains($source, '$seenMaxEvents = false;')
        && str_contains($source, 'if ($seenOutput)')
        && str_contains($source, 'if ($seenMaxEvents)')
        && str_contains($source, '$outputPath = substr($argument, strlen(\'--output=\'));')
        && str_contains($source, '$raw = substr($argument, strlen(\'--max-events=\'));')
        && !str_contains($source, 'trim(substr($argument')
        && str_contains($source, '--output must not contain backslashes.'),
    'Lifecycle CLI must reject duplicate and normalized option values byte-exactly'
);
$assertTrue(
    str_contains($source, '$failureStage = \'initialization\';')
        && str_contains($source, 'use (&$failureStage)')
        && str_contains($source, 'Lifecycle evidence filesystem operation failed at stage ')
        && str_contains($source, 'Suppressed lifecycle evidence collector warning at stage ')
        && str_contains($source, "'failure_stage' => \$failureStage")
        && str_contains($source, 'restore_error_handler();')
        && strpos($source, 'restore_error_handler();') > $databaseOpen,
    'Lifecycle CLI must shield filesystem warnings, expose only a safe stage enum and restore the handler'
);
foreach ([
    'output_path_validation',
    'application_bootstrap',
    'runtime_contract_loading',
    'staging_configuration_validation',
    'approval_validation',
    'lock_prepare',
    'database_connect',
    'rollback_storage_create',
    'evidence_source_setup',
    'evidence_collection',
    'evidence_write',
    'evidence_readback',
    'evidence_verification',
    'completed',
] as $stage) {
    $assertTrue(
        str_contains($source, "\$failureStage = '{$stage}';"),
        'Lifecycle CLI must set the bounded safe stage: ' . $stage
    );
}
$assertTrue(
    substr_count($source, 'PdoConnectionFactory::create($databaseConfig)') === 2
        && str_contains($source, 'RuntimePrimaryStagingConcurrencyProbe('),
    'Lifecycle CLI must use two independent DB connections for lease evidence'
);
$assertTrue(
    str_contains($source, 'if (!chmod($lockPath, 0600))')
        && str_contains($source, '($lockMode & 0777) !== 0600')
        && str_contains($source, 'lock must have exact mode 0600')
        && !str_contains($source, '@chmod($lockPath'),
    'Lifecycle rehearsal lock must require and verify exact private permissions'
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
    str_contains($source, 'if ($outputWritten && is_file($outputPath) && !is_link($outputPath))')
        && str_contains($source, 'if (!unlink($outputPath))')
        && !str_contains($source, '@unlink($outputPath)')
        && str_contains($source, 'unverified output cleanup also failed'),
    'Lifecycle CLI must explicitly fail closed when unverified output cleanup fails'
);
$assertTrue(
    str_contains($source, "~/(?:home|var|tmp|srv|opt)/")
        && str_contains($source, "'[private-path]'")
        && str_contains($source, "'path_exposed' => false"),
    'Lifecycle CLI must redact common private absolute path roots including opt'
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
