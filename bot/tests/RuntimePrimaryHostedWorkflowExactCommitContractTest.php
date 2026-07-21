<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$workflow = file_get_contents(
    $projectRoot . '/.github/workflows/current-portable-focused-suite.yml'
);
if (!is_string($workflow)) {
    throw new RuntimeException('Current portable hosted workflow source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$checkout = strpos($workflow, 'Checkout exact deployable revision');
$bind = strpos($workflow, 'Bind exact checked-out commit');
$runtime = strpos($workflow, 'Confirm exact PHP 8.3 runtime');
$suite = strpos($workflow, 'Run current portable focused suite');
$verify = strpos($workflow, 'Verify exact current evidence bundle');
$upload = strpos($workflow, 'Upload current focused-suite evidence');

$assertTrue(
    $checkout !== false && $bind !== false && $runtime !== false
        && $suite !== false && $verify !== false && $upload !== false
        && $checkout < $bind
        && $bind < $runtime
        && $runtime < $suite
        && $suite < $verify
        && $verify < $upload,
    'Hosted workflow stages must preserve exact checkout, bind, runtime, suite, verify and upload order'
);
$assertTrue(
    str_contains($workflow, 'ref: ${{ github.event.pull_request.head.sha || github.sha }}')
        && str_contains($workflow, 'id: bind')
        && str_contains($workflow, 'git rev-parse --verify HEAD')
        && str_contains($workflow, '[[ ! "$commit" =~ ^[a-f0-9]{40}$ ]]')
        && str_contains($workflow, "MGW_CI_EXPECTED_COMMIT=%s\\n")
        && str_contains($workflow, '>> "$GITHUB_ENV"')
        && str_contains($workflow, "commit=%s\\n")
        && str_contains($workflow, '>> "$GITHUB_OUTPUT"'),
    'Hosted workflow must bind the real checked-out deployable commit as env and step output'
);
$assertTrue(
    str_contains($workflow, '--expected-commit="$MGW_CI_EXPECTED_COMMIT"')
        && !str_contains($workflow, '--expected-commit="$GITHUB_SHA"'),
    'Evidence verifier must use the checked-out commit rather than the pull-request merge SHA'
);
$assertTrue(
    str_contains(
        $workflow,
        'name: mgw-current-focused-${{ steps.bind.outputs.commit }}-${{ github.run_id }}-${{ github.run_attempt }}'
    )
        && !str_contains(
            $workflow,
            'name: mgw-current-focused-${{ github.sha }}-${{ github.run_id }}-${{ github.run_attempt }}'
        ),
    'Hosted artifact name must identify the exact deployable commit rather than the merge SHA'
);
$assertTrue(
    str_contains($workflow, 'runs-on: ubuntu-24.04')
        && str_contains($workflow, 'PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400')
        && str_contains($workflow, '["json", "pdo", "pdo_sqlite", "openssl", "mbstring"]')
        && str_contains($workflow, 'timeout --version | head -n 1'),
    'Hosted workflow must verify exact PHP 8.3 and required runtime dependencies'
);
$assertTrue(
    str_contains($workflow, 'persist-credentials: false')
        && str_contains($workflow, 'clean: true')
        && str_contains($workflow, 'permissions:')
        && str_contains($workflow, 'contents: read')
        && !str_contains($workflow, 'secrets.')
        && !str_contains($workflow, 'ssh ')
        && !str_contains($workflow, 'deploy'),
    'Hosted workflow must remain credential-free and infrastructure-neutral'
);
$assertTrue(
    str_contains($workflow, 'github.run_id')
        && str_contains($workflow, 'github.run_attempt')
        && str_contains($workflow, 'if-no-files-found: error')
        && str_contains($workflow, 'retention-days: 7'),
    'Hosted artifacts must remain attempt-isolated and fail closed when missing'
);

fwrite(
    STDOUT,
    "RuntimePrimaryHostedWorkflowExactCommitContractTest passed: {$assertions} assertions.\n"
);
