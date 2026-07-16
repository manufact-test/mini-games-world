<?php
declare(strict_types=1);

final class ManagedMigrationConfig
{
    private function __construct(
        private string $environment,
        private bool $enabled,
        private bool $autoRunStaging,
        private bool $productionCliAllowed,
        private string $productionApprovalFingerprint,
        private ?int $productionApprovalExpiresAt,
        private bool $requireProductionBackup,
        private bool $requireProductionExternalCopy
    ) {}

    public static function fromApplicationConfig(array $config): self
    {
        $environmentValue = $config['environment'] ?? 'production';
        $environment = $environmentValue instanceof BackedEnum
            ? strtolower(trim((string)$environmentValue->value))
            : strtolower(trim((string)$environmentValue));
        if ($environment === '') {
            $environment = 'production';
        }

        $settings = isset($config['managed_migrations']) && is_array($config['managed_migrations'])
            ? $config['managed_migrations']
            : [];

        $enabledDefault = $environment === 'staging';
        $enabled = self::boolValue($settings['enabled'] ?? $enabledDefault, $enabledDefault);
        $autoRunStaging = self::boolValue($settings['auto_run_staging'] ?? true, true);
        $productionCliAllowed = self::boolValue(
            $config['database_migrations_allow_production'] ?? false,
            false
        );

        $fingerprint = strtolower(trim((string)($settings['production_approval_fingerprint'] ?? '')));
        if ($fingerprint !== '' && preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException('Managed migration production approval fingerprint must be a SHA-256 value.');
        }

        $expiresAt = self::timestampOrNull($settings['production_approval_expires_at_utc'] ?? null);

        return new self(
            $environment,
            $enabled,
            $autoRunStaging,
            $productionCliAllowed,
            $fingerprint,
            $expiresAt,
            self::boolValue($settings['require_production_backup'] ?? true, true),
            self::boolValue($settings['require_production_external_copy'] ?? true, true)
        );
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function autoRunStaging(): bool
    {
        return $this->autoRunStaging;
    }

    public function productionCliAllowed(): bool
    {
        return $this->productionCliAllowed;
    }

    public function productionApprovalFingerprint(): string
    {
        return $this->productionApprovalFingerprint;
    }

    public function productionApprovalIsCurrent(int $now): bool
    {
        return $this->productionApprovalExpiresAt !== null
            && $this->productionApprovalExpiresAt >= $now;
    }

    public function productionApprovalExpiresAt(): ?int
    {
        return $this->productionApprovalExpiresAt;
    }

    public function requireProductionBackup(): bool
    {
        return $this->requireProductionBackup;
    }

    public function requireProductionExternalCopy(): bool
    {
        return $this->requireProductionExternalCopy;
    }

    public function safeSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'environment' => $this->environment,
            'auto_run_staging' => $this->autoRunStaging,
            'production_cli_allowed' => $this->productionCliAllowed,
            'production_approval_configured' => $this->productionApprovalFingerprint !== '',
            'production_approval_expires_at_utc' => $this->productionApprovalExpiresAt !== null
                ? gmdate(DATE_ATOM, $this->productionApprovalExpiresAt)
                : null,
            'require_production_backup' => $this->requireProductionBackup,
            'require_production_external_copy' => $this->requireProductionExternalCopy,
        ];
    }

    private static function timestampOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        $timestamp = strtotime(trim((string)$value));
        if ($timestamp === false || $timestamp <= 0) {
            throw new RuntimeException('Managed migration production approval expiry must be a valid UTC date/time.');
        }
        return $timestamp;
    }

    private static function boolValue(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (!is_string($value)) return $fallback;

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $fallback,
        };
    }
}
