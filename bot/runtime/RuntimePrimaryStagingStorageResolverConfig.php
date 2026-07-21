<?php
declare(strict_types=1);

final class RuntimePrimaryStagingStorageResolverConfig
{
    private function __construct(private bool $enabled) {}

    public static function fromApplicationConfig(array $config): self
    {
        if (array_key_exists('staging_db_primary_storage_resolver', $config)
            && !is_array($config['staging_db_primary_storage_resolver'])) {
            throw new RuntimeException('staging_db_primary_storage_resolver must be a configuration array.');
        }
        $settings = is_array($config['staging_db_primary_storage_resolver'] ?? null)
            ? $config['staging_db_primary_storage_resolver']
            : [];
        return new self(self::strictBool(
            $settings['enabled'] ?? false,
            'staging_db_primary_storage_resolver.enabled'
        ));
    }

    public function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new RuntimeException('Staging DB-primary storage resolver is disabled.');
        }
    }

    public function safeSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'staging_only' => true,
            'automatic_routing' => false,
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
