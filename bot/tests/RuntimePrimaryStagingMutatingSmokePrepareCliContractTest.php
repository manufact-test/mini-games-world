<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/prepare-staging-bounded-mutating-smoke.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Bounded mutating smoke prepare CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$parseLoop = strpos($source, 'foreach (array_slice($argv ?? [], 1) as $argument)');
$assertTrue($cliGuard !== false && $parseLoop !== false && $cliGuard < $parseLoop, 'Prepare CLI guard must precede parsing');
$assertTrue(
    str_contains($source, '$seen = [];')
        && str_contains($source, 'option may be specified only once')
        && str_contains($source, 'Unknown bounded mutating smoke prepare argument.'),
    'Prepare CLI must reject duplicate and unknown arguments'
);
foreach (['--receipt=', '--output=', '--expected-commit=', '--ttl-seconds='] as $option) {
    $assertTrue(str_contains($source, "'{$option}'"), 'Prepare CLI option must remain exact: ' . $option);
}
$receiptVerify = strpos($source, 'RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(');
$challenge = strpos($source, '$challenge = bin2hex(random_bytes(32));');
$approvalBuild = strpos($source, '$approvalPayload = [');
$approvalValidate = strpos($source, 'RuntimePrimaryStagingMutatingSmokeApproval::fromArray($approvalPayload)');
$approvalWrite = strpos($source, 'new RuntimePrimaryStagingEvidenceWriter($projectRoot)');
$assertTrue(
    $receiptVerify !== false && $challenge !== false && $approvalBuild !== false
        && $approvalValidate !== false && $approvalWrite !== false
        && $receiptVerify < $challenge && $challenge < $approvalBuild
        && $approvalBuild < $approvalValidate && $approvalValidate < $approvalWrite,
    'Prepare CLI must verify receipt before generating and publishing approval'
);
$assertTrue(
    str_contains($source, "'max_state_writes' => 1")
        && str_contains($source, "'rollback_required' => true")
        && str_contains($source, "'webhook_allowed' => false")
        && str_contains($source, "'cron_change_allowed' => false")
        && str_contains($source, "'production_allowed' => false"),
    'Prepare approval must be one-write rollback-only and forbid webhook/Cron/production'
);
$assertTrue(
    str_contains($source, "'mutating_smoke_prepared' => true")
        && str_contains($source, "'mutating_smoke_executed' => false")
        && str_contains($source, "'persistent_config_changed' => false")
        && str_contains($source, "'production_changed' => false"),
    'Prepare output must distinguish preparation from execution and preserve safety'
);
$assertTrue(
    !str_contains($source, 'PdoConnectionFactory::create(')
        && !str_contains($source, 'RuntimePrimaryStagingBoundedMutatingSmoke(')
        && !str_contains($source, '->runOnce()')
        && !str_contains($source, 'crontab'),
    'Prepare CLI must not connect DB, execute smoke, run worker or change Cron'
);

fwrite(STDOUT, "RuntimePrimaryStagingMutatingSmokePrepareCliContractTest passed: {$assertions} assertions.\n");
