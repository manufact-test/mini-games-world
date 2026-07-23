<?php
declare(strict_types=1);

final class ProductionCutoverConfig
{
    public const RUN_CONFIRMATION = 'CUT OVER PRODUCTION TO DB PRIMARY';
    public const RELEASE_CONFIRMATION = 'RELEASE PRODUCTION DB PRIMARY';
    private const MAX_APPROVAL_TTL_SECONDS = 1800;

    private function __construct(
        private bool $enabled,
        private string $expectedBuild,
        private string $expectedReleaseCommit,
        private string $expectedPackageFingerprint,
        private string $approvalRequestId,
        private string $approvalConfirmation,
        private string $approvalPlanFingerprint,
        private ?int $approvalExpiresAt,
        private bool $releaseEnabled,
        private string $releaseRequestId,
        private string $releaseConfirmation,
        private string $releasePlanFingerprint,
        private string $releaseSourceFingerprint,
        private string $releaseSmokeFingerprint,
        private ?int $releaseExpiresAt,
        private bool $requirePrimaryBackup,
        private bool $requireExternalCopy
    ) {}

    public static function fromApplicationConfig(array $config): self
    {
        $settings = is_array($config['production_cutover'] ?? null)
            ? $config['production_cutover']
            : [];
        $release = is_array($settings['release'] ?? null)
            ? $settings['release']
            : [];

        return new self(
            self::exactBool($settings['enabled'] ?? false, 'production_cutover.enabled'),
            self::boundedString($settings['expected_build'] ?? null, 191),
            self::exactCommit($settings['expected_release_commit'] ?? null),
            self::exactSha($settings['expected_package_fingerprint'] ?? null),
            self::exactRequestId($settings['approval_request_id'] ?? null),
            self::boundedString($settings['approval_confirmation'] ?? null, 100),
            self::exactSha($settings['approval_plan_fingerprint'] ?? null),
            self::timestampOrNull($settings['approval_expires_at_utc'] ?? null),
            self::exactBool($release['enabled'] ?? false, 'production_cutover.release.enabled'),
            self::exactRequestId($release['request_id'] ?? null),
            self::boundedString($release['confirmation'] ?? null, 100),
            self::exactSha($release['plan_fingerprint'] ?? null),
            self::exactSha($release['source_fingerprint'] ?? null),
            self::exactSha($release['smoke_receipt_fingerprint'] ?? null),
            self::timestampOrNull($release['expires_at_utc'] ?? null),
            self::exactBool(
                $settings['require_primary_backup'] ?? true,
                'production_cutover.require_primary_backup'
            ),
            self::exactBool(
                $settings['require_external_copy'] ?? true,
                'production_cutover.require_external_copy'
            )
        );
    }

    public function assertPackage(array $manifest): void
    {
        if (($manifest['ready'] ?? false) !== true) {
            throw new RuntimeException('Production cutover package manifest is not ready.');
        }
        if ($this->expectedBuild === ''
            || ($manifest['build'] ?? null) !== $this->expectedBuild) {
            throw new RuntimeException('Production cutover approval is not bound to the package build.');
        }
        if ($this->expectedReleaseCommit === ''
            || ($manifest['release_commit'] ?? null) !== $this->expectedReleaseCommit) {
            throw new RuntimeException('Production cutover approval is not bound to the deployed commit.');
        }
        if ($this->expectedPackageFingerprint === ''
            || ($manifest['package_fingerprint'] ?? null) !== $this->expectedPackageFingerprint) {
            throw new RuntimeException('Production cutover approval is not bound to the exact package.');
        }
    }

    public function assertApproved(string $build, string $planFingerprint, int $now): void
    {
        if (!$this->enabled) {
            throw new RuntimeException('Production cutover is not enabled in the private configuration.');
        }
        if ($this->expectedBuild === '' || !hash_equals($this->expectedBuild, $build)) {
            throw new RuntimeException('Production cutover approval is not bound to the deployed build.');
        }
        if ($this->approvalRequestId === '') {
            throw new RuntimeException('Production cutover approval request ID is invalid.');
        }
        if (!hash_equals(self::RUN_CONFIRMATION, $this->approvalConfirmation)) {
            throw new RuntimeException('Production cutover confirmation phrase is invalid.');
        }
        $planFingerprint = self::exactSha($planFingerprint);
        if ($this->approvalPlanFingerprint === ''
            || $planFingerprint === ''
            || !hash_equals($this->approvalPlanFingerprint, $planFingerprint)) {
            throw new RuntimeException('Production cutover plan fingerprint is not explicitly approved.');
        }
        self::assertFreshExpiry($this->approvalExpiresAt, $now, 'cutover');
    }

