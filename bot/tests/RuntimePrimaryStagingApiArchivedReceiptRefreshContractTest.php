<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$shell = file_get_contents(
    $projectRoot . '/ops/runtime/run-staging-api-incident-recovery.sh'
);
$receiptVerifier = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryStagingReadOnlyCheckpointReceipt.php'
);

if (!is_string($shell) || !is_string($receiptVerifier)) {
    throw new RuntimeException('Archived receipt refresh contract sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($shell, "! -name 'staging-read-only-checkpoint-receipt-incident-refresh-*.json'")
        && str_contains($shell, 'ARCHIVED_RECEIPT="$RECEIPT"')
        && str_contains($shell, 'REFRESHED_RECEIPT="$PRIVATE_DIR/staging-read-only-checkpoint-receipt-incident-refresh-$RUN_ID.json"'),
    'Recovery launcher must select the immutable incident receipt and isolate the temporary refreshed copy.'
);
$assertTrue(
    !str_contains($shell, '< <(')
        && !str_contains($shell, '/dev/fd')
        && str_contains($shell, 'CANDIDATE_LIST="$PRIVATE_DIR/staging-api-incident-receipt-candidates-$LIST_ID.txt"')
        && str_contains($shell, 'done < "$CANDIDATE_LIST"')
        && str_contains($shell, 'chmod 0600 "$CANDIDATE_LIST"'),
    'Recovery receipt selection must not depend on process substitution or /dev/fd.'
);
$assertTrue(
    str_contains($shell, 'cleanup_temporary_files()')
        && str_contains($shell, 'trap cleanup_temporary_files EXIT HUP INT TERM')
        && str_contains($shell, 'rm -f -- "$CANDIDATE_LIST"')
        && str_contains($shell, 'rm -f -- "$REFRESHED_RECEIPT"'),
    'All temporary recovery files must be removed on every exit path.'
);
$assertTrue(
    str_contains($shell, 'RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(')
        && str_contains($shell, '$generatedAt->getTimestamp()')
        && str_contains($shell, '$verified["repository_commit"] ?? ""')
        && str_contains($shell, '($verified["state_revision"] ?? null) !== 1'),
    'Archived receipt must be fully verified at its original timestamp and bound to the exact incident.'
);
$assertTrue(
    str_contains($shell, 'unset(')
        && str_contains($shell, '$verified["receipt_file"]')
        && str_contains($shell, '$verified["receipt_sha256"]')
        && str_contains($shell, '$verified["receipt_age_seconds"]')
        && str_contains($shell, '$verified["generated_at_utc"] = gmdate(DATE_ATOM);'),
    'Only derived verifier fields may be removed before refreshing the temporary timestamp.'
);
$assertTrue(
    str_contains($shell, '$handle = fopen($target, "x");')
        && str_contains($shell, 'chmod($target, 0600)')
        && str_contains($shell, 'verifyFile($target, $projectRoot, 300)'),
    'Refreshed receipt must be no-clobber, mode 0600 and freshly reverified.'
);
$assertTrue(
    !str_contains($shell, 'file_put_contents($source')
        && !str_contains($shell, 'rename($target, $source')
        && !str_contains($shell, 'touch($source')
        && str_contains($shell, '--receipt="$REFRESHED_RECEIPT"'),
    'The immutable archived receipt must never be rewritten or passed directly after expiry.'
);
$assertTrue(
    str_contains($receiptVerifier, '$maximumAgeSeconds < 60 || $maximumAgeSeconds > 3600')
        && str_contains($receiptVerifier, '$age > $maximumAgeSeconds'),
    'The shared receipt verifier age bounds must remain globally unchanged.'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingApiArchivedReceiptRefreshContractTest passed: {$assertions} assertions.\n"
);
