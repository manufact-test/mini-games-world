<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiMutatingSmokeEvidenceVerifier
{
    public const REPORT_TYPE = 'mvp-14.9b-staging-api-mutating-smoke';
    public const ACTION = 'staging_api_mutation_committed_projected_cleaned_and_verified';
    private const MAX_REPORT_BYTES = 196_608;
    private const MAX_FUTURE_SKEW_SECONDS = 30;

    public function __construct(
        private string $reportFile,
        private string $expectedCommit,
        private string $expectedDatabaseIdentity,
        private string $expectedReceiptSha256,
        private string $expectedRollbackReportSha256,
        private string $expectedLifecycleEvidenceFingerprint,
        private int $maximumAgeSeconds = 1800
    ) {
        if ($this->reportFile === '' || trim($this->reportFile) !== $this->reportFile
            || str_contains($this->reportFile, '\\') || !str_starts_with($this->reportFile, '/')
            || str_ends_with($this->reportFile, '/')) {
            throw new InvalidArgumentException('Staging API mutating smoke report path must be exact absolute Linux.');
        }
        if (preg_match('/\A[a-f0-9]{40}\z/', $this->expectedCommit) !== 1) {
            throw new InvalidArgumentException('Staging API mutating smoke expected commit is invalid.');
        }
        foreach ([
            'database identity' => $this->expectedDatabaseIdentity,
            'read-only receipt' => $this->expectedReceiptSha256,
            'rollback report' => $this->expectedRollbackReportSha256,
            'lifecycle evidence' => $this->expectedLifecycleEvidenceFingerprint,
        ] as $label => $value) {
            if (!$this->validSha($value)) {
                throw new InvalidArgumentException('Staging API mutating smoke expected ' . $label . ' must be SHA-256.');
            }
        }
        if ($this->maximumAgeSeconds < 60 || $this->maximumAgeSeconds > 3600) {
            throw new InvalidArgumentException('Staging API mutating smoke maximum age is invalid.');
        }
    }

    public function verify(?int $now = null): array
    {
        $now ??= time();
        if ($now < 1) throw new InvalidArgumentException('Staging API mutating smoke verification time is invalid.');
        $path = $this->canonicalReportPath();
        clearstatcache(true, $path);
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_REPORT_BYTES) {
            throw new RuntimeException('Staging API mutating smoke report size is invalid.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Staging API mutating smoke report could not be read exactly.');
        }
        try {
            $report = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Staging API mutating smoke report JSON is invalid.', 0, $error);
        }
        if (!is_array($report) || array_is_list($report)) {
            throw new RuntimeException('Staging API mutating smoke report must be a JSON object.');
        }
        $this->assertExactKeys($report, [
            'ok', 'report_type', 'action', 'repository_commit',
            'database_identity_fingerprint', 'read_only_receipt_sha256',
            'rollback_smoke_report_sha256', 'lifecycle_evidence_fingerprint',
            'baseline_state_revision', 'baseline_state_sha256', 'baseline_snapshot_sha256',
            'baseline_outbox_event_count', 'baseline_outbox_fingerprint',
            'baseline_all_module_fingerprint', 'api_action', 'api_child_exit_code',
            'api_response_ok', 'api_user_id_sha256', 'api_state_revision',
            'api_state_sha256', 'api_outbox_event_count', 'api_projection_completed',
            'api_all_module_audit_passed', 'cleanup_state_revision',
            'cleanup_state_sha256', 'cleanup_worker_tick_count',
            'cleanup_projection_completed', 'mapping_session_rows_deleted',
            'mapping_device_rows_deleted', 'mapping_ownership_rows_deleted',
            'mapping_identity_rows_deleted', 'orphan_user_rows_deleted',
            'final_state_revision', 'final_state_sha256', 'final_snapshot_sha256',
            'final_outbox_event_count', 'final_outbox_fingerprint',
            'final_all_module_fingerprint', 'state_restored_to_baseline',
            'snapshot_restored_to_baseline', 'all_module_projection_restored',
            'synthetic_state_user_absent', 'synthetic_identity_cleanup_verified',
            'json_rollback_source_unchanged', 'committed_api_state_write_count',
            'committed_cleanup_state_write_count', 'committed_outbox_event_count',
            'input_enabled_in_memory_only', 'selector_enabled_in_memory_only',
            'request_session_enabled_in_memory_only', 'activation_enabled_in_memory_only',
            'persistent_config_changed', 'http_route_added', 'webhook_allowed',
            'cron_changed', 'production_changed', 'sensitive_identifiers_exposed',
            'recovery_attempted', 'recovery_verified', 'generated_at_utc',
        ]);

        if (($report['ok'] ?? null) !== true
            || ($report['report_type'] ?? null) !== self::REPORT_TYPE
            || ($report['action'] ?? null) !== self::ACTION
            || ($report['api_action'] ?? null) !== 'bootstrap'
            || ($report['api_child_exit_code'] ?? null) !== 0
            || ($report['api_response_ok'] ?? null) !== true) {
            throw new RuntimeException('Staging API mutating smoke report identity is invalid.');
        }

        $commit = $this->strictString($report['repository_commit'] ?? null, 'repository commit');
        if (preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1
            || !hash_equals($this->expectedCommit, $commit)) {
            throw new RuntimeException('Staging API mutating smoke report commit does not match.');
        }
        foreach ([
            'database_identity_fingerprint', 'read_only_receipt_sha256',
            'rollback_smoke_report_sha256', 'lifecycle_evidence_fingerprint',
            'baseline_state_sha256', 'baseline_snapshot_sha256',
            'baseline_outbox_fingerprint', 'baseline_all_module_fingerprint',
            'api_user_id_sha256', 'api_state_sha256', 'cleanup_state_sha256',
            'final_state_sha256', 'final_snapshot_sha256', 'final_outbox_fingerprint',
            'final_all_module_fingerprint',
        ] as $field) {
            $value = $report[$field] ?? null;
            if (!is_string($value) || !$this->validSha($value)) {
                throw new RuntimeException('Staging API mutating smoke report SHA field is invalid: ' . $field . '.');
            }
        }
        foreach ([
            'database_identity_fingerprint' => $this->expectedDatabaseIdentity,
            'read_only_receipt_sha256' => $this->expectedReceiptSha256,
            'rollback_smoke_report_sha256' => $this->expectedRollbackReportSha256,
            'lifecycle_evidence_fingerprint' => $this->expectedLifecycleEvidenceFingerprint,
        ] as $field => $expected) {
            if (!hash_equals($expected, (string)$report[$field])) {
                throw new RuntimeException('Staging API mutating smoke report identity mismatch: ' . $field . '.');
            }
        }

        $baselineRevision = $this->positiveInt($report['baseline_state_revision'] ?? null, 'baseline revision');
        $apiRevision = $this->positiveInt($report['api_state_revision'] ?? null, 'API revision');
        $cleanupRevision = $this->positiveInt($report['cleanup_state_revision'] ?? null, 'cleanup revision');
        $finalRevision = $this->positiveInt($report['final_state_revision'] ?? null, 'final revision');
        $baselineOutbox = $this->positiveInt($report['baseline_outbox_event_count'] ?? null, 'baseline outbox count');
        $apiOutbox = $this->positiveInt($report['api_outbox_event_count'] ?? null, 'API outbox count');
        $finalOutbox = $this->positiveInt($report['final_outbox_event_count'] ?? null, 'final outbox count');
        if ($apiRevision !== $baselineRevision + 1
            || $cleanupRevision !== $baselineRevision + 2
            || $finalRevision !== $cleanupRevision
            || $apiOutbox !== $baselineOutbox + 1
            || $finalOutbox !== $baselineOutbox + 2
            || $finalOutbox !== $finalRevision) {
            throw new RuntimeException('Staging API mutating smoke revision or outbox chain is invalid.');
        }
        if (hash_equals((string)$report['baseline_state_sha256'], (string)$report['api_state_sha256'])) {
            throw new RuntimeException('Staging API mutating smoke API revision did not change state.');
        }
        foreach ([
            'cleanup_state_sha256', 'final_state_sha256', 'final_snapshot_sha256',
        ] as $field) {
            if (!hash_equals((string)$report['baseline_state_sha256'], (string)$report[$field])) {
                throw new RuntimeException('Staging API mutating smoke did not restore baseline state: ' . $field . '.');
            }
        }
        if (!hash_equals(
            (string)$report['baseline_snapshot_sha256'],
            (string)$report['final_snapshot_sha256']
        ) || !hash_equals(
            (string)$report['baseline_all_module_fingerprint'],
            (string)$report['final_all_module_fingerprint']
        )) {
            throw new RuntimeException('Staging API mutating smoke did not restore snapshot or all-module parity.');
        }

        if (($report['cleanup_worker_tick_count'] ?? null) !== 1
            || ($report['mapping_session_rows_deleted'] ?? null) !== 1
            || ($report['mapping_device_rows_deleted'] ?? null) !== 1
            || ($report['mapping_ownership_rows_deleted'] ?? null) !== 1
            || !in_array($report['mapping_identity_rows_deleted'] ?? null, [1, 2], true)
            || ($report['orphan_user_rows_deleted'] ?? null) !== 1
            || ($report['committed_api_state_write_count'] ?? null) !== 1
            || ($report['committed_cleanup_state_write_count'] ?? null) !== 1
            || ($report['committed_outbox_event_count'] ?? null) !== 2) {
            throw new RuntimeException('Staging API mutating smoke bounded write or cleanup counts are invalid.');
        }

        foreach ([
            'api_projection_completed', 'api_all_module_audit_passed',
            'cleanup_projection_completed', 'state_restored_to_baseline',
            'snapshot_restored_to_baseline', 'all_module_projection_restored',
            'synthetic_state_user_absent', 'synthetic_identity_cleanup_verified',
            'json_rollback_source_unchanged', 'input_enabled_in_memory_only',
            'selector_enabled_in_memory_only', 'request_session_enabled_in_memory_only',
            'activation_enabled_in_memory_only',
        ] as $field) {
            if (($report[$field] ?? null) !== true) {
                throw new RuntimeException('Staging API mutating smoke required proof is not true: ' . $field . '.');
            }
        }
        foreach ([
            'persistent_config_changed', 'http_route_added', 'webhook_allowed',
            'cron_changed', 'production_changed', 'sensitive_identifiers_exposed',
            'recovery_attempted', 'recovery_verified',
        ] as $field) {
            if (($report[$field] ?? null) !== false) {
                throw new RuntimeException('Staging API mutating smoke safety flag must be false: ' . $field . '.');
            }
        }

        $generated = $this->strictString($report['generated_at_utc'] ?? null, 'generated timestamp');
        $generatedAt = $this->parseExactUtc($generated)->getTimestamp();
        $age = $now - $generatedAt;
        if ($age < -self::MAX_FUTURE_SKEW_SECONDS || $age > $this->maximumAgeSeconds) {
            throw new RuntimeException('Staging API mutating smoke report is outside the accepted age window.');
        }

        return [
            'ok' => true,
            'action' => 'staging_api_mutating_smoke_evidence_verified',
            'report_type' => self::REPORT_TYPE,
            'report_field_count' => count($report),
            'report_sha256' => hash('sha256', $raw),
            'repository_commit' => $commit,
            'database_identity_fingerprint' => $report['database_identity_fingerprint'],
            'baseline_state_revision' => $baselineRevision,
            'api_state_revision' => $apiRevision,
            'cleanup_state_revision' => $cleanupRevision,
            'final_state_revision' => $finalRevision,
            'committed_api_state_write_count' => 1,
            'committed_cleanup_state_write_count' => 1,
            'committed_outbox_event_count' => 2,
            'state_restored_to_baseline' => true,
            'all_module_projection_restored' => true,
            'synthetic_identity_cleanup_verified' => true,
            'json_rollback_source_unchanged' => true,
            'persistent_config_changed' => false,
            'webhook_allowed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'report_age_seconds' => max(0, $age),
            'verified_at_utc' => gmdate(DATE_ATOM, $now),
        ];
    }

    private function canonicalReportPath(): string
    {
        if (is_link($this->reportFile) || !is_file($this->reportFile)) {
            throw new RuntimeException('Staging API mutating smoke report must be a regular non-symlink file.');
        }
        $real = realpath($this->reportFile);
        if (!is_string($real) || !hash_equals($this->reportFile, $real)
            || preg_match('~(?:\A|/)public_html(?:/|\z)~', $real) === 1) {
            throw new RuntimeException('Staging API mutating smoke report path is unsafe.');
        }
        $parent = dirname($real);
        clearstatcache(true, $real);
        clearstatcache(true, $parent);
        $mode = fileperms($real);
        $parentMode = fileperms($parent);
        if (!is_int($mode) || ($mode & 0777) !== 0600
            || !is_int($parentMode) || ($parentMode & 0022) !== 0) {
            throw new RuntimeException('Staging API mutating smoke report permissions are unsafe.');
        }
        return $real;
    }

    private function assertExactKeys(array $report, array $expected): void
    {
        $actual = array_keys($report);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('Staging API mutating smoke report must contain exactly 60 fields.');
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('Staging API mutating smoke ' . $label . ' must be a positive integer.');
        }
        return $value;
    }

    private function strictString(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Staging API mutating smoke ' . $label . ' must be a string.');
        }
        return $value;
    }

    private function validSha(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function parseExactUtc(string $value): DateTimeImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $value) !== 1) {
            throw new RuntimeException('Staging API mutating smoke timestamp must use exact UTC +00:00.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || (is_array($errors)
                && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))
            || $date->format(DATE_ATOM) !== $value) {
            throw new RuntimeException('Staging API mutating smoke timestamp is invalid.');
        }
        return $date;
    }
}
