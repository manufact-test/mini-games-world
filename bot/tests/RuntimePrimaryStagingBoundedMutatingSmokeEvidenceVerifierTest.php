<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeApproval.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try { $callback(); } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$now = 1784743200;
$commit = str_repeat('a', 40);
$databaseIdentity = str_repeat('b', 64);
$readOnlyReportSha = str_repeat('c', 64);
$receiptSha = str_repeat('d', 64);
$approvalId = str_repeat('e', 64);
$modules = [
    'accounts', 'realtime', 'economy', 'notifications', 'invites',
    'history', 'shop', 'payments', 'weekly_bonus',
];
$valid = static function () use (
    $now, $commit, $databaseIdentity, $readOnlyReportSha, $receiptSha, $approvalId, $modules
): array {
    return [
        'ok' => true,
        'report_type' => RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier::REPORT_TYPE,
        'action' => RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier::ACTION,
        'approval_id' => $approvalId,
        'baseline_state_revision' => 7,
        'baseline_state_sha256' => str_repeat('1', 64),
        'baseline_outbox_fingerprint' => str_repeat('2', 64),
        'baseline_all_module_fingerprint' => str_repeat('3', 64),
        'mutation_state_revision' => 8,
        'mutation_state_sha256' => str_repeat('4', 64),
        'worker_tick_count' => 1,
        'worker_action' => 'projection_completed',
        'worker_claimed' => true,
        'projected_modules' => $modules,
        'all_module_fingerprint_during_mutation' => str_repeat('5', 64),
        'projection_event_status' => 'completed',
        'projection_attempt_count' => 1,
        'temporary_state_write_count' => 1,
        'temporary_projection_completed' => true,
        'temporary_all_module_audit_passed' => true,
        'rollback_signal_caught' => true,
        'state_revision_restored' => true,
        'state_sha256_restored' => true,
        'snapshot_restored' => true,
        'outbox_restored' => true,
        'all_module_projection_restored' => true,
        'committed_state_write_count' => 0,
        'committed_outbox_event_count' => 0,
        'persistent_config_changed' => false,
        'http_route_added' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM, $now),
        'approval_contract_version' => RuntimePrimaryStagingMutatingSmokeApproval::CONTRACT_VERSION,
        'approval_consumed' => true,
        'repository_commit' => $commit,
        'database_identity_fingerprint' => $databaseIdentity,
        'read_only_receipt_sha256' => $receiptSha,
        'read_only_report_sha256' => $readOnlyReportSha,
        'challenge_sha256' => str_repeat('6', 64),
        'rollback_guard_verified' => true,
    ];
};

$root = sys_get_temp_dir() . '/mgw-mutating-verifier-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700, true)) throw new RuntimeException('Could not create verifier test directory.');
$path = $root . '/report.json';
$write = static function (array $report) use ($path): void {
    file_put_contents($path, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    chmod($path, 0600);
};
$verify = static function () use (
    $path, $commit, $databaseIdentity, $readOnlyReportSha, $receiptSha, $approvalId, $now
): array {
    return (new RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier(
        $path,
        $commit,
        $databaseIdentity,
        $readOnlyReportSha,
        $receiptSha,
        $approvalId,
        600
    ))->verify($now + 30);
};

try {
    $write($valid());
    $result = $verify();
    $assertTrue(($result['ok'] ?? false) === true, 'Valid mutating smoke report must pass');
    $assertTrue(($result['report_field_count'] ?? 0) === 43, 'Mutating smoke report must have exactly 43 fields');
    $assertTrue(($result['rollback_verified'] ?? false) === true, 'Verifier must prove rollback');
    $assertTrue(($result['committed_state_write_count'] ?? -1) === 0, 'Verifier must prove zero committed writes');

    $extra = $valid();
    $extra['extra'] = true;
    $write($extra);
    $assertThrows($verify, 'exactly 43 fields');

    $wrongRevision = $valid();
    $wrongRevision['mutation_state_revision'] = 9;
    $write($wrongRevision);
    $assertThrows($verify, 'exactly one temporary revision');

    $wrongTick = $valid();
    $wrongTick['worker_tick_count'] = 0;
    $write($wrongTick);
    $assertThrows($verify, 'temporary execution proof');

    $missingModule = $valid();
    array_pop($missingModule['projected_modules']);
    $write($missingModule);
    $assertThrows($verify, 'missing projected modules');

    $committed = $valid();
    $committed['committed_state_write_count'] = 1;
    $write($committed);
    $assertThrows($verify, 'committed temporary writes');

    $unsafe = $valid();
    $unsafe['production_changed'] = true;
    $write($unsafe);
    $assertThrows($verify, 'safety flag must be false');

    $notConsumed = $valid();
    $notConsumed['approval_consumed'] = false;
    $write($notConsumed);
    $assertThrows($verify, 'required proof is not true');

    $wrongApproval = $valid();
    $wrongApproval['approval_id'] = str_repeat('f', 64);
    $write($wrongApproval);
    $assertThrows($verify, 'identity mismatch: approval_id');

    $commitWithNewline = $valid();
    $commitWithNewline['repository_commit'] .= "\n";
    $write($commitWithNewline);
    $assertThrows($verify, 'commit does not match');

    $shaWithNewline = $valid();
    $shaWithNewline['challenge_sha256'] .= "\n";
    $write($shaWithNewline);
    $assertThrows($verify, 'sha field is invalid: challenge_sha256');

    $old = $valid();
    $old['generated_at_utc'] = gmdate(DATE_ATOM, $now - 700);
    $write($old);
    $assertThrows($verify, 'outside the accepted age window');

    $write($valid());
    chmod($path, 0644);
    $assertThrows($verify, 'permissions are unsafe');
} finally {
    @unlink($path);
    @rmdir($root);
}

fwrite(STDOUT, "RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifierTest passed: {$assertions} assertions.\n");
