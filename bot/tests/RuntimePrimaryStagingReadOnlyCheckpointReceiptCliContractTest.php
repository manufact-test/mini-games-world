<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/write-staging-read-only-checkpoint-receipt.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Read-only checkpoint receipt CLI source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cliGuard = strpos($source, "if (PHP_SAPI !== 'cli')");
$argumentLoop = strpos($source, 'foreach (array_slice($argv ?? [], 1) as $argument)');
$assertTrue($cliGuard !== false && $argumentLoop !== false && $cliGuard < $argumentLoop, 'CLI guard must precede argument parsing');
$assertTrue(
    str_contains($source, '$seen = [];')
        && str_contains($source, 'option may be specified only once')
        && str_contains($source, 'Unknown read-only checkpoint receipt argument.'),
    'Receipt CLI must reject duplicate and unknown options'
);
foreach ([
    '--report=', '--output=', '--expected-commit=', '--expected-database-identity=',
    '--expected-evidence-fingerprint=', '--max-age-seconds=',
] as $option) {
    $assertTrue(str_contains($source, "'{$option}'"), 'Receipt CLI must expose exact option: ' . $option);
}
$verifierRequire = strpos($source, 'RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier.php');
$receiptRequire = strpos($source, 'RuntimePrimaryStagingReadOnlyCheckpointReceipt.php');
$writerRequire = strpos($source, 'RuntimePrimaryStagingEvidenceWriter.php');
$verifyCall = strpos($source, 'new RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier(');
$buildCall = strpos($source, 'RuntimePrimaryStagingReadOnlyCheckpointReceipt::build(');
$writeCall = strpos($source, 'new RuntimePrimaryStagingEvidenceWriter($projectRoot)');
$assertTrue(
    $verifierRequire !== false && $receiptRequire !== false && $writerRequire !== false
        && $verifyCall !== false && $buildCall !== false && $writeCall !== false
        && $verifierRequire < $verifyCall && $receiptRequire < $buildCall && $writerRequire < $writeCall,
    'Receipt CLI must re-verify, build and atomically publish in order'
);
$assertTrue(
    str_contains($source, "'mutating_smoke_authorized' => false")
        && str_contains($source, "'persistent_config_changed' => false")
        && str_contains($source, "'webhook_allowed' => false")
        && str_contains($source, "'cron_changed' => false")
        && str_contains($source, "'production_changed' => false"),
    'Receipt CLI success output must preserve all safety flags'
);
$assertTrue(
    !str_contains($source, 'PdoConnectionFactory::create(')
        && !str_contains($source, 'bot/core/bootstrap.php')
        && !str_contains($source, 'crontab')
        && !str_contains($source, 'WebhookHandler.php'),
    'Receipt CLI must not bootstrap application, contact DB, Cron or webhook'
);

fwrite(STDOUT, "RuntimePrimaryStagingReadOnlyCheckpointReceiptCliContractTest passed: {$assertions} assertions.\n");
