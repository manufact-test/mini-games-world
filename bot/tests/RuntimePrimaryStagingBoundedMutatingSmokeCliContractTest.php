<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/run-staging-bounded-mutating-smoke.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Bounded mutating smoke execution CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$parseLoop = strpos($source, 'foreach (array_slice($argv ?? [], 1) as $argument)');
$assertTrue(
    $cliGuard !== false && $parseLoop !== false && $cliGuard < $parseLoop,
    'Execution CLI guard must precede argument parsing.'
);
$assertTrue(
    str_contains($source, '$seen = [];')
        && str_contains($source, 'option may be specified only once')
        && str_contains($source, 'Unknown bounded mutating smoke execution argument.'),
    'Execution CLI must reject duplicate and unknown options.'
);
foreach ([
    '--receipt=',
    '--approval=',
    '--consumed-approval=',
    '--challenge=',
    '--output=',
    '--expected-commit=',
] as $option) {
    $assertTrue(
        str_contains($source, "'{$option}'"),
        'Execution CLI option must remain exact: ' . $option
    );
}

$receiptVerify = strpos(
    $source,
    'RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile('
);
$reportVerify = strpos(
    $source,
    'new RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier('
);
$approvalBuild = strpos(
    $source,
    'RuntimePrimaryStagingMutatingSmokeApproval::fromArray($approvalPayload)'
);
$approvalAssert = strpos($source, '$approval->assertApproved(');
$lockAcquire = strpos($source, 'flock($lockHandle, LOCK_EX | LOCK_NB)');
$consumeLink = strpos(
    $source,
    "link(\$approvalPath, \$values['consumed_approval'])"
);
$consumeUnlink = strpos($source, 'unlink($approvalPath)');
$databaseOpen = strpos($source, 'PdoConnectionFactory::create($databaseConfig)');
$assertTrue(
    $receiptVerify !== false
        && $reportVerify !== false
        && $approvalBuild !== false
        && $approvalAssert !== false
        && $lockAcquire !== false
        && $consumeLink !== false
        && $consumeUnlink !== false
        && $databaseOpen !== false
        && $receiptVerify < $reportVerify
        && $reportVerify < $approvalBuild
        && $approvalBuild < $approvalAssert
        && $approvalAssert < $lockAcquire
        && $lockAcquire < $consumeLink
        && $consumeLink < $consumeUnlink
        && $consumeUnlink < $databaseOpen,
    'Execution must verify identities, lock, and consume approval before any DB connection.'
);

$assertTrue(
    substr_count($source, 'PdoConnectionFactory::create($databaseConfig)') === 2
        && str_contains($source, '$mutationDatabase === $verificationDatabase'),
    'Execution must require two independently created staging DB connections.'
);

$baselineCapture = strpos($source, '$baseline = $rollbackGuard->capture();');
$coreRun = strpos($source, 'new RuntimePrimaryStagingBoundedMutatingSmoke(');
$firstRestore = strpos($source, '$rollbackGuard->assertRestored($baseline);', $coreRun);
$secondRestore = $firstRestore === false
    ? false
    : strpos($source, '$rollbackGuard->assertRestored($baseline);', $firstRestore + 1);
$reportWrite = strpos($source, 'new RuntimePrimaryStagingEvidenceWriter($projectRoot)');
$assertTrue(
    $baselineCapture !== false
        && $coreRun !== false
        && $firstRestore !== false
        && $secondRestore !== false
        && $reportWrite !== false
        && $baselineCapture < $coreRun
        && $coreRun < $firstRestore
        && $firstRestore < $secondRestore
        && $secondRestore < $reportWrite,
    'Execution must verify rollback on both failure and success before publishing evidence.'
);

$assertTrue(
    str_contains($source, "'approval_consumed' => true")
        && str_contains($source, "'rollback_guard_verified' => true")
        && str_contains($source, "'committed_state_write_count' => 0")
        && str_contains($source, "'committed_outbox_event_count' => 0"),
    'Success evidence must prove consumed approval, independent rollback guard, and zero committed writes.'
);
$assertTrue(
    str_contains($source, "'persistent_config_changed' => false")
        && str_contains($source, "'webhook_allowed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false"),
    'Execution output must preserve all safety flags.'
);
$assertTrue(
    !str_contains($source, 'MUTATING_SMOKE_AUTHORIZED=true')
        && !str_contains($source, 'crontab')
        && !str_contains($source, 'WebhookHandler.php'),
    'Execution CLI must not modify persistent authorization, Cron, or webhook routing.'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingBoundedMutatingSmokeCliContractTest passed: {$assertions} assertions.\n"
);
