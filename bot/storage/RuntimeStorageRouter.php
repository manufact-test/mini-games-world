<?php
declare(strict_types=1);

final class RuntimeStorageRouter
{
    public const DRIVER_JSON = 'json';
    public const DRIVER_DATABASE = 'database';

    private const PRODUCTION_ACTIVATION_BUILD = 'v103-mvp14-production-cutover';
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
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        $productionActivated = $environment === 'production'
            && $this->enabled()
            && $this->productionActivationValid($this->settings());

        return [
            'enabled' => $this->enabled(),
            'default_driver' => self::DRIVER_JSON,
            'rollback_driver' => self::DRIVER_JSON,
            'enabled_modules' => $this->enabledModules(),
            'production_allowed' => $productionActivated,
            'production_activated' => $productionActivated,
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
        if (in_array($environment, ['staging', 'local'], true)) {
            // Staging/local keep the existing incremental module-routing contract.
        } elseif ($environment === 'production') {
            $this->assertProductionActivation($settings);
            $missingProductionModules = array_values(array_diff(self::MODULES, $enabledModules));
            if ($missingProductionModules !== []) {
                throw new RuntimeException('Production database runtime requires every approved module to be enabled.');
            }
        } else {
            throw new RuntimeException('Database runtime routing is enabled in an unsupported environment.');
        }

        $globalDriver = strtolower(trim((string)($this->config['storage_driver'] ?? self::DRIVER_JSON)));
        if ($globalDriver === '') $globalDriver = self::DRIVER_JSON;
        if ($globalDriver !== self::DRIVER_JSON) {
            throw new RuntimeException('Global storage_driver must remain json while JSON is the rollback source.');
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
        if (in_array('shop', $enabledModules, true)) {
            foreach (['economy', 'history'] as $dependency) {
                if (!in_array($dependency, $enabledModules, true)) {
                    throw new RuntimeException('Shop DB routing requires economy and history DB routing.');
                }
            }
        }
        if (in_array('payments', $enabledModules, true)) {
            foreach (['economy', 'history'] as $dependency) {
                if (!in_array($dependency, $enabledModules, true)) {
                    throw new RuntimeException('Payment DB routing requires economy and history DB routing.');
                }
            }
        }
        if (in_array('weekly_bonus', $enabledModules, true)) {
            foreach (['realtime', 'economy', 'history'] as $dependency) {
                if (!in_array($dependency, $enabledModules, true)) {
                    throw new RuntimeException('Weekly bonus DB routing requires realtime, economy and history DB routing.');
                }
            }
        }
    }

    private function assertProductionActivation(array $settings): void
    {
        if (!$this->productionActivationValid($settings)) {
            throw new RuntimeException('Production database runtime requires a completed controlled cutover activation marker.');
        }
    }

    private function productionActivationValid(array $settings): bool
    {
        if (!$this->boolValue($settings['production_activated'] ?? false)) return false;
        if (trim((string)($settings['activation_build'] ?? '')) !== self::PRODUCTION_ACTIVATION_BUILD) return false;

        $planFingerprint = strtolower(trim((string)($settings['activation_plan_fingerprint'] ?? '')));
        $sourceFingerprint = strtolower(trim((string)($settings['activation_source_fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $planFingerprint) !== 1) return false;
        if (preg_match('/^[a-f0-9]{64}$/', $sourceFingerprint) !== 1) return false;

        $activatedAt = trim((string)($settings['activated_at_utc'] ?? ''));
        if ($activatedAt === '' || strtotime($activatedAt) === false) return false;

        $rollbackDriver = strtolower(trim((string)($settings['rollback_driver'] ?? self::DRIVER_JSON)));
        return $rollbackDriver === self::DRIVER_JSON;
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
