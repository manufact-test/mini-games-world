<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeEvidenceVerifier.php';

$temporary = sys_get_temp_dir() . '/mgw-api-mutating-verifier-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('Unable to create API mutating verifier test directory.');
}
chmod($temporary, 0700);
$reportFile = $temporary . '/report.json';
$now = 1_800_000_000;
$commit = str_repeat('a', 40);
$databaseIdentity = str_repeat('b', 64);
$receiptSha = str_repeat('c', 64);
$rollbackSha = str_repeat('d', 64);
$evidenceFingerprint = str_repeat('e', 64);

$valid = static fn(): array => [
    'ok' => true,
    'report_type' => RuntimePrimaryStagingApiMutatingSmokeEvidenceVerifier::REPORT_TYPE,
    'action' => RuntimePrimaryStagingApiMutatingSmokeEvidenceVerifier::ACTION,
    'repository_commit' => $commit,
    'database_identity_fingerprint' => $databaseIdentity,
    'read_only_receipt_sha256' => $receiptSha,
    'rollback_smoke_report_sha256' => $rollbackSha,
    'lifecycle_evidence_fingerprint' => $evidenceFingerprint,
    'baseline_state_revision' => 10,
    'baseline_state_sha256' => str_repeat('1', 64),
    'baseline_snapshot_sha256' => str_repeat('1', 64),
    'baseline_outbox_event_count' => 10,
    'baseline_outbox_fingerprint' => str_repeat('2', 64),
    'baseline_all_module_fingerprint' => str_repeat('3', 64),
    'api_action' => 'bootstrap',
    'api_child_exit_code' => 0,
    'api_response_ok' => true,
    'api_user_id_sha256' => str_repeat('4', 64),
    'api_state_revision' => 11,
    'api_state_sha256' => str_repeat('5', 64),
    'api_outbox_event_count' => 11,
    'api_projection_completed' => true,
    'api_all_module_audit_passed' => true,
    'cleanup_state_revision' => 12,
    'cleanup_state_sha256' => str_repeat('1', 64),
    'cleanup_worker_tick_count' => 1,
    'cleanup_projection_completed' => true,
    'mapping_session_rows_deleted' => 1,
    'mapping_device_rows_deleted' => 1,
    'mapping_ownership_rows_deleted' => 1,
    'mapping_identity_rows_deleted' => 2,
    'orphan_user_rows_deleted' => 1,
    'final_state_revision' => 12,
    'final_state_sha256' => str_repeat('1', 64),
    'final_snapshot_sha256' => str_repeat('1', 64),
    'final_outbox_event_count' => 12,
    'final_outbox_fingerprint' => str_repeat('6', 64),
    'final_all_module_fingerprint' => str_repeat('3', 64),
    'state_restored_to_baseline' => true,
    'snapshot_restored_to_baseline' => true,
    'all_module_projection_restored' => true,
    'synthetic_state_user_absent' => true,
    'synthetic_identity_cleanup_verified' => true,
    'json_rollback_source_unchanged' => true,
    'committed_api_state_write_count' => 1,
    'committed_cleanup_state_write_count' => 1,
    'committed_outbox_event_count' => 2,
    'input_enabled_in_memory_only' => true,
    'selector_enabled_in_memory_only' => true,
    'request_session_enabled_in_memory_only' => true,
    'activation_enabled_in_memory_only' => true,
    'persistent_config_changed' => false,
    'http_route_added' => false,
    'webhook_allowed' => false,
    'cron_changed' => false,
    'production_changed' => false,
    'sensitive_identifiers_exposed' => false,
    'recovery_attempted' => false,
    'recovery_verified' => false,
    'generated_at_utc' => gmdate(DATE_ATOM, $now - 10),
];

$write = static function (array $report) use ($reportFile): void {
    file_put_contents($reportFile, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n");
    chmod($reportFile, 0600);
};
$verify = static function () use (
    $reportFile,
    $commit,
    $databaseIdentity,
    $receiptSha,
    $rollbackSha,
    $evidenceFingerprint,
    $now
): array {
    return (new RuntimePrimaryStagingApiMutatingSmokeEvidenceVerifier(
        $reportFile,
        $commit,
        $databaseIdentity,
        $receiptSha,
        $rollbackSha,
        $evidenceFingerprint,
        1800
    ))->verify($now);
};
$assertions = 0;
$assertThrows = static function (callable $callback, string $part) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $part)) return;
        throw new RuntimeException('Unexpected API mutating verifier error: ' . $error->getMessage(), 0, $error);
    }
    throw new RuntimeException('Expected API mutating verifier failure was not raised: ' . $part);
};

try {
    $write($valid());
    $verified = $verify();
    $assertions++;
    if (($verified['ok'] ?? false) !== true
        || ($verified['report_field_count'] ?? 0) !== 60
        || ($verified['committed_outbox_event_count'] ?? 0) !== 2) {
        throw new RuntimeException('Valid API mutating smoke report was not verified.');
    }

    $extra = $valid();
    $extra['unexpected'] = true;
    $write($extra);
    $assertThrows($verify, 'exactly 60 fields');

    $badApiRevision = $valid();
    $badApiRevision['api_state_revision'] = 12;
    $write($badApiRevision);
    $assertThrows($verify, 'revision or outbox chain');

    $badFinalSha = $valid();
    $badFinalSha['final_state_sha256'] = str_repeat('9', 64);
    $write($badFinalSha);
    $assertThrows($verify, 'restore baseline state');

    $badCleanup = $valid();
    $badCleanup['mapping_session_rows_deleted'] = 0;
    $write($badCleanup);
    $assertThrows($verify, 'cleanup counts');

    $badSafety = $valid();
    $badSafety['production_changed'] = true;
    $write($badSafety);
    $assertThrows($verify, 'safety flag');

    $commitWithNewline = $valid();
    $commitWithNewline['repository_commit'] .= "\n";
    $write($commitWithNewline);
    $assertThrows($verify, 'commit does not match');

    $shaWithNewline = $valid();
    $shaWithNewline['api_user_id_sha256'] .= "\n";
    $write($shaWithNewline);
    $assertThrows($verify, 'SHA field is invalid');

    $old = $valid();
    $old['generated_at_utc'] = gmdate(DATE_ATOM, $now - 1900);
    $write($old);
    $assertThrows($verify, 'outside the accepted age');

    $write($valid());
    chmod($reportFile, 0644);
    $assertThrows($verify, 'permissions are unsafe');
} finally {
    @unlink($reportFile);
    @rmdir($temporary);
}

fwrite(STDOUT, "RuntimePrimaryStagingApiMutatingSmokeEvidenceVerifierTest passed: {$assertions} assertions.\n");
