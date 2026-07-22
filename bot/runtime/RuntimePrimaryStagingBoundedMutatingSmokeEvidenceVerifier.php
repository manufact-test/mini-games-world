<?php
declare(strict_types=1);

final class RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier
{
    public const REPORT_TYPE = 'mvp-14.9-bounded-rollback-staging-mutating-smoke';
    public const ACTION = 'staging_mutating_smoke_rolled_back_and_verified';
    private const MAX_REPORT_BYTES = 131_072;
    private const MAX_FUTURE_SKEW_SECONDS = 30;
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private string $reportFile,
        private string $expectedCommit,
        private string $expectedDatabaseIdentity,
        private string $expectedReadOnlyReportSha256,
        private string $expectedReceiptSha256,
        private string $expectedApprovalId,
        private int $maximumAgeSeconds = 1800
    ) {
        if ($this->reportFile === '' || trim($this->reportFile) !== $this->reportFile
            || str_contains($this->reportFile, '\\') || !str_starts_with($this->reportFile, '/')
            || str_ends_with($this->reportFile, '/')) {
            throw new InvalidArgumentException('Bounded mutating smoke report path must be exact absolute Linux.');
        }
        if (preg_match('/\A[a-f0-9]{40}\z/', $this->expectedCommit) !== 1) {
            throw new InvalidArgumentException('Bounded mutating smoke expected commit is invalid.');
        }
        foreach ([
            'database identity' => $this->expectedDatabaseIdentity,
            'read-only report' => $this->expectedReadOnlyReportSha256,
            'read-only receipt' => $this->expectedReceiptSha256,
            'approval ID' => $this->expectedApprovalId,
        ] as $label => $value) {
            if (!$this->validSha($value)) {
                throw new InvalidArgumentException('Bounded mutating smoke expected ' . $label . ' must be SHA-256.');
            }
        }
        if ($this->maximumAgeSeconds < 60 || $this->maximumAgeSeconds > 3600) {
            throw new InvalidArgumentException('Bounded mutating smoke report maximum age is invalid.');
        }
    }

    public function verify(?int $now = null): array
    {
        $now ??= time();
        if ($now < 1) throw new InvalidArgumentException('Bounded mutating smoke verification time is invalid.');
        $path = $this->canonicalReportPath();
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_REPORT_BYTES) {
            throw new RuntimeException('Bounded mutating smoke report size is invalid.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Bounded mutating smoke report could not be read exactly.');
        }
        try {
            $report = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Bounded mutating smoke report JSON is invalid.', 0, $error);
        }
        if (!is_array($report) || array_is_list($report)) {
            throw new RuntimeException('Bounded mutating smoke report must be a JSON object.');
        }
        $this->assertExactKeys($report, [
            'ok', 'report_type', 'action', 'approval_id', 'baseline_state_revision',
            'baseline_state_sha256', 'baseline_outbox_fingerprint',
            'baseline_all_module_fingerprint', 'mutation_state_revision',
            'mutation_state_sha256', 'worker_tick_count', 'worker_action',
            'worker_claimed', 'projected_modules', 'all_module_fingerprint_during_mutation',
            'projection_event_status', 'projection_attempt_count',
            'temporary_state_write_count', 'temporary_projection_completed',
            'temporary_all_module_audit_passed', 'rollback_signal_caught',
            'state_revision_restored', 'state_sha256_restored', 'snapshot_restored',
            'outbox_restored', 'all_module_projection_restored',
            'committed_state_write_count', 'committed_outbox_event_count',
            'persistent_config_changed', 'http_route_added', 'webhook_allowed',
            'cron_changed', 'production_changed', 'sensitive_identifiers_exposed',
            'generated_at_utc', 'approval_contract_version', 'approval_consumed',
            'repository_commit', 'database_identity_fingerprint',
            'read_only_receipt_sha256', 'read_only_report_sha256',
            'challenge_sha256', 'rollback_guard_verified',
        ]);

        if (($report['ok'] ?? null) !== true
            || ($report['report_type'] ?? null) !== self::REPORT_TYPE
            || ($report['action'] ?? null) !== self::ACTION
            || ($report['approval_contract_version'] ?? null)
                !== RuntimePrimaryStagingMutatingSmokeApproval::CONTRACT_VERSION) {
            throw new RuntimeException('Bounded mutating smoke report identity is invalid.');
        }
        $commit = $report['repository_commit'] ?? null;
        if (!is_string($commit) || preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1
            || !hash_equals($this->expectedCommit, $commit)) {
            throw new RuntimeException('Bounded mutating smoke report commit does not match.');
        }
        foreach ([
            'approval_id', 'baseline_state_sha256', 'baseline_outbox_fingerprint',
            'baseline_all_module_fingerprint', 'mutation_state_sha256',
            'all_module_fingerprint_during_mutation', 'database_identity_fingerprint',
            'read_only_receipt_sha256', 'read_only_report_sha256', 'challenge_sha256',
        ] as $field) {
            $value = $report[$field] ?? null;
            if (!is_string($value) || !$this->validSha($value)) {
                throw new RuntimeException('Bounded mutating smoke report SHA field is invalid: ' . $field . '.');
            }
        }
        foreach ([
            'database_identity_fingerprint' => $this->expectedDatabaseIdentity,
            'read_only_report_sha256' => $this->expectedReadOnlyReportSha256,
            'read_only_receipt_sha256' => $this->expectedReceiptSha256,
            'approval_id' => $this->expectedApprovalId,
        ] as $field => $expected) {
            if (!hash_equals($expected, (string)$report[$field])) {
                throw new RuntimeException('Bounded mutating smoke report identity mismatch: ' . $field . '.');
            }
        }

        $baselineRevision = $this->positiveInt($report['baseline_state_revision'] ?? null, 'baseline revision');
        $mutationRevision = $this->positiveInt($report['mutation_state_revision'] ?? null, 'mutation revision');
        if ($mutationRevision !== $baselineRevision + 1) {
            throw new RuntimeException('Bounded mutating smoke report must contain exactly one temporary revision.');
        }
        if (($report['worker_tick_count'] ?? null) !== 1
            || ($report['worker_action'] ?? null) !== 'projection_completed'
            || ($report['worker_claimed'] ?? null) !== true
            || ($report['projection_event_status'] ?? null) !== 'completed'
            || $this->positiveInt($report['projection_attempt_count'] ?? null, 'projection attempt count') < 1
            || ($report['temporary_state_write_count'] ?? null) !== 1) {
            throw new RuntimeException('Bounded mutating smoke report temporary execution proof is invalid.');
        }
        $modules = $report['projected_modules'] ?? null;
        if (!is_array($modules) || !array_is_list($modules)) {
            throw new RuntimeException('Bounded mutating smoke projected modules must be a list.');
        }
        $modules = array_values(array_unique(array_map('strval', $modules)));
        sort($modules, SORT_STRING);
        $required = self::MODULES;
        sort($required, SORT_STRING);
        if ($modules !== $required) {
            throw new RuntimeException('Bounded mutating smoke report is missing projected modules.');
        }

        foreach ([
            'temporary_projection_completed', 'temporary_all_module_audit_passed',
            'rollback_signal_caught', 'state_revision_restored', 'state_sha256_restored',
            'snapshot_restored', 'outbox_restored', 'all_module_projection_restored',
            'approval_consumed', 'rollback_guard_verified',
        ] as $field) {
            if (($report[$field] ?? null) !== true) {
                throw new RuntimeException('Bounded mutating smoke required proof is not true: ' . $field . '.');
            }
        }
        if (($report['committed_state_write_count'] ?? null) !== 0
            || ($report['committed_outbox_event_count'] ?? null) !== 0) {
            throw new RuntimeException('Bounded mutating smoke report contains committed temporary writes.');
        }
        foreach ([
            'persistent_config_changed', 'http_route_added', 'webhook_allowed',
            'cron_changed', 'production_changed', 'sensitive_identifiers_exposed',
        ] as $field) {
            if (($report[$field] ?? null) !== false) {
                throw new RuntimeException('Bounded mutating smoke safety flag must be false: ' . $field . '.');
            }
        }

        $generated = $report['generated_at_utc'] ?? null;
        if (!is_string($generated)) {
            throw new RuntimeException('Bounded mutating smoke timestamp type is invalid.');
        }
        $generatedAt = $this->parseExactUtc($generated);
        $age = $now - $generatedAt;
        if ($age < -self::MAX_FUTURE_SKEW_SECONDS || $age > $this->maximumAgeSeconds) {
            throw new RuntimeException('Bounded mutating smoke report is outside the accepted age window.');
        }

        return [
            'ok' => true,
            'action' => 'staging_bounded_mutating_smoke_evidence_verified',
            'report_type' => self::REPORT_TYPE,
            'report_field_count' => count($report),
            'report_sha256' => hash('sha256', $raw),
            'repository_commit' => $commit,
            'database_identity_fingerprint' => $report['database_identity_fingerprint'],
            'read_only_receipt_sha256' => $report['read_only_receipt_sha256'],
            'read_only_report_sha256' => $report['read_only_report_sha256'],
            'approval_id' => $report['approval_id'],
            'baseline_state_revision' => $baselineRevision,
            'mutation_state_revision' => $mutationRevision,
            'worker_tick_count' => 1,
            'temporary_state_write_count' => 1,
            'committed_state_write_count' => 0,
            'committed_outbox_event_count' => 0,
            'rollback_verified' => true,
            'persistent_config_changed' => false,
            'webhook_allowed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'report_age_seconds' => max(0, $age),
            'verified_at_utc' => gmdate(DATE_ATOM, $now),
        ];
    }

    private function canonicalReportPath(): string
    {
        if (is_link($this->reportFile) || !is_file($this->reportFile)) {
            throw new RuntimeException('Bounded mutating smoke report must be a regular non-symlink file.');
        }
        $real = realpath($this->reportFile);
        if (!is_string($real) || !hash_equals($this->reportFile, $real)) {
            throw new RuntimeException('Bounded mutating smoke report path must be canonical.');
        }
        if (preg_match('~(?:\A|/)public_html(?:/|\z)~', $real) === 1) {
            throw new RuntimeException('Bounded mutating smoke report must remain outside public_html.');
        }
        $mode = fileperms($real);
        $parentMode = fileperms(dirname($real));
        if (!is_int($mode) || ($mode & 0777) !== 0600
            || !is_int($parentMode) || ($parentMode & 0022) !== 0) {
            throw new RuntimeException('Bounded mutating smoke report permissions are unsafe.');
        }
        return $real;
    }

    private function assertExactKeys(array $report, array $expected): void
    {
        $actual = array_keys($report);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('Bounded mutating smoke report must contain exactly 43 fields.');
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('Bounded mutating smoke ' . $label . ' must be a positive integer.');
        }
        return $value;
    }

    private function validSha(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function parseExactUtc(string $value): int
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $value) !== 1) {
            throw new RuntimeException('Bounded mutating smoke timestamp must use exact UTC +00:00.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || (is_array($errors)
                && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))
            || $date->format(DATE_ATOM) !== $value) {
            throw new RuntimeException('Bounded mutating smoke timestamp is invalid.');
        }
        return $date->getTimestamp();
    }
}
