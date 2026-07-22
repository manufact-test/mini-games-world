<?php
declare(strict_types=1);

final class RuntimePrimaryStagingMutatingSmokeApproval
{
    public const CONTRACT_VERSION = 'v1-bounded-rollback-staging-mutating-smoke-approval';
    public const MAX_APPROVAL_SECONDS = 600;

    private function __construct(
        private string $approvalId,
        private bool $enabled,
        private string $expectedDatabaseIdentityFingerprint,
        private string $expectedRepositoryCommit,
        private string $expectedReadOnlyReportSha256,
        private int $expectedBaselineStateRevision,
        private string $expectedBaselineStateSha256,
        private string $challengeSha256,
        private string $expiresAtUtc,
        private int $maxStateWrites,
        private bool $rollbackRequired,
        private bool $webhookAllowed,
        private bool $cronChangeAllowed,
        private bool $productionAllowed
    ) {}

    public static function fromArray(array $payload): self
    {
        $expectedKeys = [
            'approval_id',
            'challenge_sha256',
            'contract_version',
            'cron_change_allowed',
            'enabled',
            'expected_baseline_state_revision',
            'expected_baseline_state_sha256',
            'expected_database_identity_fingerprint',
            'expected_read_only_report_sha256',
            'expected_repository_commit',
            'expires_at_utc',
            'max_state_writes',
            'production_allowed',
            'rollback_required',
            'webhook_allowed',
        ];
        $actualKeys = array_keys($payload);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('Staging mutating smoke approval schema is invalid.');
        }
        if (($payload['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new RuntimeException('Staging mutating smoke approval contract version is invalid.');
        }

        return new self(
            self::strictString($payload['approval_id'], 'approval_id'),
            self::strictBool($payload['enabled'], 'enabled'),
            self::strictString(
                $payload['expected_database_identity_fingerprint'],
                'expected_database_identity_fingerprint'
            ),
            self::strictString($payload['expected_repository_commit'], 'expected_repository_commit'),
            self::strictString(
                $payload['expected_read_only_report_sha256'],
                'expected_read_only_report_sha256'
            ),
            self::strictInt(
                $payload['expected_baseline_state_revision'],
                'expected_baseline_state_revision'
            ),
            self::strictString(
                $payload['expected_baseline_state_sha256'],
                'expected_baseline_state_sha256'
            ),
            self::strictString($payload['challenge_sha256'], 'challenge_sha256'),
            self::strictString($payload['expires_at_utc'], 'expires_at_utc'),
            self::strictInt($payload['max_state_writes'], 'max_state_writes'),
            self::strictBool($payload['rollback_required'], 'rollback_required'),
            self::strictBool($payload['webhook_allowed'], 'webhook_allowed'),
            self::strictBool($payload['cron_change_allowed'], 'cron_change_allowed'),
            self::strictBool($payload['production_allowed'], 'production_allowed')
        );
    }

    public function assertApproved(
        DatabaseConfig $databaseConfig,
        string $repositoryCommit,
        string $readOnlyReportSha256,
        int $baselineStateRevision,
        string $baselineStateSha256,
        string $challenge,
        int $now
    ): void {
        if (!$this->enabled) {
            throw new RuntimeException('Staging mutating smoke approval is disabled.');
        }
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Staging mutating smoke requires an enabled database.');
        }
        if ($now < 1) {
            throw new RuntimeException('Staging mutating smoke approval verification time is invalid.');
        }
        if ($this->maxStateWrites !== 1) {
            throw new RuntimeException('Staging mutating smoke approval must allow exactly one temporary state write.');
        }
        if (!$this->rollbackRequired) {
            throw new RuntimeException('Staging mutating smoke approval must require rollback.');
        }
        if ($this->webhookAllowed || $this->cronChangeAllowed || $this->productionAllowed) {
            throw new RuntimeException('Staging mutating smoke approval contains a forbidden safety permission.');
        }

        $this->assertSha($this->approvalId, 'Staging mutating smoke approval ID');
        $actualDatabaseIdentity = $databaseConfig->identityFingerprint();
        $this->assertSha($actualDatabaseIdentity, 'Current staging database identity fingerprint');
        $this->assertSha(
            $this->expectedDatabaseIdentityFingerprint,
            'Approved staging database identity fingerprint'
        );
        if (!hash_equals($this->expectedDatabaseIdentityFingerprint, $actualDatabaseIdentity)) {
            throw new RuntimeException('Configured database does not match the staging mutating smoke approval.');
        }

        $this->assertCommit($repositoryCommit, 'Current staging repository commit');
        $this->assertCommit($this->expectedRepositoryCommit, 'Approved staging repository commit');
        if (!hash_equals($this->expectedRepositoryCommit, $repositoryCommit)) {
            throw new RuntimeException('Current checkout does not match the staging mutating smoke approval.');
        }

        $this->assertSha($readOnlyReportSha256, 'Current read-only smoke report fingerprint');
        $this->assertSha(
            $this->expectedReadOnlyReportSha256,
            'Approved read-only smoke report fingerprint'
        );
        if (!hash_equals($this->expectedReadOnlyReportSha256, $readOnlyReportSha256)) {
            throw new RuntimeException('Read-only smoke report does not match the mutating smoke approval.');
        }

        if ($baselineStateRevision < 1
            || $this->expectedBaselineStateRevision !== $baselineStateRevision) {
            throw new RuntimeException('DB-primary baseline revision does not match the mutating smoke approval.');
        }
        $this->assertSha($baselineStateSha256, 'Current DB-primary baseline fingerprint');
        $this->assertSha(
            $this->expectedBaselineStateSha256,
            'Approved DB-primary baseline fingerprint'
        );
        if (!hash_equals($this->expectedBaselineStateSha256, $baselineStateSha256)) {
            throw new RuntimeException('DB-primary baseline fingerprint does not match the mutating smoke approval.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $challenge) !== 1) {
            throw new RuntimeException('Staging mutating smoke challenge must be exact lowercase hexadecimal.');
        }
        $this->assertSha($this->challengeSha256, 'Approved staging mutating smoke challenge fingerprint');
        if (!hash_equals($this->challengeSha256, hash('sha256', $challenge))) {
            throw new RuntimeException('Staging mutating smoke challenge does not match the approval.');
        }

        $expiresAt = self::parseExactExpiry($this->expiresAtUtc);
        $expiresTimestamp = $expiresAt->getTimestamp();
        if ($expiresTimestamp <= $now) {
            throw new RuntimeException('Staging mutating smoke approval is expired.');
        }
        if ($expiresTimestamp - $now > self::MAX_APPROVAL_SECONDS) {
            throw new RuntimeException('Staging mutating smoke approval may be valid for at most ten minutes.');
        }
    }

    public function approvalId(): string
    {
        return $this->approvalId;
    }

    public function safeSummary(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'approval_id_configured' => $this->validSha($this->approvalId),
            'enabled' => $this->enabled,
            'database_identity_fingerprint_configured' => $this->validSha(
                $this->expectedDatabaseIdentityFingerprint
            ),
            'repository_commit_configured' => preg_match(
                '/^[a-f0-9]{40}$/',
                $this->expectedRepositoryCommit
            ) === 1,
            'read_only_report_fingerprint_configured' => $this->validSha(
                $this->expectedReadOnlyReportSha256
            ),
            'baseline_revision_configured' => $this->expectedBaselineStateRevision > 0,
            'baseline_fingerprint_configured' => $this->validSha(
                $this->expectedBaselineStateSha256
            ),
            'challenge_fingerprint_configured' => $this->validSha($this->challengeSha256),
            'approval_expiry_configured' => $this->expiresAtUtc !== '',
            'max_approval_seconds' => self::MAX_APPROVAL_SECONDS,
            'max_state_writes' => $this->maxStateWrites,
            'rollback_required' => $this->rollbackRequired,
            'webhook_allowed' => $this->webhookAllowed,
            'cron_change_allowed' => $this->cronChangeAllowed,
            'production_allowed' => $this->productionAllowed,
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
                'Staging mutating smoke approval expiry must use exact ISO-8601 seconds with an explicit UTC offset.'
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
            throw new RuntimeException('Staging mutating smoke approval expiry is invalid.');
        }
        return $parsed;
    }

    private static function strictBool(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException('Staging mutating smoke approval field must be boolean: ' . $label . '.');
        }
        return $value;
    }

    private static function strictInt(mixed $value, string $label): int
    {
        if (!is_int($value)) {
            throw new RuntimeException('Staging mutating smoke approval field must be integer: ' . $label . '.');
        }
        return $value;
    }

    private static function strictString(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Staging mutating smoke approval field must be string: ' . $label . '.');
        }
        return $value;
    }
}
