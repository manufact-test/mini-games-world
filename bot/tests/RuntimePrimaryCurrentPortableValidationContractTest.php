<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$runner = file_get_contents($projectRoot . '/ops/ci/run-current-portable-focused-suite.sh');
$check = file_get_contents($projectRoot . '/ops/checks/db-primary-current-portable-validation-local.sh');
$workflow = file_get_contents($projectRoot . '/.github/workflows/current-portable-focused-suite.yml');
$manifest = file_get_contents($projectRoot . '/ops/ci/current-portable-suite-manifest.json');
if (!is_string($runner) || !is_string($check)
    || !is_string($workflow) || !is_string($manifest)) {
    throw new RuntimeException('Current portable validation sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$suiteRun = strpos($runner, 'bash "$SUITE_SCRIPT"');
foreach ([
    'CI checkout must not be inside public_html.',
    'GNU coreutils timeout is required for a bounded portable suite.',
    'current portable CI requires PHP 8.3.x.',
    'for extension_name in json pdo pdo_sqlite openssl mbstring',
    'focused suite manifest is invalid.',
    'checkout changes are present before the suite.',
    'portable CI artifacts must stay outside the repository checkout.',
    'portable CI artifact directory must be empty before the run.',
] as $preflight) {
    $position = strpos($runner, $preflight);
    $assertTrue(
        $position !== false && $suiteRun !== false && $position < $suiteRun,
        'Current portable preflight is missing or late: ' . $preflight
    );
}
$assertTrue(
    str_starts_with($runner, "#!/usr/bin/env bash\nset -euo pipefail\numask 077\n")
        && str_contains($runner, 'SUITE_SCRIPT="ops/checks/db-primary-current-portable-validation-local.sh"')
        && str_contains($runner, 'MANIFEST_FILE="ops/ci/current-portable-suite-manifest.json"')
        && str_contains($runner, 'v2-current-db-primary-focused-suite')
        && str_contains($runner, '(\$data["expected_unique_script_count"] ?? null) !== 14') === false
        && str_contains($runner, '($data["expected_unique_script_count"] ?? null) !== 14'),
    'Current portable runner must use the exact strict suite and manifest contract'
);
$assertTrue(
    substr_count($runner, 'git status --porcelain=v1 --untracked-files=all') === 2
        && !str_contains($runner, '--untracked-files=no')
        && str_contains($runner, 'focused suite changed the repository checkout')
        && str_contains($runner, 'repository_checkout_unchanged'),
    'Current portable runner must detect tracked and untracked checkout changes before and after execution'
);
$assertTrue(
    str_contains($runner, 'find "$CANONICAL_OUTPUT_DIR" -mindepth 1 -maxdepth 1')
        && str_contains($runner, 'chmod 0700 "$CANONICAL_OUTPUT_DIR"')
        && str_contains($runner, 'chmod 0600 "$LOG_FILE" "$MANIFEST_ARTIFACT_FILE"')
        && str_contains($runner, 'chmod 0600 "$SUMMARY_FILE"')
        && str_contains($runner, 'current-focused-suite.log')
        && str_contains($runner, 'current-focused-suite-summary.json')
        && str_contains($runner, 'current-focused-suite-manifest.json'),
    'Current portable runner must create one fresh private exact evidence bundle'
);
$assertTrue(
    str_contains($runner, 'file_mode()')
        && str_contains($runner, 'artifact directory must have exact mode 0700')
        && str_contains($runner, 'log and manifest must have exact mode 0600')
        && str_contains($runner, 'summary must have exact mode 0600')
        && !str_contains($runner, 'chmod 0700 "$CANONICAL_OUTPUT_DIR" 2>/dev/null || true')
        && !str_contains($runner, 'chmod 0600 "$SUMMARY_FILE" 2>/dev/null || true'),
    'Current portable runner must require and verify exact private permissions'
);
$assertTrue(
    substr_count($runner, 'PIPE_STATUS=("${PIPESTATUS[@]}")') === 1
        && str_contains($runner, 'TEE_EXIT_CODE="${PIPE_STATUS[1]:-127}"')
        && str_contains($runner, 'focused-suite log capture failed')
        && str_contains($runner, 'SUITE_EXIT_CODE=4'),
    'Current portable runner must fail a successful suite when tee log capture fails'
);
$assertTrue(
    str_contains($runner, 'shopt -s nullglob dotglob')
        && str_contains($runner, 'BUNDLE_ENTRIES=("$CANONICAL_OUTPUT_DIR"/*)')
        && str_contains($runner, '"${#BUNDLE_ENTRIES[@]}" -eq 3')
        && str_contains($runner, 'exact evidence bundle is incomplete or unsafe'),
    'Current portable runner must finish with exactly the three expected real evidence files'
);
$assertTrue(
    str_contains($runner, 'for command_name in bash git date find cp tee cat chmod mkdir grep timeout "$PHP_BIN"')
        && str_contains($runner, "timeout --version 2>/dev/null | grep -q 'GNU coreutils'")
        && str_contains($runner, 'GNU coreutils timeout is required for a bounded portable suite.')
        && str_contains($runner, 'timeout --signal=TERM --kill-after=15')
        && str_contains($runner, 'MGW_CI_TIMEOUT_SECONDS must be between 60 and 7200')
        && !str_contains($runner, "else\n  bash \"$SUITE_SCRIPT\""),
    'Current portable runner must fail closed without a compatible bounded timeout'
);
$assertTrue(
    str_contains($runner, '"report_type" => "mvp-14.8.6s-current-portable-validation"')
        && str_contains($runner, '"suite" => "db-primary-current-portable-validation-local"')
        && str_contains($runner, '"suite_manifest_script_count" => (int)getenv("MGW_CI_MANIFEST_SCRIPT_COUNT")')
        && str_contains($runner, '"repository_commit" => getenv("MGW_CI_COMMIT_SHA")')
        && str_contains($runner, '"log_sha256" => getenv("MGW_CI_LOG_SHA256")'),
    'Current portable summary must bind exact suite, manifest, commit and log evidence'
);
foreach ([
    '"live_database_contacted" => false',
    '"private_config_required" => false',
    '"application_entrypoints_changed" => false',
    '"cron_changed" => false',
    '"deployment_performed" => false',
    '"production_changed" => false',
    '"sensitive_identifiers_exposed" => false',
] as $safeField) {
    $assertTrue(str_contains($runner, $safeField), 'Current portable summary is missing safe field: ' . $safeField);
}
$assertTrue(
    !str_contains($runner, 'mysql ')
        && !str_contains($runner, 'mysqldump')
        && !str_contains($runner, 'curl ')
        && !str_contains($runner, 'wget ')
        && !str_contains($runner, 'ssh ')
        && !str_contains($runner, 'scp ')
        && !str_contains($runner, 'rsync ')
        && !str_contains($runner, 'git push')
        && !str_contains($runner, 'git merge')
        && !str_contains($runner, 'DATABASE_URL')
        && !str_contains($runner, 'DB_PASSWORD')
        && !str_contains($runner, 'HOSTINGER'),
    'Current portable runner must remain free of live infrastructure and secret operations'
);

$outbox = strpos($check, 'bash ops/checks/db-primary-projection-outbox-local.sh');
$worker = strpos($check, 'bash ops/checks/db-primary-projection-worker-local.sh');
$smokeVerifier = strpos($check, 'bash ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh');
$manifestTest = strpos($check, 'RuntimePrimaryCurrentPortableSuiteManifestTest.php');
$contractTest = strpos($check, 'RuntimePrimaryCurrentPortableValidationContractTest.php');
$assertTrue(
    $outbox !== false && $worker !== false && $smokeVerifier !== false
        && $manifestTest !== false && $contractTest !== false
        && $outbox < $worker && $worker < $smokeVerifier
        && $smokeVerifier < $manifestTest && $manifestTest < $contractTest,
    'Current portable check must run outbox, worker, current smoke verifier and local contracts in order'
);
$assertTrue(
    str_contains($check, 'bash -n "$file"')
        && str_contains($check, 'DB-primary current portable validation focused verification passed.'),
    'Current portable check must shell-lint itself and expose an exact final success marker'
);

$assertTrue(
    preg_match('/^on:\s*\n\s+pull_request:/m', $workflow) === 1
        && preg_match('/^\s+workflow_dispatch:\s*$/m', $workflow) === 1
        && str_contains($workflow, '- agent/mvp-14-8-6s-current-portable-validation')
        && preg_match('/^\s*(push|schedule):/m', $workflow) !== 1,
    'Current portable workflow must run only for the exact PR base or explicit manual dispatch'
);
$assertTrue(
    str_contains($workflow, 'runs-on: ubuntu-latest')
        && str_contains($workflow, 'uses: shivammathur/setup-php@v2')
        && str_contains($workflow, "php-version: '8.3'")
        && str_contains($workflow, 'extensions: json, pdo, pdo_sqlite, openssl, mbstring')
        && !str_contains($workflow, 'runs-on: [self-hosted'),
    'Current portable workflow must use GitHub-hosted Linux with exact PHP 8.3'
);
$assertTrue(
    str_contains($workflow, 'persist-credentials: false')
        && str_contains($workflow, 'clean: true')
        && str_contains($workflow, 'bash ops/ci/run-current-portable-focused-suite.sh')
        && str_contains($workflow, 'github.run_id')
        && str_contains($workflow, 'github.run_attempt')
        && str_contains($workflow, 'actions/upload-artifact@v4')
        && str_contains($workflow, 'current-focused-suite-manifest.json')
        && str_contains($workflow, 'if-no-files-found: error'),
    'Workflow must use attempt-isolated evidence from a clean credential-free checkout'
);
$assertTrue(
    !str_contains($workflow, 'secrets.')
        && !str_contains($workflow, 'ssh')
        && !str_contains($workflow, 'deploy')
        && !str_contains($workflow, 'cron'),
    'Current portable workflow must not use secrets or infrastructure actions'
);

$assertTrue(
    str_contains($manifest, '"contract_version": "v2-current-db-primary-focused-suite"')
        && str_contains($manifest, '"expected_unique_script_count": 14')
        && str_contains($manifest, 'db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh')
        && str_contains($manifest, 'db-primary-all-module-projector-local.sh'),
    'Current portable manifest must bind the current smoke verifier and complete recursive stack'
);

fwrite(STDOUT, "RuntimePrimaryCurrentPortableValidationContractTest passed: {$assertions} assertions.\n");
