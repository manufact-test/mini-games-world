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
        && str_contains($verifier, 'TTL is outside the bounded session contract'),
    'Verifier must bind revision, outbox, worker, bootstrap and session invariants'
);
$assertTrue(
    str_contains($verifier, 'must use its canonical path')
        && str_contains($verifier, 'must not be accepted from public_html')
        && str_contains($verifier, 'must not be world-writable')
        && str_contains($verifier, 'MAX_REPORT_BYTES = 131_072'),
    'Verifier must protect report path, permissions and bounded size'
);
$assertTrue(
    str_contains($verifier, "preg_match('/^[a-f0-9]{40}$/', \$commit)")
        && str_contains($verifier, "preg_match('/^[a-f0-9]{64}$/', trim(\$value))")
        && str_contains($verifier, 'different repository commit')
        && str_contains($verifier, 'different database identity')
        && str_contains($verifier, 'different lifecycle evidence'),
    'Verifier must require exact lowercase commit and expected fingerprints'
);
$assertTrue(
    str_contains($verifier, 'MAX_FUTURE_SKEW_SECONDS = 30')
        && str_contains($verifier, 'maximum age must be between 60 and 86400 seconds')
        && str_contains($verifier, 'too old for operational acceptance')
        && str_contains($verifier, 'exact UTC +00:00 format'),
    'Verifier must enforce bounded report freshness and exact UTC format'
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

$reportArg = strpos($verifierCli, "'--report=' => 'report'");
$commitArg = strpos($verifierCli, "'--expected-commit=' => 'commit'");
$databaseArg = strpos($verifierCli, "'--expected-database-identity=' => 'database'");
$evidenceArg = strpos($verifierCli, "'--expected-evidence-fingerprint=' => 'evidence'");
$loadVerifier = strpos($verifierCli, "require_once \$projectRoot");
$assertTrue(
    $reportArg !== false && $commitArg !== false
        && $databaseArg !== false && $evidenceArg !== false
        && $loadVerifier !== false
        && $reportArg < $loadVerifier
        && $commitArg < $loadVerifier
        && $databaseArg < $loadVerifier
        && $evidenceArg < $loadVerifier,
    'Verifier CLI must validate every acceptance identity before loading verifier code'
);
$assertTrue(
    str_contains($verifierCli, "'--max-age-seconds=' => 'age'")
        && str_contains($verifierCli, "'--expected-bootstrap-hooks=' => 'hooks'")
        && str_contains($verifierCli, "'--expected-bootstrap-filters=' => 'filters'")
        && str_contains($verifierCli, 'RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier('),
    'Verifier CLI must expose bounded freshness and exact bootstrap contour inputs'
);
$assertTrue(
    !str_contains($verifierCli, 'file_put_contents(')
        && !str_contains($verifierCli, 'StorageFactory')
        && !str_contains($verifierCli, 'PdoConnectionFactory')
        && !str_contains($verifierCli, 'WebhookHandler')
        && !str_contains($verifierCli, 'production-cutover.php')
        && !str_contains($verifierCli, 'crontab'),
    'Verifier CLI must not write, connect, route or touch infrastructure'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifierContractTest passed: {$assertions} assertions.\n");
