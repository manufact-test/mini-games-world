<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$runbook = file_get_contents(
    $projectRoot . '/ops/runtime/DB_PRIMARY_STAGING_READ_ONLY_EXECUTION_CHECKPOINT.md'
);
if (!is_string($runbook)) {
    throw new RuntimeException('Staging read-only execution checkpoint is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$gate0 = strpos($runbook, '## Gate 0 — hosted PHP 8.3 evidence');
$gate1 = strpos($runbook, '## Gate 1 — exact staging deployment');
$gate2 = strpos($runbook, '## Gate 2 — private staging prerequisites');
$gate3 = strpos($runbook, '## Gate 3 — short-lived evidence approval');
$gate4 = strpos($runbook, '## Gate 4 — collect fresh lifecycle evidence v4');
$gate5 = strpos($runbook, '## Gate 5 — CLI-only read-only API smoke');
$gate6 = strpos($runbook, '## Gate 6 — verify the 43-field report');
$assertTrue(
    $gate0 !== false && $gate1 !== false && $gate2 !== false
        && $gate3 !== false && $gate4 !== false && $gate5 !== false && $gate6 !== false
        && $gate0 < $gate1 && $gate1 < $gate2 && $gate2 < $gate3
        && $gate3 < $gate4 && $gate4 < $gate5 && $gate5 < $gate6,
    'Staging checkpoint gates must remain complete and in the approved order'
);

$assertTrue(
    str_contains($runbook, 'git rev-parse --verify HEAD')
        && str_contains($runbook, 'PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400')
        && str_contains($runbook, 'exact deployable branch head')
        && str_contains($runbook, 'same commit accepted by Gate 0'),
    'Checkpoint must bind exact PHP 8.3 evidence to the deployed commit'
);

$assertTrue(
    str_contains($runbook, "'enabled' => true")
        && str_contains($runbook, 'expected_database_identity_fingerprint')
        && str_contains($runbook, 'expected_repository_commit')
        && str_contains($runbook, 'approval_expires_at_utc')
        && str_contains($runbook, 'must not exceed two hours')
        && str_contains($runbook, 'set the persistent `staging_db_primary_evidence.enabled` value back to exact boolean `false`'),
    'Checkpoint must require a short-lived exact evidence approval and explicit disablement'
);

$collector = strpos($runbook, 'collect-staging-db-primary-lifecycle-evidence.php');
$smoke = strpos($runbook, 'run-staging-db-primary-api-read-only-smoke.php');
$verifier = strpos($runbook, 'verify-staging-db-primary-api-read-only-smoke-evidence.php');
$assertTrue(
    $collector !== false && $smoke !== false && $verifier !== false
        && $collector < $smoke && $smoke < $verifier,
    'Checkpoint must collect fresh v4 evidence before smoke and verify the report afterward'
);

$assertTrue(
    str_contains($runbook, '--ttl-seconds=300')
        && str_contains($runbook, 'worker_tick_count = 0')
        && str_contains($runbook, 'state, snapshot, outbox and filters remained unchanged')
        && str_contains($runbook, 'Only `staging_api_read_only_smoke_evidence_verified` is acceptance.'),
    'Checkpoint must require the zero-worker immutable read-only proof and exact acceptance action'
);

foreach ([
    'does **not** authorize production cutover',
    'does not authorize running it automatically',
    'do not run mutating smoke',
    'do not merge or cut over production',
    'webhook execution',
    'Cron changes',
] as $safetyText) {
    $assertTrue(
        str_contains($runbook, $safetyText),
        'Checkpoint safety boundary is missing: ' . $safetyText
    );
}

$assertTrue(
    !preg_match('/(?:password|token|secret)\s*[=:]\s*[A-Za-z0-9_\-]{12,}/i', $runbook)
        && !preg_match('/\b(?:mysql|mariadb):\/\/[^\s]+/i', $runbook)
        && !preg_match('/\/home\/[A-Za-z0-9._-]+\//', $runbook),
    'Checkpoint must not contain credentials, connection URLs or real private home paths'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingReadOnlyExecutionCheckpointContractTest passed: {$assertions} assertions.\n"
);
