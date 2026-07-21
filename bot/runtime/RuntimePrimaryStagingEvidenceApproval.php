<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceApproval
{
    private const MAX_APPROVAL_SECONDS = 7200;

    private function __construct(
        private bool $enabled,
        private string $expectedDatabaseIdentityFingerprint,
        private string $expectedRepositoryCommit,
        private string $expiresAtUtc
    ) {}

    public static function fromConfig(array $config): self
    {
        if (array_key_exists('staging_db_primary_evidence', $config)
            && !is_array($config['staging_db_primary_evidence'])) {
            throw new RuntimeException('staging_db_primary_evidence must be a configuration array.');
        }
        $settings = is_array($config['staging_db_primary_evidence'] ?? null)
            ? $config['staging_db_primary_evidence']
            : [];

        return new self(
            self::strictBool($settings['enabled'] ?? false, 'staging_db_primary_evidence.enabled'),
            strtolower(trim((string)($settings['expected_database_identity_fingerprint'] ?? ''))),
            strtolower(trim((string)($settings['expected_repository_commit'] ?? ''))),
            trim((string)($settings['approval_expires_at_utc'] ?? ''))
        );
    }

    public function assertApproved(
        DatabaseConfig $databaseConfig,
        string $repositoryCommit,
        int $now
    ): void {
        if (!$this->enabled) {
            throw new RuntimeException('Staging DB-primary evidence approval is disabled.');
        }
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging DB-primary evidence approval requires an enabled database.');
        }

        $actualDatabaseFingerprint = strtolower(trim($databaseConfig->identityFingerprint()));
        if (preg_match('/^[a-f0-9]{64}$/', $actualDatabaseFingerprint) !== 1) {
            throw new RuntimeException('Staging database identity fingerprint is unavailable.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $this->expectedDatabaseIdentityFingerprint) !== 1) {
            throw new RuntimeException('Approved staging database identity fingerprint is invalid.');
        }
        if (!hash_equals($this->expectedDatabaseIdentityFingerprint, $actualDatabaseFingerprint)) {
            throw new RuntimeException('Configured database does not match the explicitly approved staging database identity.');
        }

        $repositoryCommit = strtolower(trim($repositoryCommit));
        if (preg_match('/^[a-f0-9]{40}$/', $repositoryCommit) !== 1) {
            throw new RuntimeException('Current staging repository commit is invalid.');
        }
        if (preg_match('/^[a-f0-9]{40}$/', $this->expectedRepositoryCommit) !== 1) {
            throw new RuntimeException('Approved staging repository commit is invalid.');
        }
        if (!hash_equals($this->expectedRepositoryCommit, $repositoryCommit)) {
            throw new RuntimeException('Current checkout does not match the explicitly approved staging repository commit.');
        }

        if ($this->expiresAtUtc === ''
            || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $this->expiresAtUtc) !== 1) {
            throw new RuntimeException('Staging evidence approval expiry must include an explicit UTC offset.');
        }
        $expiresAt = strtotime($this->expiresAtUtc);
        if ($expiresAt === false || $expiresAt <= $now) {
            throw new RuntimeException('Staging DB-primary evidence approval is expired.');
        }
        if ($expiresAt - $now > self::MAX_APPROVAL_SECONDS) {
            throw new RuntimeException('Staging DB-primary evidence approval may be valid for at most two hours.');
        }
    }

    public function safeSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'database_identity_fingerprint_configured' => preg_match(
                '/^[a-f0-9]{64}$/',
                $this->expectedDatabaseIdentityFingerprint
            ) === 1,
            'repository_commit_configured' => preg_match(
                '/^[a-f0-9]{40}$/',
                $this->expectedRepositoryCommit
            ) === 1,
            'approval_expiry_configured' => $this->expiresAtUtc !== '',
            'max_approval_seconds' => self::MAX_APPROVAL_SECONDS,
        ];
    }

    private static function strictBool(mixed $value, string $label): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) {
            if ($value === 0) return false;
            if ($value === 1) return true;
            throw new RuntimeException($label . ' must be a strict boolean value.');
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on', 'enabled' => true,
                '0', 'false', 'no', 'off', 'disabled' => false,
                default => throw new RuntimeException($label . ' must be a strict boolean value.'),
            };
        }
        if ($value === null) return false;
        throw new RuntimeException($label . ' must be a strict boolean value.');
    }
}
