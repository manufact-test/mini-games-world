<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$recovery = file_get_contents(
    $projectRoot . '/ops/runtime/recover-staging-api-mutating-smoke-economy-incident.php'
);
$shell = file_get_contents(
    $projectRoot . '/ops/runtime/run-staging-api-incident-recovery.sh'
);
$launcher = file_get_contents($projectRoot . '/c');
$cleanup = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup.php'
);
foreach (compact('recovery', 'shell', 'launcher', 'cleanup') as $label => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Economy incident recovery source is unavailable: ' . $label . '.');
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
    str_contains($recovery, "const MGW_API_INCIDENT_COMMIT = '{$incidentCommit}';")
        && str_contains($recovery, "const MGW_API_INCIDENT_ERROR = '{$incidentError}';"),
    'Recovery must remain bound to the exact live incident.'
);
$assertTrue(
    str_contains($recovery, "if (PHP_SAPI !== 'cli')")
        && str_contains($recovery, 'PHP_VERSION_ID < 80300')
        && str_contains($recovery, "['environment'] ?? null) !== 'staging'"),
    'Recovery must remain CLI-only, PHP 8.3-only and staging-only.'
);
$assertTrue(
    str_contains($recovery, 'RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(')
        && str_contains($recovery, "\$projectRoot,\n        3600")
        && str_contains($recovery, 'MGW_API_INCIDENT_COMMIT, (string)$receipt[\'repository_commit\']')
        && str_contains($recovery, '(string)$receipt[\'database_identity_fingerprint\']'),
    'Recovery must require the exact fresh incident receipt and database identity.'
);
$assertTrue(
    str_contains($recovery, '$cleanupRevision = $baselineRevision + 2;')
        && str_contains($recovery, '(string)($apiEvent[\'status\'] ?? \'\') !== \'completed\'')
        && str_contains($recovery, '(int)($apiEvent[\'attempt_count\'] ?? 0) !== 1')
        && str_contains($recovery, '(string)($cleanupEvent[\'status\'] ?? \'\') !== \'failed\'')
        && str_contains($recovery, '(int)($cleanupEvent[\'attempt_count\'] ?? 0) !== 1')
        && str_contains($recovery, 'MGW_API_INCIDENT_ERROR'),
    'Recovery must require the exact baseline-plus-two outbox suffix.'
);
$assertTrue(
    str_contains($recovery, "b.legacy_user_id REGEXP '^9[0-9]{17}$'")
        && str_contains($recovery, "NOT EXISTS (\n               SELECT 1 FROM mgw_account_ownership")
        && str_contains($recovery, "s.entity_type = 'economy_user_balance'")
        && str_contains($recovery, 'count($balances) !== 2')
        && str_contains($recovery, "['gold_coin', 'match_coin']"),
    'Recovery must identify only the exact orphan synthetic economy pair.'
);
$economyCleanup = strpos($recovery, '$cleanup->recoverOrphanEconomyArtifacts(');
$eventReset = strpos($recovery, '$reset = $database->execute(');
$workerTick = strpos($recovery, '$tick = $worker->runOnce();');
$userDelete = strpos($recovery, '$cleanup->removeOrphanUserAfterCleanupProjection($mgwId);');
$independentAudit = strpos($recovery, '$verificationDatabase = PdoConnectionFactory::create($databaseConfig);');
$assertTrue(
    $economyCleanup !== false && $eventReset !== false && $workerTick !== false
        && $userDelete !== false && $independentAudit !== false
        && $economyCleanup < $eventReset
        && $eventReset < $workerTick
        && $workerTick < $userDelete
        && $userDelete < $independentAudit,
    'Recovery ordering must clean economy, retry revision 3, delete orphan user, then independently audit.'
);
$assertTrue(
    !str_contains($recovery, '$storage->transaction(')
        && !str_contains($recovery, 'curl_')
        && !str_contains($recovery, 'crontab')
        && !str_contains($recovery, 'webhook.php')
        && str_contains($recovery, "'production_changed' => false"),
    'Recovery must not create a new state revision, call external HTTP, touch Cron, webhook or production.'
);
$assertTrue(
    str_contains($cleanup, 'recoverOrphanEconomyArtifacts')
        && str_contains($cleanup, "'DELETE FROM mgw_ledger_entries")
        && str_contains($cleanup, "'DELETE FROM mgw_idempotency_keys")
        && str_contains($cleanup, "'DELETE FROM mgw_balances")
        && str_contains($cleanup, 'runtime_opening:v1:')
        && str_contains($cleanup, "'legacy_runtime_opening'")
        && str_contains($cleanup, "'legacy_json_runtime'"),
    'Cleanup must remove only bounded bootstrap economy artifacts.'
);
$assertTrue(
    str_contains($shell, 'INCIDENT_COMMIT="' . $incidentCommit . '"')
        && str_contains($shell, '($d["state_revision"] ?? null) === 1')
        && str_contains($shell, 'recover-staging-api-mutating-smoke-economy-incident.php')
        && str_contains($shell, 'MGW_STAGING_API_INCIDENT_RECOVERY=PASSED')
        && str_contains($shell, 'ALL_MODULE_PARITY=VERIFIED')
        && str_contains($launcher, 'run-staging-api-incident-recovery.sh'),
    'The one-command recovery launcher must select the exact incident receipt and print bounded proof.'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingApiEconomyIncidentRecoveryContractTest passed: {$assertions} assertions.\n"
);
