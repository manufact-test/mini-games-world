<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$rollback = file_get_contents($projectRoot . '/ops/runtime/run-staging-bounded-mutating-checkpoint.sh');
$api = file_get_contents($projectRoot . '/ops/runtime/run-staging-api-mutating-checkpoint.sh');
$rollbackShortcut = file_get_contents($projectRoot . '/m');
$apiShortcut = file_get_contents($projectRoot . '/a');
foreach (compact('rollback', 'api', 'rollbackShortcut', 'apiShortcut') as $label => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Staging mutating checkpoint shell source is unavailable: ' . $label . '.');
    }
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([$rollback, $api] as $source) {
    $assertTrue(
        str_contains($source, 'set -eEuo pipefail')
            && str_contains($source, 'umask 077')
            && str_contains($source, 'PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400')
            && str_contains($source, '/opt/alt/php83/usr/bin/php')
            && str_contains($source, 'git -C "$PROJECT_ROOT" status --porcelain=v1 --untracked-files=all'),
        'Mutating checkpoint shell must be strict, private, exact PHP 8.3 and clean-checkout bound.'
    );
    $assertTrue(
        !str_contains($source, 'crontab')
            && !str_contains($source, 'WebhookHandler.php')
            && !str_contains($source, 'webhook.php')
            && !str_contains($source, 'curl ')
            && !str_contains($source, 'curl_')
            && !str_contains($source, 'git push')
            && !str_contains($source, 'git checkout'),
        'Mutating checkpoint shell must not change Cron, webhook, Git refs or call HTTP.'
    );
    foreach ([
        'PERSISTENT_CONFIG_CHANGED=false',
        'WEBHOOK_ALLOWED=false',
        'CRON_CHANGED=false',
        'PRODUCTION_CHANGED=false',
    ] as $marker) {
        $assertTrue(str_contains($source, $marker), 'Mutating checkpoint shell safety marker is missing: ' . $marker);
    }
}

$prepare = strpos($rollback, 'prepare-staging-bounded-mutating-smoke.php');
$runRollback = strpos($rollback, 'run-staging-bounded-mutating-smoke.php');
$verifyRollback = strpos($rollback, 'verify-staging-bounded-mutating-smoke-evidence.php');
$assertTrue(
    $prepare !== false && $runRollback !== false && $verifyRollback !== false
        && $prepare < $runRollback && $runRollback < $verifyRollback,
    'Rollback-only checkpoint must prepare, execute and independently verify in order.'
);
$assertTrue(
    str_contains($rollback, "-name 'staging-read-only-checkpoint-receipt-*.json'")
        && str_contains($rollback, '--ttl-seconds=300')
        && str_contains($rollback, '--max-age-seconds=1800')
        && str_contains($rollback, 'MGW_STAGING_BOUNDED_MUTATING_SMOKE=PASSED')
        && str_contains($rollback, 'ROLLBACK_VERIFIED=true')
        && str_contains($rollback, 'COMMITTED_STATE_WRITES=0')
        && str_contains($rollback, 'COMMITTED_OUTBOX_EVENTS=0'),
    'Rollback-only checkpoint must bind a fresh receipt and prove exact rollback with zero committed writes.'
);

$runApi = strpos($api, 'run-staging-api-mutating-smoke.php');
$verifyApi = strpos($api, 'verify-staging-api-mutating-smoke-evidence.php');
$assertTrue(
    $runApi !== false && $verifyApi !== false && $runApi < $verifyApi,
    'Real API checkpoint must execute before independent evidence verification.'
);
$assertTrue(
    str_contains($api, "latest_file 'staging-read-only-checkpoint-receipt-*.json'")
        && str_contains($api, "latest_file 'staging-bounded-mutating-smoke-20*.json'")
        && str_contains($api, "latest_file 'staging-lifecycle-evidence-v4-*.json'")
        && str_contains($api, '--ttl-seconds=300')
        && str_contains($api, '--max-age-seconds=1800'),
    'Real API checkpoint must bind fresh receipt, rollback proof and lifecycle evidence.'
);
foreach ([
    'MGW_STAGING_API_MUTATING_SMOKE=PASSED',
    'REPORT_60_FIELDS=VERIFIED',
    'REAL_API_ENTRYPOINT=bot/api.php',
    'API_ACTION=bootstrap',
    'API_STATE_WRITES=1',
    'CLEANUP_STATE_WRITES=1',
    'COMMITTED_OUTBOX_EVENTS=2',
    'STATE_RESTORED_TO_BASELINE=true',
    'ALL_MODULE_PROJECTION_RESTORED=true',
    'SYNTHETIC_IDENTITY_CLEANUP_VERIFIED=true',
    'JSON_ROLLBACK_SOURCE_UNCHANGED=true',
] as $marker) {
    $assertTrue(str_contains($api, $marker), 'Real API checkpoint proof marker is missing: ' . $marker);
}

$assertTrue(
    str_contains($rollbackShortcut, 'exec bash "$PROJECT_ROOT/ops/runtime/run-staging-bounded-mutating-checkpoint.sh"')
        && str_contains($apiShortcut, 'exec bash "$PROJECT_ROOT/ops/runtime/run-staging-api-mutating-checkpoint.sh"'),
    'Root shortcuts must only exec their exact checkpoint runners.'
);

fwrite(STDOUT, "RuntimePrimaryStagingMutatingCheckpointShellContractTest passed: {$assertions} assertions.\n");
