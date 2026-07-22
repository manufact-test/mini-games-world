<?php
declare(strict_types=1);

final class RuntimePrimaryStateSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_state';
}
final class RuntimePrimaryProjectionOutboxSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_projection_outbox';
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$containsBlocker = static function (array $report, string $part): bool {
    foreach ((array)($report['blockers'] ?? []) as $blocker) {
        if (str_contains(strtolower((string)$blocker), strtolower($part))) return true;
    }
    return false;
};

$modules = [
    'accounts', 'realtime', 'economy', 'notifications', 'invites',
    'history', 'shop', 'payments', 'weekly_bonus',
];
$snapshotSha = str_repeat('1', 64);
$projectionProof = [
    'projection_contract_version' => 'v1-normalized-all-modules',
    'target_event_attempted' => true,
    'target_event_lease_free' => true,
    'target_event_error_free' => true,
];
$manifest = [
    'manifest_version' => RuntimePrimaryStagingEvidenceVerifier::MANIFEST_VERSION,
    'environment' => 'staging',
    'repository_commit' => str_repeat('a', 40),
    'generated_at_utc' => '2026-07-20T15:30:00+00:00',
    'php' => [
        'version' => '8.3.25',
        'version_id' => 80325,
        'sapi' => 'cli',
    ],
    'database' => [
        'driver' => 'mysql',
        'server_version' => '10.11.13-MariaDB',
        'state_engine' => 'innodb',
        'outbox_engine' => 'innodb',
    ],
    'schemas' => [
        'state' => [
            'table' => RuntimePrimaryStateSchemaInstaller::TABLE,
            'schema_fingerprint' => str_repeat('2', 64),
        ],
        'outbox' => [
            'table' => RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE,
            'schema_fingerprint' => str_repeat('3', 64),
        ],
    ],
    'source_snapshot' => [
        'before_sha256' => $snapshotSha,
        'after_first_sha256' => $snapshotSha,
        'after_second_sha256' => $snapshotSha,
        'inventory_fingerprint' => str_repeat('4', 64),
    ],
    'first_rehearsal' => $projectionProof + [
        'ok' => true,
        'action' => 'rehearsal_completed',
        'snapshot_action' => 'snapshot_initialized',
        'target_revision' => 1,
        'target_sha256' => $snapshotSha,
        'target_event_status' => 'completed',
        'target_event_completed' => true,
        'status_healthy' => true,
        'parity_completed' => true,
        'worker_tick_count' => 1,
        'projected_modules' => $modules,
        'projection_proof' => 'worker_completed_current_run',
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
    ],
    'repeated_rehearsal' => $projectionProof + [
        'ok' => true,
        'action' => 'rehearsal_completed',
        'snapshot_action' => 'snapshot_unchanged',
        'target_revision' => 1,
        'target_sha256' => $snapshotSha,
        'target_event_status' => 'completed',
        'target_event_completed' => true,
        'status_healthy' => true,
        'parity_completed' => true,
        'worker_tick_count' => 0,
        'projected_modules' => $modules,
        'projection_proof' => 'completed_current_contract_reused',
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
    ],
    'concurrency' => [
        'cli_lock' => [
            'ok' => true,
            'second_exit_code' => 2,
            'second_result' => 'rehearsal_lock_blocked',
        ],
        'worker_lease' => [
            'ok' => true,
            'state_revision' => 1,
            'first_claimed' => true,
            'second_action' => 'projection_busy',
            'second_state_revision' => 1,
            'lease_seconds' => 120,
        ],
    ],
    'entrypoint_evidence' => RuntimePrimaryEntrypointEvidence::inspect($projectRoot),
];

$verifier = new RuntimePrimaryStagingEvidenceVerifier($projectRoot);
$valid = $verifier->verify($manifest);
$assertTrue(($valid['ok'] ?? false) === true, 'Complete staging evidence must pass');
$assertTrue(($valid['blocker_count'] ?? -1) === 0, 'Complete staging evidence must have no blockers');
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', (string)($valid['evidence_fingerprint'] ?? '')) === 1,
    'Complete staging evidence must have a deterministic fingerprint'
);

