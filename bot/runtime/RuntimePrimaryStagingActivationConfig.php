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
        $enabled = array_key_exists('enabled', $settings)
            ? self::strictBool($settings['enabled'], 'staging_db_primary_activation.enabled')
            : false;

        return new self(
            $enabled,
            self::strictString(
                $settings['expected_database_identity_fingerprint'] ?? '',
                'staging_db_primary_activation.expected_database_identity_fingerprint'
            ),
            self::strictString(
                $settings['expected_repository_commit'] ?? '',
                'staging_db_primary_activation.expected_repository_commit'
            ),
            self::strictString(
                $settings['evidence_file'] ?? '',
                'staging_db_primary_activation.evidence_file'
            ),
            self::strictString(
                $settings['expected_evidence_fingerprint'] ?? '',
                'staging_db_primary_activation.expected_evidence_fingerprint'
            ),
            self::strictString(
                $settings['approval_expires_at_utc'] ?? '',
                'staging_db_primary_activation.approval_expires_at_utc'
            )
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
        if ($now < 1) {
            throw new RuntimeException('Staging DB-primary activation verification time is invalid.');
        }

        $actualDatabaseFingerprint = $databaseConfig->identityFingerprint();
        $this->assertSha($actualDatabaseFingerprint, 'Current staging database identity fingerprint');
        $this->assertSha($this->expectedDatabaseIdentityFingerprint, 'Approved staging database identity fingerprint');
        if (!hash_equals($this->expectedDatabaseIdentityFingerprint, $actualDatabaseFingerprint)) {
            throw new RuntimeException('Configured database does not match the staging activation approval.');
        }

        $this->assertCommit($repositoryCommit, 'Current staging repository commit');
        $this->assertCommit($this->expectedRepositoryCommit, 'Approved staging repository commit');
        if (!hash_equals($this->expectedRepositoryCommit, $repositoryCommit)) {
            throw new RuntimeException('Current checkout does not match the staging activation approval.');
        }

        if ($privateDir === ''
            || str_contains($privateDir, '\\')
            || !str_starts_with($privateDir, '/')
            || ($privateDir !== '/' && str_ends_with($privateDir, '/'))) {
            throw new RuntimeException('Staging activation private directory must be an exact absolute Linux path.');
        }
        if ($this->evidenceFile === ''
            || str_contains($this->evidenceFile, '\\')
            || !str_starts_with($this->evidenceFile, '/')
            || str_ends_with($this->evidenceFile, '/')) {
            throw new RuntimeException('Staging activation evidence file must be an exact absolute Linux file path.');
        }
        if (!hash_equals($privateDir, dirname($this->evidenceFile))) {
            throw new RuntimeException('Staging activation evidence file must be located in the verified private directory.');
        }
        $this->assertSha($this->expectedEvidenceFingerprint, 'Approved staging evidence fingerprint');

        $expiresAt = self::parseExactExpiry($this->expiresAtUtc);
        $expiresTimestamp = $expiresAt->getTimestamp();
        if ($expiresTimestamp <= $now) {
            throw new RuntimeException('Staging DB-primary activation approval is expired.');
        }
        if ($expiresTimestamp - $now > self::MAX_APPROVAL_SECONDS) {
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

    private static function parseExactExpiry(string $value): DateTimeImmutable
    {
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
            $value
        ) !== 1) {
            throw new RuntimeException(
                'Staging activation approval expiry must use exact ISO-8601 seconds with an explicit UTC offset.'
            );
        }
        $isZulu = str_ends_with($value, 'Z');
        $parsed = DateTimeImmutable::createFromFormat(
            $isZulu ? '!Y-m-d\TH:i:s\Z' : '!Y-m-d\TH:i:sP',
            $value,
            $isZulu ? new DateTimeZone('UTC') : null
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable
            || (is_array($errors)
                && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))
            || $parsed->format($isZulu ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:sP') !== $value) {
            throw new RuntimeException('Staging activation approval expiry is invalid.');
        }
        return $parsed;
    }

    private static function strictBool(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException($label . ' must be a strict boolean value.');
        }
        return $value;
    }

    private static function strictString(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new RuntimeException($label . ' must be a string value.');
        }
        return $value;
    }
}
