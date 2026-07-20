<?php
declare(strict_types=1);

final class ProductionCutoverConfig
{
    private function __construct(
        private bool $enabled,
        private string $expectedBuild,
        private string $approvalPlanFingerprint,
        private ?int $approvalExpiresAt,
        private bool $requirePrimaryBackup,
        private bool $requireExternalCopy
    ) {}

    public static function fromApplicationConfig(array $config): self
    {
        $settings = is_array($config['production_cutover'] ?? null)
            ? $config['production_cutover']
            : [];

        $expectedBuild = trim((string)($settings['expected_build'] ?? ''));
        if ($expectedBuild !== '' && strlen($expectedBuild) > 191) {
            throw new RuntimeException('Production cutover expected build is invalid.');
        }

        $fingerprint = strtolower(trim((string)($settings['approval_plan_fingerprint'] ?? '')));
        if ($fingerprint !== '' && preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException('Production cutover approval fingerprint must be a SHA-256 value.');
        }

        return new self(
            self::boolValue($settings['enabled'] ?? false, false),
            $expectedBuild,
            $fingerprint,
            self::timestampOrNull($settings['approval_expires_at_utc'] ?? null),
            self::boolValue($settings['require_primary_backup'] ?? true, true),
            self::boolValue($settings['require_external_copy'] ?? true, true)
        );
    }

    public function assertApproved(string $build, string $planFingerprint, int $now): void
    {
        if (!$this->enabled) {
            throw new RuntimeException('Production cutover is not enabled in the private configuration.');
        }
        if ($this->expectedBuild === '' || !hash_equals($this->expectedBuild, $build)) {
            throw new RuntimeException('Production cutover approval is not bound to the deployed build.');
        }
        if ($this->approvalPlanFingerprint === ''
            || !hash_equals($this->approvalPlanFingerprint, strtolower(trim($planFingerprint)))) {
            throw new RuntimeException('Production cutover plan fingerprint is not explicitly approved.');
        }
        if ($this->approvalExpiresAt === null || $this->approvalExpiresAt < $now) {
            throw new RuntimeException('Production cutover approval is missing or expired.');
        }
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
            'approval_plan_fingerprint_configured' => $this->approvalPlanFingerprint !== '',
            'approval_expires_at_utc' => $this->approvalExpiresAt !== null
                ? gmdate(DATE_ATOM, $this->approvalExpiresAt)
                : null,
            'require_primary_backup' => $this->requirePrimaryBackup,
            'require_external_copy' => $this->requireExternalCopy,
        ];
    }

    private static function timestampOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value)) return $value > 0 ? $value : null;

        $timestamp = strtotime(trim((string)$value));
        if ($timestamp === false || $timestamp <= 0) {
            throw new RuntimeException('Production cutover approval expiry must be a valid UTC date/time.');
        }
        return $timestamp;
    }

    private static function boolValue(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (!is_string($value)) return $fallback;

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on', 'enabled' => true,
            '0', 'false', 'no', 'off', 'disabled' => false,
            default => $fallback,
        };
    }
}
