<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$preflight = file_get_contents($projectRoot . '/ops/runtime/check-staging-read-only-prerequisites.php');
$runner = file_get_contents($projectRoot . '/ops/runtime/run-staging-read-only-checkpoint.sh');
$launcher = file_get_contents($projectRoot . '/r');
if (!is_string($preflight) || !is_string($runner) || !is_string($launcher)) {
    throw new RuntimeException('One-command staging read-only checkpoint sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($preflight, "if (PHP_SAPI !== 'cli')")
        && str_contains($preflight, 'PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400')
        && str_contains($preflight, "preg_match('/\\A[a-f0-9]{40}\\z/'")
        && str_contains($preflight, 'RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot)')
        && str_contains($preflight, 'hash_equals($expectedCommit, $currentCommit)'),
    'Prerequisite check must be CLI-only, exact PHP 8.3 and exact-commit bound'
);
$assertTrue(
    str_contains($preflight, "($config['environment'] ?? null) !== 'staging'")
        && str_contains($preflight, 'RuntimePrimaryPrivateConfigGuard::assertExternal')
        && str_contains($preflight, 'DatabaseConfig::fromApplicationConfig($config)')
        && str_contains($preflight, "preg_match('/\\A[a-f0-9]{64}\\z/'")
        && str_contains($preflight, 'JSON rollback data directory must remain outside the deployed project.'),
    'Prerequisite check must prove staging, external private config, DB identity and external JSON rollback'
);
foreach ([
    'staging_db_primary_evidence',
    'staging_db_primary_activation',
    'staging_db_primary_entrypoint_selector',
    'staging_db_primary_request_session',
] as $latch) {
    $assertTrue(
        str_contains($preflight, "'{$latch}'")
            && str_contains($preflight, 'must remain persistently disabled before the read-only checkpoint.'),
        'Prerequisite check must fail closed on persistent latch: ' . $latch
    );
}
$assertTrue(
    str_contains($preflight, "'webhook_allowed' => false")
        && str_contains($preflight, "'production_changed' => false")
        && str_contains($preflight, "'sensitive_identifiers_exposed' => false")
        && str_contains($preflight, "[private-path]"),
    'Prerequisite output must remain path-safe and explicitly non-production'
);

$collector = strpos($runner, 'collect-staging-db-primary-lifecycle-evidence.php');
$smoke = strpos($runner, 'run-staging-db-primary-api-read-only-smoke.php');
$verify = strpos($runner, 'verify-staging-db-primary-api-read-only-smoke-evidence.php');
$assertTrue(
    $collector !== false && $smoke !== false && $verify !== false
        && $collector < $smoke && $smoke < $verify,
    'One-command runner must preserve evidence then read-only smoke then verifier order'
);
$assertTrue(
    str_starts_with($runner, "#!/usr/bin/env bash\nset -eEuo pipefail\numask 077\n")
        && str_contains($runner, 'git -C "$PROJECT_ROOT" rev-parse --verify HEAD')
        && str_contains($runner, 'git -C "$PROJECT_ROOT" status --porcelain=v1 --untracked-files=all')
        && str_contains($runner, "PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400")
        && str_contains($runner, 'PHP 8.3 CLI binary was not found'),
    'One-command runner must require a clean exact checkout and discover only PHP 8.3'
);
$assertTrue(
    str_contains($runner, "trap cleanup_overlay EXIT HUP INT TERM")
        && str_contains($runner, "require __DIR__ . '/config.php'")
        && str_contains($runner, "'staging_db_primary_evidence'")
        && str_contains($runner, "'enabled' => true")
        && str_contains($runner, 'rm -f -- "$OVERLAY_FILE"')
        && !str_contains($runner, '> "$BASE_CONFIG"')
        && !str_contains($runner, 'mv "$OVERLAY_FILE" "$BASE_CONFIG"'),
    'Evidence approval must exist only in a private temporary overlay and never replace persistent config'
);
$assertTrue(
    str_contains($runner, '--max-events=20')
        && str_contains($runner, '--ttl-seconds=300')
        && str_contains($runner, '--expected-commit="$CURRENT_COMMIT"')
        && str_contains($runner, '--expected-database-identity="$DATABASE_IDENTITY"')
        && str_contains($runner, '--expected-evidence-fingerprint="$EVIDENCE_FINGERPRINT"'),
    'One-command runner must bind collector, smoke and verifier to bounded exact identities'
);
$assertTrue(
    str_contains($runner, "MGW_STAGING_READ_ONLY_CHECKPOINT=PASSED")
        && str_contains($runner, "PERSISTENT_CONFIG_CHANGED=false")
        && str_contains($runner, "WORKER_TICKS=0")
        && str_contains($runner, "WEBHOOK_ALLOWED=false")
        && str_contains($runner, "CRON_CHANGED=false")
        && str_contains($runner, "PRODUCTION_CHANGED=false")
        && str_contains($runner, "MUTATING_SMOKE_AUTHORIZED=false"),
    'One-command runner must publish the exact safe stop-state after verification'
);
$assertTrue(
    !str_contains($runner, 'mysql ')
        && !str_contains($runner, 'mysqldump')
        && !str_contains($runner, 'ssh ')
        && !str_contains($runner, 'scp ')
        && !str_contains($runner, 'rsync ')
        && !str_contains($runner, 'crontab')
        && !str_contains($runner, 'production-cutover.php')
        && !str_contains($runner, 'git push')
        && !str_contains($runner, 'git reset'),
    'One-command runner must not contain infrastructure, production or history-changing commands'
);
$assertTrue(
    str_contains($launcher, 'set -euo pipefail')
        && str_contains($launcher, '/ops/runtime/run-staging-read-only-checkpoint.sh')
        && !str_contains($launcher, 'curl ')
        && !str_contains($launcher, 'wget '),
    'Short launcher must delegate locally without downloading code'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingOneCommandReadOnlyCheckpointContractTest passed: {$assertions} assertions.\n"
);
