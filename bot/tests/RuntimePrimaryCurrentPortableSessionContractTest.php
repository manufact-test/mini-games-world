<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$wrapper = file_get_contents(
    $projectRoot . '/ops/ci/run-and-verify-current-portable-focused-suite.sh'
);
$runner = file_get_contents(
    $projectRoot . '/ops/ci/run-current-portable-focused-suite.sh'
);
$verifier = file_get_contents(
    $projectRoot . '/ops/ci/verify-current-portable-focused-suite-evidence.php'
);
if (!is_string($wrapper) || !is_string($runner) || !is_string($verifier)) {
    throw new RuntimeException('Current portable session sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$runnerCall = strpos($wrapper, 'bash "$RUNNER_SCRIPT"');
$verifierCall = strpos($wrapper, '"$PHP_BIN" "$VERIFIER_SCRIPT"');
$sessionCreate = strpos($wrapper, 'mkdir "$REQUESTED_SESSION_ROOT"');
$assertTrue(
    $runnerCall !== false && $verifierCall !== false && $sessionCreate !== false
        && $sessionCreate < $runnerCall
        && $runnerCall < $verifierCall,
    'Portable session must create an isolated root, run the suite and then verify evidence'
);

$assertTrue(
    str_starts_with($wrapper, "#!/usr/bin/env bash\nset -euo pipefail\numask 077\n")
        && str_contains($wrapper, 'RUNNER_SCRIPT="ops/ci/run-current-portable-focused-suite.sh"')
        && str_contains($wrapper, 'VERIFIER_SCRIPT="ops/ci/verify-current-portable-focused-suite-evidence.php"')
        && str_contains($wrapper, 'MGW_CI_VERIFY_MAX_AGE_SECONDS:-3600'),
    'Portable session must use the exact strict runner/verifier contract'
);

$timeoutGuard = strpos($wrapper, "timeout --version 2>/dev/null | grep -q 'GNU coreutils'");
$phpGuard = strpos($wrapper, 'current portable session requires PHP 8.3.x.');
$assertTrue(
    $timeoutGuard !== false && $phpGuard !== false
        && $timeoutGuard < $sessionCreate
        && $phpGuard < $sessionCreate
        && str_contains($wrapper, 'GNU coreutils timeout is required for a bounded portable session.')
        && str_contains($wrapper, 'PHP_VERSION_ID >= 80300')
        && str_contains($wrapper, 'PHP_VERSION_ID < 80400')
        && str_contains($wrapper, 'timeout grep "$PHP_BIN"'),
    'Portable session must fail before filesystem changes without GNU timeout, grep or PHP 8.3'
);

$cleanGuard = strpos($wrapper, 'git status --porcelain=v1 --untracked-files=all');
$commitGuard = strpos($wrapper, 'git rev-parse --verify HEAD');
$assertTrue(
    $cleanGuard !== false && $commitGuard !== false
        && $cleanGuard < $sessionCreate
        && $commitGuard < $sessionCreate
        && str_contains($wrapper, 'checkout must be clean before creating a CI session.')
        && str_contains($wrapper, '[[ "$COMMIT_SHA" =~ ^[a-f0-9]{40}$ ]]'),
    'Portable session must bind a clean exact commit before creating artifacts'
);

$assertTrue(
    str_contains($wrapper, '[[ ! -e "$SESSION_ROOT" && ! -L "$SESSION_ROOT" ]]')
        && str_contains($wrapper, 'CI session root must not already exist.')
        && str_contains($wrapper, 'CI session root must stay outside the repository checkout.')
        && str_contains($wrapper, 'CI session root must stay outside public_html.')
        && str_contains($wrapper, 'REQUESTED_SESSION_ROOT="$SESSION_ROOT"')
        && str_contains($wrapper, 'chmod 0700 "$REQUESTED_SESSION_ROOT"')
        && !str_contains($wrapper, 'mkdir -p "$REQUESTED_SESSION_ROOT"'),
    'Portable session root must be fresh, external, private and non-symlink'
);

$assertTrue(
    str_contains($wrapper, 'SESSION_ROOT="$(cd "$REQUESTED_SESSION_ROOT" && pwd -P)"')
        && str_contains($wrapper, '[[ "$SESSION_ROOT" == "$REQUESTED_SESSION_ROOT" ]]')
        && str_contains($wrapper, 'must use its canonical path without symlink parents')
        && str_contains($wrapper, 'canonical CI session root must stay outside the repository checkout')
        && str_contains($wrapper, 'canonical CI session root must stay outside public_html')
        && str_contains($wrapper, 'CI session root must have exact mode 0700.'),
    'Portable session must reject symlink-parent path escapes and verify exact root permissions'
);

$assertTrue(
    str_contains($wrapper, 'EVIDENCE_DIR="$SESSION_ROOT/evidence"')
        && str_contains($wrapper, 'VERIFICATION_FILE="$SESSION_ROOT/current-focused-suite-verification.json"')
        && str_contains($wrapper, 'export MGW_CI_OUTPUT_DIR="$EVIDENCE_DIR"')
        && !str_contains($wrapper, 'VERIFICATION_FILE="$EVIDENCE_DIR/'),
    'Verification output must remain outside the exact three-file evidence bundle'
);

$assertTrue(
    str_contains($wrapper, '--evidence-dir="$EVIDENCE_DIR"')
        && str_contains($wrapper, '--expected-commit="$COMMIT_SHA"')
        && str_contains($wrapper, '--max-age-seconds="$MAX_AGE_SECONDS"')
        && str_contains($wrapper, '> "$VERIFICATION_FILE"'),
    'Portable session must verify the exact generated bundle, commit and freshness bound'
);

$assertTrue(
    str_contains($wrapper, 'chmod 0600 "$VERIFICATION_FILE"')
        && str_contains($wrapper, '[[ -f "$VERIFICATION_FILE" && ! -L "$VERIFICATION_FILE" ]]')
        && str_contains($wrapper, 'verification report must have exact mode 0600.')
        && str_contains($wrapper, '"${#SESSION_ENTRIES[@]}" -eq 2')
        && str_contains($wrapper, 'CI session root exact entries are incomplete or unsafe.'),
    'Portable session must secure verification output and allow only two exact safe entries'
);

$assertTrue(
    str_contains($runner, 'GNU coreutils timeout is required for a bounded portable suite.')
        && str_contains($runner, 'timeout --signal=TERM --kill-after=15')
        && str_contains($verifier, "'--evidence-dir=' => 'evidence_dir'")
        && str_contains($verifier, "'--expected-commit=' => 'commit'"),
    'Portable session must compose the hardened runner and strict verifier'
);

$assertTrue(
    !str_contains($wrapper, 'mysql ')
        && !str_contains($wrapper, 'mysqldump')
        && !str_contains($wrapper, 'curl ')
        && !str_contains($wrapper, 'wget ')
        && !str_contains($wrapper, 'ssh ')
        && !str_contains($wrapper, 'scp ')
        && !str_contains($wrapper, 'rsync ')
        && !str_contains($wrapper, 'git push')
        && !str_contains($wrapper, 'git merge')
        && !str_contains($wrapper, 'DATABASE_URL')
        && !str_contains($wrapper, 'DB_PASSWORD')
        && !str_contains($wrapper, 'HOSTINGER'),
    'Portable session must remain free of live infrastructure and secret operations'
);

fwrite(STDOUT, "RuntimePrimaryCurrentPortableSessionContractTest passed: {$assertions} assertions.\n");