$reusedFirst = $manifest;
$reusedFirst['first_rehearsal']['snapshot_action'] = 'snapshot_unchanged';
$reusedFirst['first_rehearsal']['worker_tick_count'] = 0;
$reusedFirst['first_rehearsal']['projection_proof'] = 'completed_current_contract_reused';
$report = $verifier->verify($reusedFirst);
$assertTrue(
    ($report['ok'] ?? false) === true,
    'A retry must accept an already completed current all-module projection without another worker write'
);

$production = $manifest;
$production['environment'] = 'production';
$report = $verifier->verify($production);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'must be staging'), 'Production manifest must fail');

$php84 = $manifest;
$php84['php']['version'] = '8.4.1';
$php84['php']['version_id'] = 80401;
$report = $verifier->verify($php84);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'php 8.3'), 'PHP 8.4 evidence must fail');

$changedJson = $manifest;
$changedJson['source_snapshot']['after_second_sha256'] = str_repeat('5', 64);
$report = $verifier->verify($changedJson);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'json rollback source changed'), 'Changed JSON source must fail');

$missingModule = $manifest;
array_pop($missingModule['first_rehearsal']['projected_modules']);
$report = $verifier->verify($missingModule);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'missing required projected modules'), 'Missing module must fail');

$wrongContract = $reusedFirst;
$wrongContract['first_rehearsal']['projection_contract_version'] = 'v0-stale';
$report = $verifier->verify($wrongContract);
$assertTrue(
    ($report['ok'] ?? true) === false && $containsBlocker($report, 'current all-module contract'),
    'A reused projection from another contract version must fail'
);

$activeLease = $reusedFirst;
$activeLease['first_rehearsal']['target_event_lease_free'] = false;
$report = $verifier->verify($activeLease);
$assertTrue(
    ($report['ok'] ?? true) === false && $containsBlocker($report, 'target_event_lease_free'),
    'A reused projection with an active lease must fail'
);

$repeatWrite = $manifest;
$repeatWrite['repeated_rehearsal']['worker_tick_count'] = 1;
$repeatWrite['repeated_rehearsal']['projection_proof'] = 'worker_completed_current_run';
$report = $verifier->verify($repeatWrite);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'zero worker ticks'), 'Repeated worker write must fail');

$weakLock = $manifest;
$weakLock['concurrency']['cli_lock']['second_exit_code'] = 0;
$report = $verifier->verify($weakLock);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'lock concurrency evidence'), 'Weak CLI lock evidence must fail');

$weakLease = $manifest;
$weakLease['concurrency']['worker_lease']['second_action'] = 'projection_completed';
$report = $verifier->verify($weakLease);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'lease concurrency evidence'), 'Weak worker lease evidence must fail');

$tamperedEntrypoint = $manifest;
$tamperedEntrypoint['entrypoint_evidence']['entrypoints']['api']['source_sha256'] = str_repeat('9', 64);
$report = $verifier->verify($tamperedEntrypoint);
$assertTrue(($report['ok'] ?? true) === false, 'Tampered entrypoint evidence must be rejected');
$assertTrue(
    $containsBlocker($report, 'does not match the current repository sources'),
    'Tampered entrypoint evidence must expose the repository-source mismatch blocker'
);

$sensitive = $manifest;
$sensitive['source_snapshot']['state_json'] = '{"users":[]}';
$report = $verifier->verify($sensitive);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'forbidden sensitive field'), 'Sensitive payload must fail');

$unexpected = $manifest;
$unexpected['operator_guess'] = true;
$report = $verifier->verify($unexpected);
$assertTrue(($report['ok'] ?? true) === false && $containsBlocker($report, 'unexpected fields'), 'Unexpected manifest fields must fail');

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceVerifierTest passed: {$assertions} assertions.\n");
