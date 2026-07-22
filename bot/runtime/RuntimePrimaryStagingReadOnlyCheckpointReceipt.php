<?php
declare(strict_types=1);

final class RuntimePrimaryStagingReadOnlyCheckpointReceipt
{
    public const CONTRACT_VERSION = 'v1-staging-read-only-checkpoint-receipt';
    public const ACTION = 'staging_read_only_checkpoint_receipt_written';
    private const MAX_RECEIPT_BYTES = 65_536;
    private const MAX_FUTURE_SKEW_SECONDS = 30;

    public static function build(array $verifiedReport, string $reportFile, int $now): array
    {
        if ($now < 1) {
            throw new InvalidArgumentException('Read-only checkpoint receipt time is invalid.');
        }
        $required = [
            'ok' => true,
            'action' => 'staging_api_read_only_smoke_evidence_verified',
            'state_unchanged' => true,
            'snapshot_unchanged' => true,
            'outbox_unchanged' => true,
            'persistent_config_changed' => false,
            'http_route_added' => false,
            'webhook_allowed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'worker_tick_count' => 0,
        ];
        foreach ($required as $field => $expected) {
            if (($verifiedReport[$field] ?? null) !== $expected) {
                throw new RuntimeException('Verified read-only report is incomplete for receipt field: ' . $field . '.');
            }
        }

        $commit = self::strictString($verifiedReport['repository_commit'] ?? null, 'repository commit');
        if (preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1) {
            throw new RuntimeException('Verified read-only report commit is invalid.');
        }
        $databaseIdentity = self::strictSha(
            $verifiedReport['database_identity_fingerprint'] ?? null,
            'database identity'
        );
        $evidenceFingerprint = self::strictSha(
            $verifiedReport['evidence_fingerprint'] ?? null,
            'evidence fingerprint'
        );
        $reportSha = self::strictSha(
            $verifiedReport['report_sha256'] ?? null,
            'read-only report fingerprint'
        );
        $stateSha = self::strictSha(
            $verifiedReport['state_sha256'] ?? null,
            'state fingerprint'
        );
        $revision = $verifiedReport['state_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Verified read-only report state revision is invalid.');
        }
        $canonicalReport = self::canonicalPrivateFile($reportFile, 'Read-only smoke report');
        $actualReportSha = hash_file('sha256', $canonicalReport);
        if (!is_string($actualReportSha) || !hash_equals($reportSha, $actualReportSha)) {
            throw new RuntimeException('Read-only smoke report file no longer matches its verified fingerprint.');
        }

