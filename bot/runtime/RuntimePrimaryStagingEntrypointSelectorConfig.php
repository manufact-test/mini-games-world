<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEntrypointSelectorConfig
{
    public const CONTRACT_VERSION = 'v1-staging-db-primary-entrypoint-selector';

    private function __construct(
        private bool $enabled,
        private string $contractVersion,
        private array $allowedEntrypoints
    ) {}

    public static function fromApplicationConfig(array $config): self
    {
        if (array_key_exists('staging_db_primary_entrypoint_selector', $config)
            && !is_array($config['staging_db_primary_entrypoint_selector'])) {
            throw new RuntimeException('staging_db_primary_entrypoint_selector must be a configuration array.');
        }
        $settings = is_array($config['staging_db_primary_entrypoint_selector'] ?? null)
            ? $config['staging_db_primary_entrypoint_selector']
            : [];
        $enabled = self::strictBool(
            $settings['enabled'] ?? false,
            'staging_db_primary_entrypoint_selector.enabled'
        );
        $contractVersion = trim((string)($settings['contract_version'] ?? ''));
        $allowed = $settings['allowed_entrypoints'] ?? [];
        if (!is_array($allowed) || !array_is_list($allowed)) {
            throw new RuntimeException('staging_db_primary_entrypoint_selector.allowed_entrypoints must be a list.');
        }
        $normalized = [];
        foreach ($allowed as $entrypoint) {
            $entrypoint = strtolower(trim((string)$entrypoint));
            if (!in_array($entrypoint, ['api', 'webhook'], true)) {
                throw new RuntimeException('Unsupported staging DB-primary entrypoint: ' . $entrypoint . '.');
            }
            if (isset($normalized[$entrypoint])) {
                throw new RuntimeException('Duplicate staging DB-primary entrypoint: ' . $entrypoint . '.');
            }
            $normalized[$entrypoint] = true;
        }
        $allowedEntrypoints = array_keys($normalized);

        if ($enabled) {
            if ($contractVersion !== self::CONTRACT_VERSION) {
                throw new RuntimeException('Staging DB-primary entrypoint selector contract version is invalid.');
            }
            if ($allowedEntrypoints === []) {
                throw new RuntimeException('Enabled staging DB-primary entrypoint selector requires at least one allowed entrypoint.');
            }
        }

        return new self($enabled, $contractVersion, $allowedEntrypoints);
    }

    public function enabledFor(string $entrypoint): bool
    {
        $entrypoint = strtolower(trim($entrypoint));
        if (!in_array($entrypoint, ['api', 'webhook'], true)) {
            throw new InvalidArgumentException('Entrypoint selector supports only api or webhook.');
        }
        return $this->enabled && in_array($entrypoint, $this->allowedEntrypoints, true);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function safeSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'contract_version' => $this->contractVersion,
            'allowed_entrypoints' => $this->allowedEntrypoints,
            'staging_only' => true,
            'production_allowed' => false,
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
