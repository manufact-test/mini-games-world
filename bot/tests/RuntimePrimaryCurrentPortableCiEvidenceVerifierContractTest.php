<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$verifier = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryCurrentPortableCiEvidenceVerifier.php'
);
$cli = file_get_contents(
    $projectRoot . '/ops/ci/verify-current-portable-focused-suite-evidence.php'
);
$runner = file_get_contents(
    $projectRoot . '/ops/ci/run-current-portable-focused-suite.sh'
);
$workflow = file_get_contents(
    $projectRoot . '/.github/workflows/current-portable-focused-suite.yml'
);
$manifest = file_get_contents(
    $projectRoot . '/ops/ci/current-portable-suite-manifest.json'
);
if (!is_string($verifier) || !is_string($cli) || !is_string($runner)
    || !is_string($workflow) || !is_string($manifest)) {
    throw new RuntimeException('Current portable evidence verifier sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$summaryFields = [
    'ok', 'report_type', 'suite', 'suite_manifest_sha256',
    'suite_manifest_script_count', 'repository_commit', 'php_version',
    'started_at_utc', 'finished_at_utc', 'duration_seconds', 'exit_code',
    'log_sha256', 'repository_checkout_unchanged', 'live_database_contacted',
    'private_config_required', 'application_entrypoints_changed', 'cron_changed',
    'deployment_performed', 'production_changed', 'sensitive_identifiers_exposed',
];
$assertTrue(count($summaryFields) === 20, 'Current portable summary field count changed unexpectedly');
foreach ($summaryFields as $field) {
    $assertTrue(
        str_contains($runner, '"' . $field . '"')
            && str_contains($verifier, "'{$field}'"),
        'Current portable producer/verifier field is not synchronized: ' . $field
    );
}

$assertTrue(
    str_contains($verifier, "public const REPORT_TYPE = 'mvp-14.8.6s-current-portable-validation'")
        && str_contains($verifier, "public const SUITE = 'db-primary-current-portable-validation-local'")
        && str_contains($verifier, "public const MANIFEST_CONTRACT = 'v2-current-db-primary-focused-suite'")
        && str_contains($verifier, 'public const EXPECTED_SCRIPT_COUNT = 14'),
    'Verifier must bind exact current portable identities'
);
$assertTrue(
    str_contains($verifier, "private const SUMMARY_FILE = 'current-focused-suite-summary.json'")
        && str_contains($verifier, "private const LOG_FILE = 'current-focused-suite.log'")
        && str_contains($verifier, "private const MANIFEST_FILE = 'current-focused-suite-manifest.json'")
        && str_contains($verifier, 'exactly three evidence files'),
    'Verifier must require the exact three-file current portable bundle'
);
$assertTrue(
    str_contains($verifier, 'MAX_SUMMARY_BYTES = 65_536')
        && str_contains($verifier, 'MAX_MANIFEST_BYTES = 262_144')
        && str_contains($verifier, 'MAX_LOG_BYTES = 20_971_520')
        && str_contains($verifier, 'could not be read exactly'),
    'Verifier must use bounded exact evidence reads'
);
$assertTrue(
    str_contains($verifier, 'must use its canonical path')
        && str_contains($verifier, 'must not be verified inside public_html')
        && substr_count($verifier, 'must not be world-writable') >= 2
        && str_contains($verifier, 'is_link($path) || !is_file($path)'),
    'Verifier must reject noncanonical, public, symlink and world-writable evidence'
);
$assertTrue(
    str_contains($verifier, "'ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh'")
        && str_contains($verifier, "'ops/checks/db-primary-staging-api-read-only-smoke-local.sh'")
        && str_contains($verifier, "'ops/checks/db-primary-all-module-projector-local.sh'")
        && str_contains($verifier, '($manifest[\'ordered_roots\'] ?? null) !== self::EXPECTED_ROOTS')
        && str_contains($verifier, '($manifest[\'recursive_chain\'] ?? null) !== self::EXPECTED_CHAIN'),
    'Verifier must hard-code the exact fourteen-script manifest graph'
);
$assertTrue(
    str_contains($verifier, '...array_reverse($chainMarkers)')
        && str_contains($verifier, 'DB-primary current portable validation focused verification passed.')
        && str_contains($verifier, 'array_keys($lines, $marker, true)')
        && str_contains($verifier, 'duplicate success marker')
        && str_contains($verifier, 'success-marker order is incomplete or invalid'),
    'Verifier must require exact complete-line markers once and in execution order'
);
$assertTrue(
    str_contains($verifier, "preg_match('/^[a-f0-9]{40}$/', $commit)")
        && str_contains($verifier, "preg_match('/^[a-f0-9]{64}$/', $manifestSha)")
        && str_contains($verifier, 'hash_equals($this->expectedCommit, $commit)')
        && str_contains($verifier, "preg_match('/^8\\.3\\.\\d+")
        && !str_contains($verifier, 'strtolower(trim($commit))'),
    'Verifier must require exact commit, hash and PHP 8.3 identities without normalization'
);
$assertTrue(
    str_contains($verifier, 'MAX_FUTURE_SKEW_SECONDS = 30')
        && str_contains($verifier, 'maximum age must be between 60 and 604800 seconds')
        && str_contains($verifier, 'timestamps must use exact UTC Z format')
        && str_contains($verifier, 'duration does not match its timestamps')
        && str_contains($verifier, 'too old for operational acceptance'),
    'Verifier must enforce exact timeline and bounded freshness'
);
$assertTrue(
    str_contains($verifier, "($summary['exit_code'] ?? null) !== 0")
        && str_contains($verifier, "($summary['repository_checkout_unchanged'] ?? null) !== true")
        && str_contains($verifier, "!is_int($summary['suite_manifest_script_count'] ?? null)")
        && str_contains($verifier, 'summary safety flag must be false'),
    'Verifier must reject stringified, failed, mutated or unsafe summary evidence'
);
$assertTrue(
    !str_contains($verifier, 'StorageFactory')
        && !str_contains($verifier, 'PdoConnectionFactory')
        && !str_contains($verifier, 'DatabaseConfig')
        && !str_contains($verifier, 'file_put_contents(')
        && !str_contains($verifier, 'curl')
        && !str_contains($verifier, 'http_response_code('),
    'Verifier class must remain offline and read-only'
);

foreach ([
    "'--evidence-dir=' => 'evidence_dir'",
    "'--expected-commit=' => 'commit'",
    "'--max-age-seconds=' => 'age'",
] as $option) {
    $assertTrue(str_contains($cli, $option), 'Verifier CLI option is missing: ' . $option);
}
$duplicateGuard = strpos($cli, 'if (isset($seen[$matchedName]))');
$requiredGuard = strpos($cli, "foreach (['evidence_dir', 'commit'] as $required)");
$commitGuard = strpos($cli, "preg_match('/^[a-f0-9]{40}$/', $values['commit'])");
$ageGuard = strpos($cli, '$maximumAgeSeconds < 60 || $maximumAgeSeconds > 604_800');
$classLoad = strpos($cli, "require_once $projectRoot");
$assertTrue(
    $duplicateGuard !== false && $requiredGuard !== false
        && $commitGuard !== false && $ageGuard !== false && $classLoad !== false
        && $duplicateGuard < $classLoad
        && $requiredGuard < $classLoad
        && $commitGuard < $classLoad
        && $ageGuard < $classLoad,
    'Verifier CLI must reject duplicate, missing and malformed inputs before class loading'
);
$assertTrue(
    str_contains($cli, '$value = substr($argument, strlen($matchedPrefix));')
        && !str_contains($cli, 'trim(substr($argument')
        && !str_contains($cli, 'strtolower($value)')
        && str_contains($cli, '~/(?:home|var|tmp|srv|opt)/'),
    'Verifier CLI must preserve exact argument bytes and sanitize private paths'
);
$assertTrue(
    !str_contains($cli, 'file_put_contents(')
        && !str_contains($cli, 'StorageFactory')
        && !str_contains($cli, 'PdoConnectionFactory')
        && !str_contains($cli, 'WebhookHandler')
        && !str_contains($cli, 'production-cutover.php')
        && !str_contains($cli, 'crontab'),
    'Verifier CLI must not write, connect, route or touch infrastructure'
);

$runPosition = strpos($workflow, 'Run current portable focused suite');
$verifyPosition = strpos($workflow, 'Verify exact current evidence bundle');
$uploadPosition = strpos($workflow, 'Upload current focused-suite evidence');
$assertTrue(
    $runPosition !== false && $verifyPosition !== false && $uploadPosition !== false
        && $runPosition < $verifyPosition && $verifyPosition < $uploadPosition,
    'Workflow must verify the current bundle after execution and before upload'
);
$assertTrue(
    preg_match('/^on:\s*\n\s+workflow_dispatch:\s*$/m', $workflow) === 1
        && str_contains($workflow, 'runs-on: [self-hosted, linux, x64, mgw-ci]')
        && str_contains($workflow, 'github.run_id')
        && str_contains($workflow, 'github.run_attempt')
        && str_contains($workflow, 'MGW_CI_VERIFICATION_FILE')
        && str_contains($workflow, '--expected-commit="$GITHUB_SHA"')
        && str_contains($workflow, '--max-age-seconds=3600'),
    'Workflow must remain manual, self-hosted, attempt-isolated and commit-bound'
);
$assertTrue(
    str_contains($workflow, '> "$MGW_CI_VERIFICATION_FILE"')
        && str_contains($workflow, '${{ env.MGW_CI_VERIFICATION_FILE }}')
        && !str_contains($workflow, '$MGW_CI_OUTPUT_DIR/current-portable-verification')
        && !str_contains($workflow, 'secrets.')
        && !str_contains($workflow, 'deploy')
        && !str_contains($workflow, 'ssh'),
    'Workflow verification report must stay outside the exact input bundle without secrets or deploy'
);
$assertTrue(
    str_contains($manifest, '"contract_version": "v2-current-db-primary-focused-suite"')
        && str_contains($manifest, '"expected_unique_script_count": 14')
        && str_contains($runner, 'artifact directory must contain exactly three evidence files')
        && str_contains($runner, 'focused-suite log capture failed'),
    'Verifier must inherit the hardened exact-bundle producer contract'
);

fwrite(STDOUT, "RuntimePrimaryCurrentPortableCiEvidenceVerifierContractTest passed: {$assertions} assertions.\n");