        return [
            'action' => self::ACTION,
            'contract_version' => self::CONTRACT_VERSION,
            'cron_changed' => false,
            'database_identity_fingerprint' => $databaseIdentity,
            'evidence_fingerprint' => $evidenceFingerprint,
            'generated_at_utc' => gmdate(DATE_ATOM, $now),
            'http_route_added' => false,
            'mutating_smoke_authorized' => false,
            'ok' => true,
            'persistent_config_changed' => false,
            'production_changed' => false,
            'read_only_report_file' => $canonicalReport,
            'read_only_report_sha256' => $reportSha,
            'repository_commit' => $commit,
            'sensitive_identifiers_exposed' => false,
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'webhook_allowed' => false,
            'worker_tick_count' => 0,
        ];
    }

    public static function verifyFile(
        string $receiptFile,
        string $projectRoot,
        int $maximumAgeSeconds = 1800,
        ?int $now = null
    ): array {
        $now ??= time();
        if ($now < 1 || $maximumAgeSeconds < 60 || $maximumAgeSeconds > 3600) {
            throw new InvalidArgumentException('Read-only checkpoint receipt verification bounds are invalid.');
        }
        $projectRoot = realpath($projectRoot);
        if (!is_string($projectRoot) || !is_dir($projectRoot)) {
            throw new InvalidArgumentException('Read-only checkpoint receipt project root is unavailable.');
        }
        $path = self::canonicalPrivateFile($receiptFile, 'Read-only checkpoint receipt');
        if ($path === $projectRoot || str_starts_with($path, rtrim($projectRoot, '/') . '/')) {
            throw new RuntimeException('Read-only checkpoint receipt must remain outside the deployed project.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_RECEIPT_BYTES) {
            throw new RuntimeException('Read-only checkpoint receipt size is invalid.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Read-only checkpoint receipt could not be read exactly.');
        }
        try {
            $receipt = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Read-only checkpoint receipt JSON is invalid.', 0, $error);
        }
        if (!is_array($receipt) || array_is_list($receipt)) {
            throw new RuntimeException('Read-only checkpoint receipt must be a JSON object.');
        }
        $expectedKeys = [
            'action', 'contract_version', 'cron_changed', 'database_identity_fingerprint',
            'evidence_fingerprint', 'generated_at_utc', 'http_route_added',
            'mutating_smoke_authorized', 'ok', 'persistent_config_changed',
            'production_changed', 'read_only_report_file', 'read_only_report_sha256',
            'repository_commit', 'sensitive_identifiers_exposed', 'state_revision',
            'state_sha256', 'webhook_allowed', 'worker_tick_count',
        ];
        $actualKeys = array_keys($receipt);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('Read-only checkpoint receipt schema is invalid.');
        }
        if (($receipt['ok'] ?? null) !== true
            || ($receipt['action'] ?? null) !== self::ACTION
            || ($receipt['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($receipt['mutating_smoke_authorized'] ?? null) !== false
            || ($receipt['worker_tick_count'] ?? null) !== 0) {
            throw new RuntimeException('Read-only checkpoint receipt identity is invalid.');
        }
        foreach ([
            'persistent_config_changed', 'http_route_added', 'webhook_allowed',
            'cron_changed', 'production_changed', 'sensitive_identifiers_exposed',
        ] as $field) {
            if (($receipt[$field] ?? null) !== false) {
                throw new RuntimeException('Read-only checkpoint receipt safety flag must be false: ' . $field . '.');
            }
        }
        $commit = self::strictString($receipt['repository_commit'] ?? null, 'repository commit');
        if (preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1) {
            throw new RuntimeException('Read-only checkpoint receipt repository commit is invalid.');
        }
        foreach ([
            'database_identity_fingerprint', 'evidence_fingerprint',
            'read_only_report_sha256', 'state_sha256',
        ] as $field) {
            self::strictSha($receipt[$field] ?? null, $field);
        }
        if (!is_int($receipt['state_revision'] ?? null) || $receipt['state_revision'] < 1) {
            throw new RuntimeException('Read-only checkpoint receipt state revision is invalid.');
        }
        $reportFile = self::canonicalPrivateFile(
            self::strictString($receipt['read_only_report_file'] ?? null, 'report file'),
            'Receipt read-only report'
        );
        if ($reportFile === $projectRoot || str_starts_with($reportFile, rtrim($projectRoot, '/') . '/')) {
            throw new RuntimeException('Receipt read-only report must remain outside the deployed project.');
        }
        $actualReportSha = hash_file('sha256', $reportFile);
        if (!is_string($actualReportSha)
            || !hash_equals((string)$receipt['read_only_report_sha256'], $actualReportSha)) {
            throw new RuntimeException('Receipt read-only report fingerprint no longer matches.');
        }
        $generatedAt = self::parseExactUtc(
            self::strictString($receipt['generated_at_utc'] ?? null, 'generated timestamp')
        );
        $age = $now - $generatedAt;
        if ($age < -self::MAX_FUTURE_SKEW_SECONDS || $age > $maximumAgeSeconds) {
            throw new RuntimeException('Read-only checkpoint receipt is outside the accepted age window.');
        }
        $receipt['receipt_file'] = $path;
        $receipt['receipt_sha256'] = hash('sha256', $raw);
        $receipt['receipt_age_seconds'] = max(0, $age);
        return $receipt;
    }

    private static function canonicalPrivateFile(string $path, string $label): string
    {
        if ($path === '' || trim($path) !== $path || str_contains($path, '\\')
            || !str_starts_with($path, '/') || str_ends_with($path, '/')) {
            throw new RuntimeException($label . ' path must be an exact absolute Linux file path.');
        }
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException($label . ' must be a regular non-symlink file.');
        }
        $real = realpath($path);
        if (!is_string($real) || !hash_equals($path, $real)) {
            throw new RuntimeException($label . ' path must be canonical.');
        }
        if (preg_match('~(?:\A|/)public_html(?:/|\z)~', $real) === 1) {
            throw new RuntimeException($label . ' must not be inside public_html.');
        }
        clearstatcache(true, $real);
        $mode = fileperms($real);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException($label . ' must have exact mode 0600.');
        }
        $parent = dirname($real);
        $parentMode = fileperms($parent);
        if (!is_int($parentMode) || ($parentMode & 0022) !== 0) {
            throw new RuntimeException($label . ' directory must not be group/world writable.');
        }
        return $real;
    }

    private static function strictString(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Read-only checkpoint receipt ' . $label . ' must be a string.');
        }
        return $value;
    }

    private static function strictSha(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException('Read-only checkpoint receipt ' . $label . ' must be SHA-256.');
        }
        return $value;
    }

    private static function parseExactUtc(string $value): int
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $value) !== 1) {
            throw new RuntimeException('Read-only checkpoint receipt timestamp must use exact UTC +00:00.');
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
            throw new RuntimeException('Read-only checkpoint receipt timestamp is invalid.');
        }
        return $date->getTimestamp();
    }
}
