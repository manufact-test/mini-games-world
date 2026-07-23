<?php
declare(strict_types=1);

final class ProductionCutoverReleaseReceiptVerifier
{
    public const CONTRACT_VERSION = 'v1-production-cutover-release-receipt';
    private const MAX_BYTES = 262_144;
    private const MAX_AGE_SECONDS = 900;

    public function verify(
        string $receiptFile,
        array $state,
        array $manifest,
        array $runtimeContract,
        string $databaseIdentityFingerprint,
        int $now
    ): array {
        $receiptFile = $this->privateFile($receiptFile);
        $receipt = $this->readJson($receiptFile);

        $generatedAt = $this->utcTimestamp($receipt['generated_at_utc'] ?? null);
        $expiresAt = $this->utcTimestamp($receipt['expires_at_utc'] ?? null);
        $plan = $this->exactSha($state['plan_fingerprint'] ?? null);
        $source = $this->exactSha($state['source_fingerprint'] ?? null);
        $databaseIdentityFingerprint = $this->exactSha($databaseIdentityFingerprint);

        $checks = [
            'contract_version' => ($receipt['contract_version'] ?? null) === self::CONTRACT_VERSION,
            'ready_exact' => ($receipt['ready'] ?? null) === true,
            'environment_exact' => ($receipt['environment'] ?? null) === 'production',
            'build_exact' => ($receipt['build'] ?? null) === ProductionCutoverRunner::BUILD,
            'package_version_exact' => ($receipt['package_version'] ?? null)
                === ProductionCutoverRunner::PACKAGE_VERSION,
            'release_commit_exact' => ($receipt['release_commit'] ?? null)
                === ($manifest['release_commit'] ?? null),
            'package_fingerprint_exact' => ($receipt['package_fingerprint'] ?? null)
                === ($manifest['package_fingerprint'] ?? null),
            'runtime_contract_exact' => ($receipt['runtime_contract_fingerprint'] ?? null)
                === ($runtimeContract['contract_fingerprint'] ?? null),
            'plan_fingerprint_exact' => $plan !== ''
                && ($receipt['plan_fingerprint'] ?? null) === $plan,
            'source_fingerprint_exact' => $source !== ''
                && ($receipt['source_fingerprint'] ?? null) === $source,
            'database_identity_exact' => $databaseIdentityFingerprint !== ''
                && ($receipt['database_identity_fingerprint'] ?? null)
                    === $databaseIdentityFingerprint,
            'cutover_state_exact' => ($receipt['cutover_state'] ?? null) === 'awaiting_release',
            'health_http_200' => ($receipt['health_http_status'] ?? null) === 200,
            'health_ok_exact' => ($receipt['health_ok'] ?? null) === true,
            'database_connected_exact' => ($receipt['database_connected'] ?? null) === true,
            'schema_current_exact' => ($receipt['schema_current'] ?? null) === true,
            'pending_migrations_zero' => ($receipt['pending_migrations'] ?? null) === 0,
            'all_nine_modules_exact' => ($receipt['enabled_module_count'] ?? null) === 9,
            'read_only_api_smoke_passed' => ($receipt['read_only_api_smoke'] ?? null) === true,
            'all_module_regression_passed' => ($receipt['all_module_regression'] ?? null) === true,
            'json_snapshot_unchanged' => ($receipt['json_snapshot_unchanged'] ?? null) === true,
            'maintenance_still_enabled' => ($receipt['maintenance_enabled'] ?? null) === true,
            'financial_read_only_still_enabled' => ($receipt['financial_read_only'] ?? null) === true,
            'json_write_block_still_active' => ($receipt['json_write_block_active'] ?? null) === true,
            'database_write_executed_false' => ($receipt['database_write_executed'] ?? null) === false,
            'persistent_config_changed_false' => ($receipt['persistent_config_changed'] ?? null) === false,
            'webhook_changed_false' => ($receipt['webhook_changed'] ?? null) === false,
            'cron_changed_false' => ($receipt['cron_changed'] ?? null) === false,
            'production_changed_false' => ($receipt['production_changed'] ?? null) === false,
            'generated_time_valid' => $generatedAt !== null
                && $generatedAt <= $now
                && ($now - $generatedAt) <= self::MAX_AGE_SECONDS,
            'expiry_valid' => $generatedAt !== null
                && $expiresAt !== null
                && $expiresAt > $now
                && ($expiresAt - $generatedAt) >= 60
                && ($expiresAt - $generatedAt) <= self::MAX_AGE_SECONDS,
        ];

        $blockers = [];
        foreach ($checks as $name => $passed) {
            if ($passed !== true) $blockers[] = 'release receipt check failed: ' . $name;
        }
        ksort($checks, SORT_STRING);
        sort($blockers, SORT_STRING);

        $fingerprint = hash('sha256', self::canonicalJson($receipt));
        return [
            'ready' => $blockers === [],
            'contract_version' => self::CONTRACT_VERSION,
            'receipt_fingerprint' => $fingerprint,
            'generated_at_utc' => is_string($receipt['generated_at_utc'] ?? null)
                ? (string)$receipt['generated_at_utc']
                : '',
            'expires_at_utc' => is_string($receipt['expires_at_utc'] ?? null)
                ? (string)$receipt['expires_at_utc']
                : '',
            'checks' => $checks,
            'blockers' => $blockers,
            'database_contacted' => false,
            'database_write_executed' => false,
            'persistent_config_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function privateFile(string $path): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Production release receipt is unavailable.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException('Production release receipt path is not canonical.');
        }
        clearstatcache(true, $canonical);
        $mode = fileperms($canonical);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Production release receipt must have exact mode 0600.');
        }
        return $canonical;
    }

    private function readJson(string $path): array
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Production release receipt size is invalid.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Production release receipt could not be read exactly.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Production release receipt is invalid JSON.', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Production release receipt must be an object.');
        }
        return $decoded;
    }

    private function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private function utcTimestamp(mixed $value): ?int
    {
        if (!is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/', $value) !== 1) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
        return $date->getOffset() === 0 ? $date->getTimestamp() : null;
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
