<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$recovery = file_get_contents(
    $projectRoot . '/ops/runtime/recover-staging-api-mutating-smoke-detached-economy-incident.php'
);
$shell = file_get_contents(
    $projectRoot . '/ops/runtime/run-staging-api-detached-economy-incident-recovery.sh'
);
$launcher = file_get_contents($projectRoot . '/c');
foreach (compact('recovery', 'shell', 'launcher') as $label => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Detached economy incident recovery source is unavailable: ' . $label . '.');
    }
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$incidentCommit = 'ad2a409d8f979a4b79b568efd6b81c5947659aaa';
$incidentError = 'Legacy economy delta is not ready: Database contains a balance outside the frozen economy source.';
$assertTrue(
    str_contains($recovery, "const MGW_DETACHED_API_INCIDENT_COMMIT = '{$incidentCommit}';")
        && str_contains($recovery, "const MGW_DETACHED_API_INCIDENT_ERROR = '{$incidentError}';")
        && str_contains($recovery, "const MGW_DETACHED_API_PROVIDER = 'telegram';"),
    'Detached recovery must remain bound to the exact live incident.'
);
$assertTrue(
    str_contains($recovery, "if (PHP_SAPI !== 'cli')")
        && str_contains($recovery, 'PHP_VERSION_ID < 80300')
        && str_contains($recovery, "['environment'] ?? null) !== 'staging'"),
    'Detached recovery must remain CLI-only, PHP 8.3-only and staging-only.'
);
$assertTrue(
    str_contains($recovery, 'RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(')
        && str_contains($recovery, "\$projectRoot,\n        3600")
        && str_contains($recovery, 'MGW_DETACHED_API_INCIDENT_COMMIT, (string)$receipt[\'repository_commit\']')
        && str_contains($recovery, '(string)$receipt[\'database_identity_fingerprint\']'),
    'Detached recovery must require the exact receipt and database identity.'
);
$assertTrue(
    str_contains($recovery, '$cleanupRevision = $baselineRevision + 2;')
        && str_contains($recovery, '(string)($apiEvent[\'status\'] ?? \'\') !== \'completed\'')
        && str_contains($recovery, '(int)($apiEvent[\'attempt_count\'] ?? 0) !== 1')
        && str_contains($recovery, '(string)($cleanupEvent[\'status\'] ?? \'\') !== \'failed\'')
        && str_contains($recovery, '(int)($cleanupEvent[\'attempt_count\'] ?? 0) !== 1')
        && str_contains($recovery, 'MGW_DETACHED_API_INCIDENT_ERROR'),
    'Detached recovery must require the exact baseline-plus-two outbox suffix.'
);
$assertTrue(
    str_contains($recovery, "b.legacy_user_id REGEXP '^9[0-9]{17}$'")
        && str_contains($recovery, 'o.mgw_id <=> b.mgw_id')
        && str_contains($recovery, "s.entity_type = 'economy_user_balance'")
        && str_contains($recovery, 'count($candidateRows) !== 2')
        && str_contains($recovery, "['gold_coin', 'match_coin']"),
    'Detached recovery must identify only the exact orphan synthetic economy pair.'
);
$assertTrue(
    str_contains($recovery, 'function isDetachedMgwId(mixed $value): bool')
        && str_contains($recovery, 'return !MgwIdGenerator::isValid($value);')
        && str_contains($recovery, 'function sameDetachedMgwId(mixed $expected, mixed $actual): bool')
        && str_contains($recovery, 'mgw_id <=> :mgw_id'),
    'Detached recovery must treat the historical projection key as untrusted and compare it null-safely.'
);
$assertTrue(
    str_contains($recovery, "'SELECT COUNT(*) FROM mgw_users WHERE mgw_id <=> :mgw_id'")
        && str_contains($recovery, 'provider = :provider AND provider_subject = :subject')
        && str_contains($recovery, 'detached_identity_rows_verified_zero')
        && !str_contains($recovery, 'removeOrphanUserAfterCleanupProjection'),
    'Detached recovery must prove the user and all mappings are already absent without deleting a user.'
);
$assertTrue(
    str_contains($recovery, "'DELETE FROM mgw_ledger_entries\n             WHERE account_ref = :account_ref AND legacy_user_id = :legacy_user_id'")
        && str_contains($recovery, "'DELETE FROM mgw_idempotency_keys WHERE owner_ref = :account_ref'")
        && str_contains($recovery, "'DELETE FROM mgw_balances\n             WHERE account_ref = :account_ref AND legacy_user_id = :legacy_user_id'")
        && str_contains($recovery, 'runtime_opening:v1:')
        && str_contains($recovery, "'legacy_runtime_opening'")
        && str_contains($recovery, "'legacy_json_runtime'"),
    'Detached recovery may delete only the bounded bootstrap economy artifacts by account and legacy identity.'
);
$economyCleanup = strpos($recovery, '$cleanupProof = $database->transaction(');
$eventReset = strpos($recovery, '$reset = $database->execute(');
$workerTick = strpos($recovery, '$tick = $worker->runOnce();');
$independentAudit = strpos($recovery, '$verificationDatabase = PdoConnectionFactory::create($databaseConfig);');
$assertTrue(
    $economyCleanup !== false && $eventReset !== false && $workerTick !== false
        && $independentAudit !== false
        && $economyCleanup < $eventReset
        && $eventReset < $workerTick
        && $workerTick < $independentAudit,
    'Detached recovery ordering must clean economy, retry revision 3, then independently audit.'
);
$assertTrue(
    !str_contains($recovery, '$storage->transaction(')
        && !str_contains($recovery, 'curl_')
        && !str_contains($recovery, 'crontab')
        && !str_contains($recovery, 'webhook.php')
        && str_contains($recovery, "'production_changed' => false"),
    'Detached recovery must not create a new state revision, call HTTP, touch Cron, webhook or production.'
);
$assertTrue(
    str_contains($shell, 'INCIDENT_COMMIT="' . $incidentCommit . '"')
        && !str_contains($shell, '< <(')
        && !str_contains($shell, '/dev/fd')
        && str_contains($shell, 'recover-staging-api-mutating-smoke-detached-economy-incident.php')
        && str_contains($shell, 'MGW_STAGING_API_DETACHED_INCIDENT_RECOVERY=PASSED')
        && str_contains($shell, 'DETACHED_ECONOMY_ORPHAN_ROWS_REMOVED=true')
        && str_contains($shell, 'ALL_MODULE_PARITY=VERIFIED')
        && str_contains($launcher, 'run-staging-api-detached-economy-incident-recovery.sh'),
    'The c launcher must use the Hostinger-compatible exact detached recovery and print bounded proof.'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingApiEconomyIncidentRecoveryContractTest passed: {$assertions} assertions.\n"
);
