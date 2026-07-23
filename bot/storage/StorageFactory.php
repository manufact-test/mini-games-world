<?php
declare(strict_types=1);

require_once __DIR__ . '/../runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once __DIR__ . '/../runtime/RuntimePrimaryProjectionOutboxWriter.php';

final class StorageFactory
{
    public static function create(array $config): StorageAdapterInterface
    {
        $driver = strtolower(trim((string)($config['storage_driver'] ?? 'json')));
        if ($driver === '') {
            $driver = 'json';
        }

        return match ($driver) {
            'json' => self::createJson((string)($config['data_dir'] ?? '')),
            'database' => self::createDatabasePrimary($config),
            default => throw new RuntimeException('Unsupported storage driver: ' . $driver),
        };
    }

    public static function createJson(string $dataDir): StorageAdapterInterface
    {
        self::installGuardedEntrypointContextIfEligible();
        if (class_exists('ProductionPrimaryEntrypointStorageContext', false)
            && ProductionPrimaryEntrypointStorageContext::installed()) {
            return ProductionPrimaryEntrypointStorageContext::storage();
        }
        if (class_exists('RuntimePrimaryEntrypointStorageContext', false)
            && RuntimePrimaryEntrypointStorageContext::installed()) {
            return RuntimePrimaryEntrypointStorageContext::storage();
        }
        return new JsonStorageAdapter($dataDir);
    }

    public static function createDatabasePrimary(array $config): DatabasePrimaryStateStorageAdapter
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('DB-primary storage requires an enabled database configuration.');
        }

        if (array_key_exists('runtime_primary_projection_outbox', $config)
            && !is_array($config['runtime_primary_projection_outbox'])) {
            throw new RuntimeException('runtime_primary_projection_outbox must be a configuration array.');
        }
        $outbox = is_array($config['runtime_primary_projection_outbox'] ?? null)
            ? $config['runtime_primary_projection_outbox']
            : [];
        $outboxEnabled = self::strictBool(
            $outbox['enabled'] ?? false,
            'runtime_primary_projection_outbox.enabled'
        );

        return new DatabasePrimaryStateStorageAdapter(
            PdoConnectionFactory::create($databaseConfig),
            $outboxEnabled ? new RuntimePrimaryProjectionOutboxWriter() : null
        );
    }

    private static function installGuardedEntrypointContextIfEligible(): void
    {
        static $attempted = [];
        static $failures = [];

        $script = basename(trim((string)(
            $_SERVER['SCRIPT_FILENAME']
            ?? $_SERVER['PHP_SELF']
            ?? ''
        )));
        $entrypoint = match ($script) {
            'api.php' => 'api',
            'webhook.php' => 'webhook',
            default => '',
        };
        if ($entrypoint === '') return;

        if (isset($failures[$entrypoint])) {
            throw new RuntimeException(
                'Guarded entrypoint storage selection previously failed in this request.',
                0,
                $failures[$entrypoint]
            );
        }
        if ((class_exists('ProductionPrimaryEntrypointStorageContext', false)
                && ProductionPrimaryEntrypointStorageContext::installed())
            || (class_exists('RuntimePrimaryEntrypointStorageContext', false)
                && RuntimePrimaryEntrypointStorageContext::installed())) {
            return;
        }
        if (isset($attempted[$entrypoint])) return;
        $attempted[$entrypoint] = true;

        $config = $GLOBALS['config'] ?? null;
        $configFile = $GLOBALS['configFile'] ?? null;
        if (!is_array($config) || !is_string($configFile) || trim($configFile) === '') {
            $error = new RuntimeException(
                'Entrypoint storage selector requires the active application config context.'
            );
            $failures[$entrypoint] = $error;
            throw $error;
        }

        try {
            if (($config['environment'] ?? null) === 'production') {
                require_once __DIR__ . '/../runtime/ProductionPrimaryEntrypointBootstrap.php';
                ProductionPrimaryEntrypointBootstrap::installIfEnabled(
                    dirname(__DIR__, 2),
                    $config,
                    $configFile,
                    $entrypoint
                );
                return;
            }

            require_once __DIR__ . '/../runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
            (new RuntimePrimaryStagingEntrypointStorageSelector(
                dirname(__DIR__, 2),
                $config,
                $configFile,
                $entrypoint
            ))->installIfEnabled();
        } catch (Throwable $error) {
            $failures[$entrypoint] = $error;
            throw $error;
        }
    }

    private static function strictBool(mixed $value, string $label): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) {
            if ($value === 0) return false;
            if ($value === 1) return true;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on', 'enabled' => true,
                '0', 'false', 'no', 'off', 'disabled' => false,
                default => throw new RuntimeException($label . ' must be a strict boolean value.'),
            };
        }
        throw new RuntimeException($label . ' must be a strict boolean value.');
    }
}
