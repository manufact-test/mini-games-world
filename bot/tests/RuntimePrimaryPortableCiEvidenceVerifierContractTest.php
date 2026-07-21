<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$verifier = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryPortableCiEvidenceVerifier.php'
);
$cli = file_get_contents(
    $projectRoot . '/ops/ci/verify-portable-focused-suite-evidence.php'
);
$workflow = file_get_contents(
    $projectRoot . '/.github/workflows/portable-self-hosted-focused-suite.yml'
);
if (!is_string($verifier) || !is_string($cli) || !is_string($workflow)) {
    throw new RuntimeException('Portable CI evidence verifier sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($verifier, "private const SUMMARY_FILE = 'focused-suite-summary.json'")
        && str_contains($verifier, "private const LOG_FILE = 'focused-suite.log'")
        && str_contains($verifier, "private const MANIFEST_FILE = 'focused-suite-manifest.json'")
        && str_contains($verifier, 'must contain exactly three evidence files'),
    'Verifier must require the exact three-file evidence bundle'
);
$assertTrue(
    str_contains($verifier, 'MAX_SUMMARY_BYTES = 65_536')
        && str_contains($verifier, 'MAX_MANIFEST_BYTES = 262_144')
        && str_contains($verifier, 'MAX_LOG_BYTES = 20_971_520')
        && str_contains($verifier, 'clearstatcache(true, $path)')
        && str_contains($verifier, 'could not be read exactly')
        && str_contains($verifier, 'must not be world-writable'),
    'Verifier must enforce bounded exact private file reads'
);
$assertTrue(
    str_contains($verifier, 'must use its canonical path')
        && str_contains($verifier, 'must not be verified inside public_html')
        && str_contains($verifier, 'is_link($path)')
        && str_contains($verifier, 'is_link($this->evidenceDirectory)')
        && str_contains($verifier, 'evidence directory must not be world-writable'),
    'Verifier must reject public_html, symlinks, non-canonical and world-writable paths'
);
$assertTrue(
    str_contains($verifier, "preg_match('/^[a-f0-9]{40}$/', \$commit)")
        && str_contains($verifier, 'evidence belongs to a different repository commit')
        && str_contains($verifier, "preg_match('/^8\\.3\\.\\d+")
        && str_contains($verifier, 'was not produced by PHP 8.3.x')
        && !str_contains($verifier, 'strtolower(')
        && !str_contains($verifier, 'trim((string)($summary')
        && !str_contains($verifier, '$this->expectedCommit ='),
    'Verifier must bind exact byte-preserved commit, hashes and PHP runtime'
);
$assertTrue(
    substr_count($verifier, "hash('sha256',") >= 2
        && str_contains($verifier, 'manifest SHA-256 does not match')
        && str_contains($verifier, 'log SHA-256 does not match')
        && str_contains($verifier, 'hash_equals('),
    'Verifier must bind exact manifest and log hashes'
);
$assertTrue(
    str_contains($verifier, 'EXPECTED_SCRIPT_COUNT = 13')
        && str_contains($verifier, 'private const EXPECTED_ROOTS = [')
        && str_contains($verifier, 'private const EXPECTED_CHAIN = [')
        && str_contains($verifier, "'ordered_roots'] ?? null) !== self::EXPECTED_ROOTS")
        && str_contains($verifier, "'recursive_chain'] ?? null) !== self::EXPECTED_CHAIN")
        && str_contains($verifier, 'identity or exact script graph is invalid'),
    'Verifier must require the exact 13-script manifest graph, not only counts and links'
);
$assertTrue(
    str_contains($verifier, 'array_reverse($chainMarkers)')
        && str_contains($verifier, 'array_keys($lines, $marker, true)')
        && str_contains($verifier, 'success-marker order is incomplete or invalid')
        && str_contains($verifier, 'duplicate success marker')
        && str_contains($verifier, 'DB-primary portable self-hosted CI focused verification passed.'),
    'Verifier must prove every success marker as one exact line in execution order'
);
$assertTrue(
    str_contains($verifier, 'timestamps must use exact UTC Z format')
        && str_contains($verifier, "->format('Y-m-d\\TH:i:s\\Z')")
        && str_contains($verifier, 'duration does not match its timestamps')
        && str_contains($verifier, '!is_int($duration)')
        && str_contains($verifier, '$duration > 7200'),
    'Verifier must bind exact valid timestamps and strictly typed bounded duration'
);
$assertTrue(
    str_contains($verifier, "(\$summary['exit_code'] ?? null) !== 0")
        && str_contains($verifier, "!is_int(\$summary['suite_manifest_script_count'] ?? null)"),
    'Verifier must reject stringified numeric summary fields'
);
foreach ([
    'live_database_contacted',
    'private_config_required',
    'application_entrypoints_changed',
    'cron_changed',
    'deployment_performed',
    'production_changed',
    'sensitive_identifiers_exposed',
] as $field) {
    $assertTrue(
        str_contains($verifier, "'{$field}'")
            && str_contains($cli, "'{$field}'"),
        'Verifier layer is missing safety field: ' . $field
    );
}
$assertTrue(
    !str_contains($verifier, 'StorageFactory')
        && !str_contains($verifier, 'PdoConnectionFactory')
        && !str_contains($verifier, 'DatabaseConfig')
        && !str_contains($verifier, 'file_put_contents(')
        && !str_contains($verifier, 'curl')
        && !str_contains($verifier, 'http_response_code('),
    'Verifier class must remain offline and read-only'
);
$duplicateGuard = strpos($cli, 'if (isset($seen[$matchedName]))');
$requiredGuard = strpos($cli, "foreach (['directory', 'commit'] as \$required)");
$commitFormat = strpos($cli, "preg_match('/^[a-f0-9]{40}$/', \$expectedCommit)");
$loadVerifier = strpos($cli, "require_once \$projectRoot");
$assertTrue(
    $duplicateGuard !== false
        && $requiredGuard !== false
        && $commitFormat !== false
        && $loadVerifier !== false
        && $duplicateGuard < $loadVerifier
        && $requiredGuard < $loadVerifier
        && $commitFormat < $loadVerifier
        && str_contains($cli, "'--evidence-dir=' => 'directory'")
        && str_contains($cli, "'--expected-commit=' => 'commit'")
        && str_contains($cli, '$value = substr($argument, strlen($matchedPrefix));')
        && !str_contains($cli, 'trim(substr(')
        && !str_contains($cli, 'strtolower(')
        && str_contains($cli, 'RuntimePrimaryPortableCiEvidenceVerifier(')
        && !str_contains($cli, 'file_put_contents('),
    'CLI must reject duplicates/missing/malformed exact arguments before loading offline verifier code'
);
$verifyStep = strpos($workflow, 'name: Verify exact evidence bundle');
$uploadStep = strpos($workflow, 'name: Upload focused-suite evidence');
$assertTrue(
    $verifyStep !== false
        && $uploadStep !== false
        && $verifyStep < $uploadStep
        && str_contains($workflow, '--expected-commit="$GITHUB_SHA"')
        && str_contains($workflow, 'github.run_id')
        && str_contains($workflow, 'github.run_attempt')
        && str_contains($workflow, 'mgw-ci-focused-verification-')
        && str_contains($workflow, '${{ env.MGW_CI_OUTPUT_DIR }}'),
    'Workflow must use attempt-isolated paths and verify the exact commit-bound bundle before upload'
);
$assertTrue(
    !str_contains($workflow, 'secrets.')
        && !str_contains($workflow, 'ubuntu-latest')
        && !str_contains($workflow, 'deploy')
        && !str_contains($workflow, 'cron'),
    'Evidence verification workflow must remain self-hosted and infrastructure-free'
);

fwrite(STDOUT, "RuntimePrimaryPortableCiEvidenceVerifierContractTest passed: {$assertions} assertions.\n");