    public function assertReleaseApproved(
        string $planFingerprint,
        string $sourceFingerprint,
        string $smokeReceiptFingerprint,
        int $now
    ): void {
        if (!$this->releaseEnabled) {
            throw new RuntimeException('Production cutover release is not separately enabled.');
        }
        if ($this->releaseRequestId === '') {
            throw new RuntimeException('Production cutover release request ID is invalid.');
        }
        if (!hash_equals(self::RELEASE_CONFIRMATION, $this->releaseConfirmation)) {
            throw new RuntimeException('Production cutover release confirmation phrase is invalid.');
        }
        foreach ([
            'plan' => [self::exactSha($planFingerprint), $this->releasePlanFingerprint],
            'source' => [self::exactSha($sourceFingerprint), $this->releaseSourceFingerprint],
            'smoke receipt' => [
                self::exactSha($smokeReceiptFingerprint),
                $this->releaseSmokeFingerprint,
            ],
        ] as $label => [$actual, $expected]) {
            if ($actual === '' || $expected === '' || !hash_equals($expected, $actual)) {
                throw new RuntimeException(
                    'Production cutover release ' . $label . ' fingerprint is not approved.'
                );
            }
        }
        self::assertFreshExpiry($this->releaseExpiresAt, $now, 'release');
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function releaseEnabled(): bool
    {
        return $this->releaseEnabled;
    }

    public function requirePrimaryBackup(): bool
    {
        return $this->requirePrimaryBackup;
    }

    public function requireExternalCopy(): bool
    {
        return $this->requireExternalCopy;
    }

    public function safeSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'expected_build_configured' => $this->expectedBuild !== '',
            'expected_release_commit_configured' => $this->expectedReleaseCommit !== '',
            'expected_package_fingerprint_configured' => $this->expectedPackageFingerprint !== '',
            'approval_request_id_configured' => $this->approvalRequestId !== '',
            'approval_confirmation_exact' => hash_equals(
                self::RUN_CONFIRMATION,
                $this->approvalConfirmation
            ),
            'approval_plan_fingerprint_configured' => $this->approvalPlanFingerprint !== '',
            'approval_expires_at_utc' => $this->approvalExpiresAt !== null
                ? gmdate(DATE_ATOM, $this->approvalExpiresAt)
                : null,
            'release_enabled' => $this->releaseEnabled,
            'release_request_id_configured' => $this->releaseRequestId !== '',
            'release_confirmation_exact' => hash_equals(
                self::RELEASE_CONFIRMATION,
                $this->releaseConfirmation
            ),
            'release_plan_fingerprint_configured' => $this->releasePlanFingerprint !== '',
            'release_source_fingerprint_configured' => $this->releaseSourceFingerprint !== '',
            'release_smoke_fingerprint_configured' => $this->releaseSmokeFingerprint !== '',
            'release_expires_at_utc' => $this->releaseExpiresAt !== null
                ? gmdate(DATE_ATOM, $this->releaseExpiresAt)
                : null,
            'max_approval_ttl_seconds' => self::MAX_APPROVAL_TTL_SECONDS,
            'require_primary_backup' => $this->requirePrimaryBackup,
            'require_external_copy' => $this->requireExternalCopy,
        ];
    }

    private static function assertFreshExpiry(?int $expiresAt, int $now, string $label): void
    {
        if ($expiresAt === null || $expiresAt <= $now) {
            throw new RuntimeException('Production ' . $label . ' approval is missing or expired.');
        }
        if (($expiresAt - $now) > self::MAX_APPROVAL_TTL_SECONDS) {
            throw new RuntimeException(
                'Production ' . $label . ' approval expiry is too far in the future.'
            );
        }
    }

    private static function timestampOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) {
            throw new RuntimeException(
                'Production cutover approval expiry must be an ISO-8601 string with an explicit UTC offset.'
            );
        }
        $raw = trim($value);
        if ($raw === ''
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $raw) !== 1) {
            throw new RuntimeException(
                'Production cutover approval expiry must be an exact ISO-8601 timestamp.'
            );
        }
        try {
            $date = new DateTimeImmutable($raw);
        } catch (Throwable $error) {
            throw new RuntimeException('Production cutover approval expiry is invalid.', 0, $error);
        }
        return $date->getTimestamp();
    }

    private static function exactBool(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException($label . ' must be an exact boolean.');
        }
        return $value;
    }

    private static function exactCommit(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{40}\z/', $value) === 1
            ? $value
            : '';
    }

    private static function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private static function exactRequestId(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/', $value) === 1
            ? $value
            : '';
    }

    private static function boundedString(mixed $value, int $max): string
    {
        if (!is_string($value)) return '';
        $value = trim($value);
        return $value !== '' && strlen($value) <= $max ? $value : '';
    }
}
