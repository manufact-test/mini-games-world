<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$verifier = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier.php'
);
$verifierCli = file_get_contents(
    $projectRoot . '/ops/runtime/verify-staging-db-primary-api-read-only-smoke-evidence.php'
);
$smokeCli = file_get_contents(
    $projectRoot . '/ops/runtime/run-staging-db-primary-api-read-only-smoke.php'
);
if (!is_string($verifier) || !is_string($verifierCli) || !is_string($smokeCli)) {
    throw new RuntimeException('Read-only smoke evidence contract sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$fields = [
    'ok', 'report_type', 'action', 'state_revision', 'state_sha256',
    'projection_contract_version', 'outbox_event_count', 'outbox_fingerprint',
    'top_level_count', 'top_level_keys_fingerprint', 'evidence_manifest_version',
    'evidence_fingerprint', 'repository_commit', 'database_identity_fingerprint',
    'ttl_seconds', 'json_default_verified', 'rollback_data_dir_external',
    'rollback_data_dir_canonical', 'bootstrap_legacy_hook_count',
    'bootstrap_legacy_filter_count', 'api_bootstrap_hooks_preserved',
    'api_bootstrap_filters_preserved', 'worker_tick_count', 'context_state_matched',
    'lifecycle_v4_verified', 'legacy_json_bridges_suppressed',
    'completed_events_lease_free', 'state_unchanged', 'snapshot_unchanged',
    'outbox_unchanged', 'data_filters_unchanged', 'request_finalizer_completed',
    'persistent_config_changed', 'selector_enabled_in_memory_only',
    'request_session_enabled_in_memory_only', 'activation_enabled_in_memory_only',
    'http_route_added', 'api_only', 'webhook_allowed', 'cron_changed',
    'production_changed', 'sensitive_identifiers_exposed', 'generated_at_utc',
];
$assertTrue(count($fields) === 43, 'Read-only smoke evidence field count changed unexpectedly');
foreach ($fields as $field) {
    $assertTrue(
        str_contains($smokeCli, "'{$field}'")
            && str_contains($verifier, "'{$field}'"),
        'Smoke output/verifier field is not synchronized: ' . $field
    );
}

$assertTrue(
    str_contains($verifier, "public const REPORT_TYPE = 'mvp-14.8.6n-staging-api-read-only-smoke'")
        && str_contains($verifier, "public const ACTION = 'staging_api_read_only_smoke_passed'")
        && str_contains($verifier, "public const EVIDENCE_VERSION = 'v4-staging-db-primary-api-lifecycle-evidence'")
        && str_contains($verifier, "public const PROJECTION_VERSION = 'v1-normalized-all-modules'"),
    'Verifier must bind exact read-only smoke identity and versions'
);
$assertTrue(
    str_contains($verifier, 'outbox count does not match state revision')
        && str_contains($verifier, 'not zero-worker read-only evidence')
        && str_contains($verifier, 'bootstrap hook/filter contour is unexpected')
        && str_contains($verifier, 'TTL is outside the bounded session contract')
        && str_contains($verifier, 'top-level state count is outside safe bounds'),
    'Verifier must bind revision, outbox, worker, bootstrap, top-level and session invariants'
);
$assertTrue(
    str_contains($verifier, 'path must be an exact absolute Linux file path')
        && str_contains($verifier, 'must use its canonical path')
        && str_contains($verifier, 'must not be accepted from public_html')
        && str_contains($verifier, 'must have exact mode 0600')
        && str_contains($verifier, 'directory must not be group/world writable')
        && str_contains($verifier, 'MAX_REPORT_BYTES = 131_072')
        && !str_contains($verifier, '$this->reportFile = str_replace('),
    'Verifier must protect exact report path, private permissions and bounded size without repair'
);
$assertTrue(
    substr_count($verifier, "preg_match('/\\A[a-f0-9]{40}\\z/'") >= 2
        && str_contains($verifier, "preg_match('/\\A[a-f0-9]{64}\\z/', \$value)")
        && str_contains($verifier, 'different repository commit')
        && str_contains($verifier, 'different database identity')
        && str_contains($verifier, 'different lifecycle evidence')
        && !str_contains($verifier, '$this->expectedCommit = trim(')
        && !str_contains($verifier, '$commit = trim(')
        && !str_contains($verifier, '$value = trim($value)'),
    'Verifier must require byte-exact lowercase identities with absolute regex anchors'
);
$assertTrue(
    str_contains($verifier, 'MAX_FUTURE_SKEW_SECONDS = 30')
        && str_contains($verifier, 'maximum age must be between 60 and 86400 seconds')
        && str_contains($verifier, 'too old for operational acceptance')
        && str_contains($verifier, 'exact UTC +00:00 format')
        && str_contains($verifier, 'timestamp is invalid')
        && str_contains($verifier, "preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T")
        && !str_contains($verifier, '$value = trim($value);'),
    'Verifier must enforce bounded freshness and exact valid UTC format'
);
$assertTrue(
    str_contains($verifier, '!is_int($value) || $value < 1')
        && str_contains($verifier, "(\$report['bootstrap_legacy_hook_count'] ?? null) !== \$this->expectedBootstrapHookCount")
        && str_contains($verifier, "(\$report['worker_tick_count'] ?? null) !== 0"),
    'Verifier must reject stringified numeric evidence fields'
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
    "'--report=' => 'report'",
    "'--expected-commit=' => 'commit'",
    "'--expected-database-identity=' => 'database'",
    "'--expected-evidence-fingerprint=' => 'evidence'",
    "'--max-age-seconds=' => 'age'",
    "'--expected-bootstrap-hooks=' => 'hooks'",
    "'--expected-bootstrap-filters=' => 'filters'",
] as $optionContract) {
    $assertTrue(
        str_contains($verifierCli, $optionContract),
        'Verifier CLI option contract is missing: ' . $optionContract
    );
}
$duplicateGuard = strpos($verifierCli, 'if (isset($seen[$matchedName]))');
$requiredGuard = strpos($verifierCli, "foreach (['report', 'commit', 'database', 'evidence'] as \$required)");
$reportFormat = strpos($verifierCli, 'trim($reportFile) !== $reportFile');
$commitFormat = strpos($verifierCli, "preg_match('/\\A[a-f0-9]{40}\\z/', \$expectedCommit)");
$databaseFormat = strpos($verifierCli, "'--expected-database-identity' => \$expectedDatabaseIdentity");
$numericFormat = strpos($verifierCli, "preg_match('/\\A\\d+\\z/', \$values[\$numeric])");
$ageBound = strpos($verifierCli, '$maximumAgeSeconds < 60 || $maximumAgeSeconds > 86_400');
$hookBound = strpos($verifierCli, '$expectedHookCount < 1 || $expectedHookCount > 32');
$filterBound = strpos($verifierCli, '$expectedFilterCount < 0 || $expectedFilterCount > 32');
$loadVerifier = strpos($verifierCli, "require_once \$projectRoot");
$assertTrue(
    $duplicateGuard !== false
        && $requiredGuard !== false
        && $reportFormat !== false
        && $commitFormat !== false
        && $databaseFormat !== false
        && $numericFormat !== false
        && $ageBound !== false
        && $hookBound !== false
        && $filterBound !== false
        && $loadVerifier !== false
        && $duplicateGuard < $loadVerifier
        && $requiredGuard < $loadVerifier
        && $reportFormat < $loadVerifier
        && $commitFormat < $loadVerifier
        && $databaseFormat < $loadVerifier
        && $numericFormat < $loadVerifier
        && $ageBound < $loadVerifier
        && $hookBound < $loadVerifier
        && $filterBound < $loadVerifier,
    'Verifier CLI must reject duplicate, missing, malformed and out-of-range options before loading verifier code'
);
$assertTrue(
    str_contains($verifierCli, 'Verifier option may be specified only once')
        && str_contains($verifierCli, '$values[$matchedName] = substr($argument, strlen($matchedPrefix));')
        && str_contains($verifierCli, "str_contains(\$reportFile, '\\\\')")
        && str_contains($verifierCli, 'trim($reportFile) !== $reportFile')
        && !str_contains($verifierCli, '$value = str_replace(')
        && !str_contains($verifierCli, '$value = trim(substr(')
        && !str_contains($verifierCli, 'strtolower($value)')
        && !str_contains($verifierCli, 'strtolower(trim(substr('),
    'Verifier CLI must preserve exact argument bytes and reject path repair or duplicate options'
);
$assertTrue(
    str_contains($verifierCli, 'RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier(')
        && str_contains($verifierCli, '~/(?:home|var|tmp|srv|opt)/')
        && !str_contains($verifierCli, 'file_put_contents(')
        && !str_contains($verifierCli, 'StorageFactory')
        && !str_contains($verifierCli, 'PdoConnectionFactory')
        && !str_contains($verifierCli, 'WebhookHandler')
        && !str_contains($verifierCli, 'production-cutover.php')
        && !str_contains($verifierCli, 'crontab'),
    'Verifier CLI must sanitize paths and not write, connect, route or touch infrastructure'
);
$assertTrue(
    str_contains($smokeCli, 'Read-only API smoke report safety flag is invalid: ')
        && str_contains($smokeCli, 'Read-only API smoke overlay safety flag is invalid: ')
        && str_contains($smokeCli, "'production_changed' => \$report['production_changed']")
        && str_contains($smokeCli, "'persistent_config_changed' => \$overlayReport['persistent_config_changed']"),
    'Smoke producer must validate and propagate safety evidence before verifier acceptance'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifierContractTest passed: {$assertions} assertions.\n");
