<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier
{
    public const REPORT_TYPE = 'mvp-14.8.6n-staging-api-read-only-smoke';
    public const ACTION = 'staging_api_read_only_smoke_passed';
    public const EVIDENCE_VERSION = 'v4-staging-db-primary-api-lifecycle-evidence';
    public const PROJECTION_VERSION = 'v1-normalized-all-modules';

    private const MAX_REPORT_BYTES = 131_072;
    private const MIN_TTL_SECONDS = 60;
    private const MAX_TTL_SECONDS = 600;
    private const MAX_FUTURE_SKEW_SECONDS = 30;

    public function __construct(
        private string $reportFile,
        private string $expectedCommit,
        private string $expectedDatabaseIdentity,
        private string $expectedEvidenceFingerprint,
        private int $maximumAgeSeconds = 3600,
        private int $expectedBootstrapHookCount = 5,
        private int $expectedBootstrapFilterCount = 2
    ) {
        if ($this->reportFile === ''
            || trim($this->reportFile) !== $this->reportFile
            || str_contains($this->reportFile, '\\')
            || !str_starts_with($this->reportFile, '/')
            || str_ends_with($this->reportFile, '/')) {
            throw new InvalidArgumentException(
                'Read-only smoke report path must be an exact absolute Linux file path.'
            );
        }
        if (preg_match('/\A[a-f0-9]{40}\z/', $this->expectedCommit) !== 1) {
            throw new InvalidArgumentException('Read-only smoke expected commit must be a full lowercase SHA-1.');
        }
        foreach ([
            'database identity' => $this->expectedDatabaseIdentity,
            'evidence fingerprint' => $this->expectedEvidenceFingerprint,
        ] as $label => $value) {
            if (!$this->validSha($value)) {
                throw new InvalidArgumentException('Read-only smoke expected ' . $label . ' must be SHA-256.');
            }
        }
        if ($this->maximumAgeSeconds < 60 || $this->maximumAgeSeconds > 86_400) {
            throw new InvalidArgumentException('Read-only smoke maximum age must be between 60 and 86400 seconds.');
        }
        if ($this->expectedBootstrapHookCount < 1 || $this->expectedBootstrapHookCount > 32
            || $this->expectedBootstrapFilterCount < 0 || $this->expectedBootstrapFilterCount > 32) {
            throw new InvalidArgumentException('Read-only smoke expected bootstrap counts are outside safe bounds.');
        }
    }

    public function verify(?int $now = null): array
    {
        $now ??= time();
        if ($now < 1) {
            throw new InvalidArgumentException('Read-only smoke verification time is invalid.');
        }
        $path = $this->canonicalReportPath();
        clearstatcache(true, $path);
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_REPORT_BYTES) {
            throw new RuntimeException('Read-only smoke report size is invalid.');
        }
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Read-only smoke report must have exact mode 0600.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Read-only smoke report could not be read exactly.');
        }
        try {
            $report = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Read-only smoke report JSON is invalid.', 0, $error);
        }
        if (!is_array($report) || array_is_list($report)) {
            throw new RuntimeException('Read-only smoke report must be a JSON object.');
        }
        $this->assertExactKeys($report, [
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
        ]);

        if (($report['ok'] ?? null) !== true
            || ($report['report_type'] ?? null) !== self::REPORT_TYPE
            || ($report['action'] ?? null) !== self::ACTION
            || ($report['projection_contract_version'] ?? null) !== self::PROJECTION_VERSION
            || ($report['evidence_manifest_version'] ?? null) !== self::EVIDENCE_VERSION) {
            throw new RuntimeException('Read-only smoke report identity or version contract is invalid.');
        }

        $commit = $report['repository_commit'] ?? null;
        $databaseIdentity = $report['database_identity_fingerprint'] ?? null;
        $evidenceFingerprint = $report['evidence_fingerprint'] ?? null;
        if (!is_string($commit)
            || preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1) {
            throw new RuntimeException('Read-only smoke report repository commit format is invalid.');
        }
        if (!is_string($databaseIdentity) || !is_string($evidenceFingerprint)) {
            throw new RuntimeException('Read-only smoke report identity fingerprint type is invalid.');
        }
        foreach ([
            'state_sha256',
            'outbox_fingerprint',
            'top_level_keys_fingerprint',
            'evidence_fingerprint',
            'database_identity_fingerprint',
        ] as $field) {
            $value = $report[$field] ?? null;
            if (!is_string($value) || !$this->validSha($value)) {
                throw new RuntimeException('Read-only smoke report SHA-256 field is invalid: ' . $field . '.');
            }
        }
        if (!hash_equals($this->expectedCommit, $commit)) {
            throw new RuntimeException('Read-only smoke report belongs to a different repository commit.');
        }
        if (!hash_equals($this->expectedDatabaseIdentity, $databaseIdentity)) {
            throw new RuntimeException('Read-only smoke report belongs to a different database identity.');
        }
        if (!hash_equals($this->expectedEvidenceFingerprint, $evidenceFingerprint)) {
            throw new RuntimeException('Read-only smoke report belongs to different lifecycle evidence.');
        }

        $revision = $this->positiveInteger($report['state_revision'] ?? null, 'state revision');
        $outboxCount = $this->positiveInteger($report['outbox_event_count'] ?? null, 'outbox event count');
        if ($revision !== $outboxCount) {
            throw new RuntimeException('Read-only smoke report outbox count does not match state revision.');
        }
        $topLevelCount = $this->positiveInteger($report['top_level_count'] ?? null, 'top-level state count');
        if ($topLevelCount > 256) {
            throw new RuntimeException('Read-only smoke report top-level state count is outside safe bounds.');
        }
        $ttl = $this->positiveInteger($report['ttl_seconds'] ?? null, 'TTL');
        if ($ttl < self::MIN_TTL_SECONDS || $ttl > self::MAX_TTL_SECONDS) {
            throw new RuntimeException('Read-only smoke report TTL is outside the bounded session contract.');
        }
        if (($report['bootstrap_legacy_hook_count'] ?? null) !== $this->expectedBootstrapHookCount
            || ($report['bootstrap_legacy_filter_count'] ?? null) !== $this->expectedBootstrapFilterCount) {
            throw new RuntimeException('Read-only smoke report bootstrap hook/filter contour is unexpected.');
        }
        if (($report['worker_tick_count'] ?? null) !== 0) {
            throw new RuntimeException('Read-only smoke report is not zero-worker read-only evidence.');
        }

        foreach ([
            'json_default_verified', 'rollback_data_dir_external',
            'rollback_data_dir_canonical', 'api_bootstrap_hooks_preserved',
            'api_bootstrap_filters_preserved', 'context_state_matched',
            'lifecycle_v4_verified', 'legacy_json_bridges_suppressed',
            'completed_events_lease_free', 'state_unchanged', 'snapshot_unchanged',
            'outbox_unchanged', 'data_filters_unchanged', 'request_finalizer_completed',
            'selector_enabled_in_memory_only', 'request_session_enabled_in_memory_only',
            'activation_enabled_in_memory_only', 'api_only',
        ] as $field) {
            if (($report[$field] ?? null) !== true) {
                throw new RuntimeException('Read-only smoke report required proof is not true: ' . $field . '.');
            }
        }
        foreach ([
            'persistent_config_changed', 'http_route_added', 'webhook_allowed',
            'cron_changed', 'production_changed', 'sensitive_identifiers_exposed',
        ] as $field) {
            if (($report[$field] ?? null) !== false) {
                throw new RuntimeException('Read-only smoke report safety flag must be false: ' . $field . '.');
            }
        }

        $generatedAtValue = $report['generated_at_utc'] ?? null;
        if (!is_string($generatedAtValue)) {
            throw new RuntimeException('Read-only smoke report timestamp type is invalid.');
        }
        $generatedAt = $this->parseGeneratedAt($generatedAtValue);
        $age = $now - $generatedAt;
        if ($age < -self::MAX_FUTURE_SKEW_SECONDS) {
            throw new RuntimeException('Read-only smoke report timestamp is unexpectedly in the future.');
        }
        if ($age > $this->maximumAgeSeconds) {
            throw new RuntimeException('Read-only smoke report is too old for operational acceptance.');
        }

        return [
            'ok' => true,
            'action' => 'staging_api_read_only_smoke_evidence_verified',
            'report_type' => self::REPORT_TYPE,
            'report_sha256' => hash('sha256', $raw),
            'repository_commit' => $commit,
            'database_identity_fingerprint' => $databaseIdentity,
            'evidence_fingerprint' => $evidenceFingerprint,
            'state_revision' => $revision,
            'state_sha256' => $report['state_sha256'],
            'outbox_event_count' => $outboxCount,
            'outbox_fingerprint' => $report['outbox_fingerprint'],
            'projection_contract_version' => self::PROJECTION_VERSION,
            'evidence_manifest_version' => self::EVIDENCE_VERSION,
            'worker_tick_count' => 0,
            'bootstrap_legacy_hook_count' => $this->expectedBootstrapHookCount,
            'bootstrap_legacy_filter_count' => $this->expectedBootstrapFilterCount,
            'report_age_seconds' => max(0, $age),
            'state_unchanged' => true,
            'snapshot_unchanged' => true,
            'outbox_unchanged' => true,
            'persistent_config_changed' => false,
            'http_route_added' => false,
            'webhook_allowed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'verified_at_utc' => gmdate(DATE_ATOM, $now),
        ];
    }

    private function canonicalReportPath(): string
    {
        if (is_link($this->reportFile) || !is_file($this->reportFile)) {
            throw new RuntimeException('Read-only smoke report must be an absolute real file.');
        }
        $real = realpath($this->reportFile);
        if (!is_string($real)) {
            throw new RuntimeException('Read-only smoke report canonical path is unavailable.');
        }
        if (!hash_equals($this->reportFile, $real)) {
            throw new RuntimeException('Read-only smoke report must use its canonical path.');
        }
        if (preg_match('~(?:\A|/)public_html(?:/|\z)~', $real) === 1) {
            throw new RuntimeException('Read-only smoke report must not be accepted from public_html.');
        }
        $directory = dirname($real);
        clearstatcache(true, $directory);
        $directoryMode = fileperms($directory);
        if (!is_int($directoryMode) || ($directoryMode & 0022) !== 0) {
            throw new RuntimeException('Read-only smoke report directory must not be group/world writable.');
        }
        return $real;
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('Read-only smoke report ' . $label . ' must be a positive integer.');
        }
        return $value;
    }

    private function parseGeneratedAt(string $value): int
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $value) !== 1) {
            throw new RuntimeException('Read-only smoke report timestamp must use exact UTC +00:00 format.');
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:sP',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || (is_array($errors)
                && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))
            || $date->format(DATE_ATOM) !== $value) {
            throw new RuntimeException('Read-only smoke report timestamp is invalid.');
        }
        return $date->getTimestamp();
    }

    private function validSha(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function assertExactKeys(array $report, array $expected): void
    {
        $actual = array_keys($report);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('Read-only smoke report fields are not exact.');
        }
    }
}
