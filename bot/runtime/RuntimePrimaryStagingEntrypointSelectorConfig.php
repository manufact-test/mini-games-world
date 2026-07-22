<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEntrypointSelectorConfig
{
    public const CONTRACT_VERSION = 'v2-api-only-staging-db-primary-entrypoint-selector';

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
        $enabled = array_key_exists('enabled', $settings)
            ? self::strictBool($settings['enabled'], 'staging_db_primary_entrypoint_selector.enabled')
            : false;
        $contractVersion = array_key_exists('contract_version', $settings)
            ? self::strictString(
                $settings['contract_version'],
                'staging_db_primary_entrypoint_selector.contract_version'
            )
            : '';
        $allowed = array_key_exists('allowed_entrypoints', $settings)
            ? $settings['allowed_entrypoints']
            : [];
        if (!is_array($allowed) || !array_is_list($allowed)) {
            throw new RuntimeException('staging_db_primary_entrypoint_selector.allowed_entrypoints must be a list.');
        }
        foreach ($allowed as $entrypoint) {
            if (!is_string($entrypoint)) {
                throw new RuntimeException(
                    'staging_db_primary_entrypoint_selector.allowed_entrypoints values must be strings.'
                );
            }
            if ($entrypoint !== 'api') {
                throw new RuntimeException('The staging DB-primary entrypoint selector supports only API.');
            }
        }
        if (count($allowed) !== count(array_unique($allowed, SORT_STRING))) {
            throw new RuntimeException('Duplicate staging DB-primary entrypoint: api.');
        }
        $allowedEntrypoints = array_values($allowed);

        if ($enabled) {
            if ($contractVersion !== self::CONTRACT_VERSION) {
                throw new RuntimeException('Staging DB-primary entrypoint selector contract version is invalid.');
            }
            if ($allowedEntrypoints !== ['api']) {
                throw new RuntimeException('Enabled staging DB-primary entrypoint selector must allow exactly API.');
            }
        }

        return new self($enabled, $contractVersion, $allowedEntrypoints);
    }

    public function enabledFor(string $entrypoint): bool
    {
        if (!in_array($entrypoint, ['api', 'webhook'], true)) {
            throw new InvalidArgumentException('Entrypoint selector supports only api or webhook.');
        }
        if ($entrypoint === 'webhook') return false;
        return $this->enabled && $this->allowedEntrypoints === ['api'];
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
            'api_only' => true,
            'webhook_allowed' => false,
            'staging_only' => true,
            'production_allowed' => false,
        ];
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
