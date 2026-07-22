<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$path = $projectRoot . '/ops/runtime/run-staging-read-only-checkpoint.sh';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Read-only checkpoint shell source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$receiptPath = strpos($source, 'staging-read-only-checkpoint-receipt-$RUN_ID.json');
$receiptStatusPath = strpos($source, 'staging-read-only-checkpoint-receipt-status-$RUN_ID.json');
$verification = strpos($source, 'verify-staging-db-primary-api-read-only-smoke-evidence.php');
$receiptWriter = strpos($source, 'write-staging-read-only-checkpoint-receipt.php');
$assertTrue(
    $receiptPath !== false && $receiptStatusPath !== false
        && $verification !== false && $receiptWriter !== false
        && $verification < $receiptWriter,
    'Read-only shell must verify report before publishing unique no-clobber receipt'
);
foreach ([
    '--report="$REPORT_FILE"',
    '--output="$RECEIPT_FILE"',
    '--expected-commit="$CURRENT_COMMIT"',
    '--expected-database-identity="$DATABASE_IDENTITY"',
    '--expected-evidence-fingerprint="$EVIDENCE_FINGERPRINT"',
    '--max-age-seconds=900',
] as $argument) {
    $assertTrue(str_contains($source, $argument), 'Receipt writer shell argument must remain exact: ' . $argument);
}
$assertTrue(
    str_contains($source, 'chmod 0600 "$RECEIPT_STATUS_FILE"')
        && str_contains($source, 'strict read-only checkpoint receipt creation failed')
        && str_contains($source, 'read-only checkpoint receipt result is invalid'),
    'Receipt shell must secure and validate the writer status'
);
$assertTrue(
    str_contains($source, '($d["mutating_smoke_authorized"] ?? null) !== false')
        && str_contains($source, '($d["persistent_config_changed"] ?? null) !== false')
        && str_contains($source, '($d["webhook_allowed"] ?? null) !== false')
        && str_contains($source, '($d["cron_changed"] ?? null) !== false')
        && str_contains($source, '($d["production_changed"] ?? null) !== false'),
    'Receipt status validation must fail closed on every safety flag'
);
$assertTrue(
    str_contains($source, "printf 'READ_ONLY_RECEIPT=VERIFIED\\n'")
        && str_contains($source, "printf 'MUTATING_SMOKE_AUTHORIZED=false\\n'"),
    'Successful read-only output must prove receipt while keeping mutation unauthorized'
);
$assertTrue(
    !str_contains($source, 'MUTATING_SMOKE_AUTHORIZED=true')
        && !str_contains($source, 'staging_db_primary_mutating_smoke'),
    'Ordinary read-only command must never authorize mutating smoke'
);

fwrite(STDOUT, "RuntimePrimaryStagingReadOnlyCheckpointReceiptShellContractTest passed: {$assertions} assertions.\n");
