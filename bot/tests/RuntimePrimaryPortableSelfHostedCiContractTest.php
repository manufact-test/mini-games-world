<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$runner = file_get_contents($projectRoot . '/ops/ci/run-portable-focused-suite.sh');
$check = file_get_contents($projectRoot . '/ops/checks/db-primary-portable-self-hosted-ci-local.sh');
$workflow = file_get_contents($projectRoot . '/.github/workflows/portable-self-hosted-focused-suite.yml');
$docs = file_get_contents($projectRoot . '/ops/ci/PORTABLE_SELF_HOSTED_CI.md');
if (!is_string($runner) || !is_string($check) || !is_string($workflow) || !is_string($docs)) {
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
$worktreeGuard = strpos($runner, 'tracked checkout changes are present before the suite');
$outputGuard = strpos($runner, 'portable CI artifacts must stay outside the repository checkout');
$assertTrue(
    $suiteRun !== false
        && $publicHtmlGuard !== false
        && $phpVersionGuard !== false
        && $extensionGuard !== false
        && $worktreeGuard !== false
        && $outputGuard !== false
        && $publicHtmlGuard < $suiteRun
        && $phpVersionGuard < $suiteRun
        && $extensionGuard < $suiteRun
        && $worktreeGuard < $suiteRun
        && $outputGuard < $suiteRun,
    'Portable runner must complete every preflight before the focused suite'
);
$assertTrue(
    str_contains($runner, 'SUITE_SCRIPT="ops/checks/db-primary-portable-self-hosted-ci-local.sh"')
        && str_contains($runner, 'timeout --signal=TERM --kill-after=15')
        && str_contains($runner, 'MGW_CI_TIMEOUT_SECONDS must be between 60 and 7200'),
    'Portable runner must use the exact bounded focused-suite entrypoint'
);
$assertTrue(
    str_contains($runner, '[[ ! -L "$OUTPUT_DIR" ]]')
        && str_contains($runner, 'artifact files must not be symbolic links')
        && str_contains($runner, 'chmod 0700 "$CANONICAL_OUTPUT_DIR"')
        && str_contains($runner, 'chmod 0600 "$LOG_FILE"')
        && str_contains($runner, 'chmod 0600 "$SUMMARY_FILE"'),
    'Portable runner must protect artifact directory and files'
);
$assertTrue(
    str_contains($runner, 'git status --porcelain=v1 --untracked-files=no')
        && str_contains($runner, 'focused suite changed tracked files')
        && str_contains($runner, 'tracked_worktree_unchanged'),
    'Portable runner must prove tracked worktree immutability'
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

$oldSuite = strpos($check, 'bash ops/checks/db-primary-staging-api-read-only-smoke-local.sh');
$newTest = strpos($check, 'RuntimePrimaryPortableSelfHostedCiContractTest.php');
$assertTrue(
    $oldSuite !== false && $newTest !== false && $oldSuite < $newTest,
    'Portable focused check must run the complete inherited stack before its new contract test'
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
        && str_contains($workflow, 'actions/upload-artifact@v4'),
    'Workflow must use a clean non-credentialed checkout and portable runner'
);
$assertTrue(
    !str_contains($workflow, 'secrets.')
        && !str_contains($workflow, 'ssh')
        && !str_contains($workflow, 'deploy')
        && !str_contains($workflow, 'cron'),
    'Workflow must not use secrets or infrastructure actions'
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
