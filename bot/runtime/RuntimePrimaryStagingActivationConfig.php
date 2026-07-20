<?php
declare(strict_types=1);

final class RuntimePrimaryStagingActivationConfig
{
    private const MAX_APPROVAL_SECONDS = 1800;

    private function __construct(
        private bool $enabled,
        private string $expectedDatabaseIdentityFingerprint,
        private string $expectedRepositoryCommit,
        private string $evidenceFile,
        private string $expectedEvidenceFingerprint,
        private string $expiresAtUtc
    ) {}

    public static function fromApplicationConfig(array $config): self
    {
        if (array_key_exists('staging_db_primary_activation', $config)
            && !is_array($config['staging_db_primary_activation'])) {
            throw new RuntimeException('staging_db_primary_activation must be a configuration array.');
        }
        $settings = is_array($config['staging_db_primary_activation'] ?? null)
            ? $config['staging_db_primary_activation']
            : [];

        return new self(
            self::strictBool($settings['enabled'] ?? false, 'staging_db_primary_activation.enabled'),
            strtolower(trim((string)($settings['expected_database_identity_fingerprint'] ?? ''))),
            strtolower(trim((string)($settings['expected_repository_commit'] ?? ''))),
            str_replace('\\', '/', trim((string)($settings['evidence_file'] ?? ''))),
            strtolower(trim((string)($settings['expected_evidence_fingerprint'] ?? ''))),
            trim((string)($settings['approval_expires_at_utc'] ?? ''))
        );
    }

    public function assertApproved(
        DatabaseConfig $databaseConfig,
        string $repositoryCommit,
        string $privateDir,
        int $now
    ): void {
        if (!$this->enabled) {
            throw new RuntimeException('Staging DB-primary activation approval is disabled.');
        }
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging DB-primary activation requires an enabled database.');
        }

        $actualDatabaseFingerprint = strtolower(trim($databaseConfig->identityFingerprint()));
        $this->assertSha($actualDatabaseFingerprint, 'Current staging database identity fingerprint');
        $this->assertSha($this->expectedDatabaseIdentityFingerprint, 'Approved staging database identity fingerprint');
        if (!hash_equals($this->expectedDatabaseIdentityFingerprint, $actualDatabaseFingerprint)) {
            throw new RuntimeException('Configured database does not match the staging activation approval.');
        }

        $repositoryCommit = strtolower(trim($repositoryCommit));
        $this->assertCommit($repositoryCommit, 'Current staging repository commit');
        $this->assertCommit($this->expectedRepositoryCommit, 'Approved staging repository commit');
        if (!hash_equals($this->expectedRepositoryCommit, $repositoryCommit)) {
            throw new RuntimeException('Current checkout does not match the staging activation approval.');
        }

        $privateDir = rtrim(str_replace('\\', '/', trim($privateDir)), '/');
        if ($privateDir === '' || !str_starts_with($privateDir, '/')) {
            throw new RuntimeException('Staging activation private directory is invalid.');
        }
        if ($this->evidenceFile === '' || !str_starts_with($this->evidenceFile, '/')) {
            throw new RuntimeException('Staging activation evidence file path must be absolute.');
        }
        $configuredParent = rtrim(str_replace('\\', '/', dirname($this->evidenceFile)), '/');
        if (!hash_equals($privateDir, $configuredParent)) {
            throw new RuntimeException('Staging activation evidence file must be located in the verified private directory.');
        }
        $this->assertSha($this->expectedEvidenceFingerprint, 'Approved staging evidence fingerprint');

        if ($this->expiresAtUtc === ''
            || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $this->expiresAtUtc) !== 1) {
            throw new RuntimeException('Staging activation approval expiry must include an explicit UTC offset.');
        }
        $expiresAt = strtotime($this->expiresAtUtc);
        if ($expiresAt === false || $expiresAt <= $now) {
            throw new RuntimeException('Staging DB-primary activation approval is expired.');
        }
        if ($expiresAt - $now > self::MAX_APPROVAL_SECONDS) {
            throw new RuntimeException('Staging DB-primary activation approval may be valid for at most 30 minutes.');
        }
    }

    public function evidenceFile(): string
    {
        return $this->evidenceFile;
    }

    public function expectedEvidenceFingerprint(): string
    {
        return $this->expectedEvidenceFingerprint;
    }

    public function safeSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'database_identity_fingerprint_configured' => $this->validSha($this->expectedDatabaseIdentityFingerprint),
            'repository_commit_configured' => preg_match('/^[a-f0-9]{40}$/', $this->expectedRepositoryCommit) === 1,
            'evidence_file_configured' => $this->evidenceFile !== '',
            'evidence_fingerprint_configured' => $this->validSha($this->expectedEvidenceFingerprint),
            'approval_expiry_configured' => $this->expiresAtUtc !== '',
            'max_approval_seconds' => self::MAX_APPROVAL_SECONDS,
        ];
    }

    private function assertSha(string $value, string $label): void
    {
        if (!$this->validSha($value)) {
            throw new RuntimeException($label . ' is invalid.');
        }
    }

    private function validSha(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function assertCommit(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new RuntimeException($label . ' is invalid.');
        }
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
