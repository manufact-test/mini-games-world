<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$runner = file_get_contents($projectRoot . '/ops/ci/run-portable-focused-suite.sh');
$check = file_get_contents($projectRoot . '/ops/checks/db-primary-portable-self-hosted-ci-local.sh');
$workflow = file_get_contents($projectRoot . '/.github/workflows/portable-self-hosted-focused-suite.yml');
$docs = file_get_contents($projectRoot . '/ops/ci/PORTABLE_SELF_HOSTED_CI.md');
$manifest = file_get_contents($projectRoot . '/ops/ci/portable-focused-suite-manifest.json');
if (!is_string($runner) || !is_string($check) || !is_string($workflow)
    || !is_string($docs) || !is_string($manifest)) {
    throw new RuntimeException('Portable self-hosted CI sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$suiteRun = strpos($runner, 'bash "$SUITE_SCRIPT"');
$publicHtmlGuard = strpos($runner, 'CI checkout must not be inside public_html');
$phpVersionGuard = strpos($runner, 'portable CI requires PHP 8.3.x');
$extensionGuard = strpos($runner, 'for extension_name in json pdo pdo_sqlite openssl mbstring');
$manifestGuard = strpos($runner, 'focused suite manifest is invalid');
$worktreeGuard = strpos($runner, 'checkout changes are present before the suite');
$outputGuard = strpos($runner, 'portable CI artifacts must stay outside the repository checkout');
$emptyOutputGuard = strpos($runner, 'portable CI artifact directory must be empty before the run');
$assertTrue(
    $suiteRun !== false
        && $publicHtmlGuard !== false
        && $phpVersionGuard !== false
        && $extensionGuard !== false
        && $manifestGuard !== false
        && $worktreeGuard !== false
        && $outputGuard !== false
        && $emptyOutputGuard !== false
        && $publicHtmlGuard < $suiteRun
        && $phpVersionGuard < $suiteRun
        && $extensionGuard < $suiteRun
        && $manifestGuard < $suiteRun
        && $worktreeGuard < $suiteRun
        && $outputGuard < $suiteRun
        && $emptyOutputGuard < $suiteRun,
    'Portable runner must complete every preflight before the focused suite'
);
$assertTrue(
    str_contains($runner, 'SUITE_SCRIPT="ops/checks/db-primary-portable-self-hosted-ci-local.sh"')
        && str_contains($runner, 'MANIFEST_FILE="ops/ci/portable-focused-suite-manifest.json"')
        && str_contains($runner, 'v1-portable-db-primary-focused-suite')
        && str_contains($runner, 'timeout --version')
        && str_contains($runner, 'GNU coreutils')
        && str_contains($runner, 'timeout --signal=TERM --kill-after=15')
        && str_contains($runner, 'MGW_CI_TIMEOUT_SECONDS must be between 60 and 7200'),
    'Portable runner must use the exact bounded manifest-backed entrypoint'
);
$assertTrue(
    str_contains($runner, 'umask 077')
        && str_contains($runner, '[[ ! -L "$OUTPUT_DIR" ]]')
        && str_contains($runner, 'artifact files must not be symbolic links')
        && str_contains($runner, 'find "$CANONICAL_OUTPUT_DIR" -mindepth 1 -maxdepth 1')
        && str_contains($runner, 'chmod 0700 "$CANONICAL_OUTPUT_DIR"')
        && str_contains($runner, 'chmod 0600 "$LOG_FILE"')
        && str_contains($runner, 'chmod 0600 "$SUMMARY_FILE"'),
    'Portable runner must protect a fresh private artifact directory and files'
);
$assertTrue(
    str_contains($runner, 'cp "$MANIFEST_FILE" "$MANIFEST_ARTIFACT_FILE"')
        && str_contains($runner, 'copied focused suite manifest fingerprint does not match')
        && str_contains($runner, 'focused-suite-manifest.json'),
    'Portable runner must archive the exact verified suite manifest'
);
$assertTrue(
    substr_count($runner, 'git status --porcelain=v1 --untracked-files=all') === 2
        && !str_contains($runner, '--untracked-files=no')
        && str_contains($runner, 'focused suite changed the repository checkout')
        && str_contains($runner, 'tracked_worktree_unchanged'),
    'Portable runner must detect tracked and untracked checkout changes before and after the suite'
);
$assertTrue(
    str_contains($runner, 'for command_name in bash git date find cp tee cat chmod mkdir "$PHP_BIN"')
        && str_contains($runner, 'required command is unavailable'),
    'Portable runner must preflight every host command used by the evidence path'
);
$assertTrue(
    str_contains($runner, '"suite_manifest_sha256" => getenv("MGW_CI_MANIFEST_SHA256")')
        && str_contains($runner, '"suite_manifest_script_count" => (int)getenv("MGW_CI_MANIFEST_SCRIPT_COUNT")'),
    'Portable summary must bind exact suite manifest SHA and script count'
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
    $assertTrue(str_contains($runner, $safeField), 'Portable summary is missing safe field: ' . $safeField);
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
    'Portable runner must not contain live infrastructure or secret commands'
);

$outbox = strpos($check, 'bash ops/checks/db-primary-projection-outbox-local.sh');
$worker = strpos($check, 'bash ops/checks/db-primary-projection-worker-local.sh');
$apiStack = strpos($check, 'bash ops/checks/db-primary-staging-api-read-only-smoke-local.sh');
$manifestTest = strpos($check, 'RuntimePrimaryPortableFocusedSuiteManifestTest.php');
$contractTest = strpos($check, 'RuntimePrimaryPortableSelfHostedCiContractTest.php');
$assertTrue(
    $outbox !== false
        && $worker !== false
        && $apiStack !== false
        && $manifestTest !== false
        && $contractTest !== false
        && $outbox < $worker
        && $worker < $apiStack
        && $apiStack < $manifestTest
        && $manifestTest < $contractTest,
    'Portable focused check must run outbox, worker, API stack and manifest contracts in order'
);
$assertTrue(
    str_contains($check, 'bash -n "$file"')
        && str_contains($check, 'DB-primary portable self-hosted CI focused verification passed.'),
    'Portable focused check must lint shell sources and expose an exact success marker'
);

$assertTrue(
    preg_match('/^on:\s*\n\s+workflow_dispatch:\s*$/m', $workflow) === 1
        && preg_match('/^\s*(push|pull_request|schedule):/m', $workflow) !== 1,
    'Self-hosted workflow must be manual-only'
);
$assertTrue(
    str_contains($workflow, 'runs-on: [self-hosted, linux, x64, mgw-ci]')
        && !str_contains($workflow, 'ubuntu-latest')
        && !str_contains($workflow, 'windows-latest')
        && !str_contains($workflow, 'macos-latest'),
    'Workflow must require only the dedicated self-hosted runner labels'
);
$assertTrue(
    str_contains($workflow, 'persist-credentials: false')
        && str_contains($workflow, 'clean: true')
        && str_contains($workflow, 'bash ops/ci/run-portable-focused-suite.sh')
        && str_contains($workflow, 'actions/upload-artifact@v4')
        && str_contains($workflow, 'focused-suite-manifest.json'),
    'Workflow must upload log, summary and exact manifest from a clean checkout'
);
$assertTrue(
    !str_contains($workflow, 'secrets.')
        && !str_contains($workflow, 'ssh')
        && !str_contains($workflow, 'deploy')
        && !str_contains($workflow, 'cron'),
    'Workflow must not use secrets or infrastructure actions'
);

$assertTrue(
    str_contains($manifest, '"expected_unique_script_count": 13')
        && str_contains($manifest, 'db-primary-projection-outbox-local.sh')
        && str_contains($manifest, 'db-primary-projection-worker-local.sh')
        && str_contains($manifest, 'db-primary-staging-api-read-only-smoke-local.sh')
        && str_contains($manifest, 'db-primary-all-module-projector-local.sh'),
    'Portable manifest must cover foundational and recursive DB-primary roots'
);
$assertTrue(
    str_contains($docs, 'does not depend on GitHub-hosted minutes')
        && str_contains($docs, 'does not connect to staging or production MySQL')
        && str_contains($docs, 'does not deploy')
        && str_contains($docs, 'does not require repository secrets')
        && str_contains($docs, 'does not install or register a self-hosted runner'),
    'Portable CI documentation must preserve the no-infrastructure boundary'
);
$assertTrue(
    str_contains($docs, 'GitLab CI, Gitea Actions, Forgejo Actions, Jenkins')
        && str_contains($docs, 'bash ops/ci/run-portable-focused-suite.sh'),
    'Portable CI documentation must remain runner-neutral'
);

fwrite(STDOUT, "RuntimePrimaryPortableSelfHostedCiContractTest passed: {$assertions} assertions.\n");
