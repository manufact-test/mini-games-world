<?php
declare(strict_types=1);

final class RuntimeStorageRouter
{
    public const DRIVER_JSON = 'json';
    public const DRIVER_DATABASE = 'database';

    private const MODULES = [
        'accounts',
        'realtime',
        'invites',
        'notifications',
        'economy',
        'history',
        'shop',
        'payments',
        'weekly_bonus',
    ];

    public function __construct(private array $config)
    {
        $this->validate();
    }

    public function routeFor(string $module): string
    {
        $module = strtolower(trim($module));
        if (!in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException('Unknown runtime storage module: ' . $module);
        }
        if (!$this->enabled()) return self::DRIVER_JSON;

        $modules = $this->settings()['modules'] ?? [];
        if (!is_array($modules)) {
            throw new RuntimeException('database_runtime.modules must be an array.');
        }
        return $this->boolValue($modules[$module] ?? false)
            ? self::DRIVER_DATABASE
            : self::DRIVER_JSON;
    }

    public function enabled(): bool
    {
        return $this->boolValue($this->settings()['enabled'] ?? false);
    }

    public function enabledModules(): array
    {
        $enabled = [];
        foreach (self::MODULES as $module) {
            if ($this->routeFor($module) === self::DRIVER_DATABASE) $enabled[] = $module;
        }
        return $enabled;
    }

    public function publicStatus(): array
    {
        return [
            'enabled' => $this->enabled(),
            'default_driver' => self::DRIVER_JSON,
            'rollback_driver' => self::DRIVER_JSON,
            'enabled_modules' => $this->enabledModules(),
            'production_allowed' => false,
        ];
    }

    private function validate(): void
    {
        $settings = $this->settings();
        if (!is_array($settings)) throw new RuntimeException('database_runtime must be an array.');

        $modules = $settings['modules'] ?? [];
        if (!is_array($modules)) throw new RuntimeException('database_runtime.modules must be an array.');

        $enabledModules = [];
        foreach ($modules as $module => $value) {
            if (!in_array((string)$module, self::MODULES, true)) {
                throw new RuntimeException('database_runtime contains an unknown module: ' . (string)$module);
            }
            if ($this->boolValue($value, true)) $enabledModules[] = (string)$module;
        }

        if (!$this->enabled()) return;

        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Database runtime routing is forbidden outside staging/local during MVP-14.8.2.');
        }

        $globalDriver = strtolower(trim((string)($this->config['storage_driver'] ?? self::DRIVER_JSON)));
        if ($globalDriver === '') $globalDriver = self::DRIVER_JSON;
        if ($globalDriver !== self::DRIVER_JSON) {
            throw new RuntimeException('Global storage_driver must remain json during staged module routing.');
        }

        $database = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$database->enabled()) {
            throw new RuntimeException('Database runtime routing requires an enabled database configuration.');
        }

        if ($enabledModules !== [] && !in_array('accounts', $enabledModules, true)) {
            throw new RuntimeException('Database runtime modules require accounts routing for stable MGW identity.');
        }
        if (in_array('invites', $enabledModules, true)
            && !in_array('notifications', $enabledModules, true)) {
            throw new RuntimeException('Invite DB routing requires notification DB routing.');
        }
        if (in_array('history', $enabledModules, true)) {
            foreach (['realtime', 'economy'] as $dependency) {
                if (!in_array($dependency, $enabledModules, true)) {
                    throw new RuntimeException('History DB routing requires realtime and economy DB routing.');
                }
            }
        }
    }

    private function settings(): array
    {
        $flags = $this->config['feature_flags'] ?? [];
        if (!is_array($flags)) return [];
        $settings = $flags['database_runtime'] ?? [];
        if ($settings === null || $settings === false) return [];
        if (!is_array($settings)) throw new RuntimeException('database_runtime must be an array.');
        return $settings;
    }

    private function boolValue(mixed $value, bool $strict = false): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) return true;
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled', ''], true)) return false;
        }
        if ($strict) throw new RuntimeException('database_runtime module values must be boolean-like.');
        return false;
    }
}
