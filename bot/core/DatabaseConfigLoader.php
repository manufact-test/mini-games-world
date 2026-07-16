<?php
declare(strict_types=1);

final class DatabaseConfigLoader
{
    public static function merge(array $config, string $applicationConfigFile): array
    {
        $privateFile = trim((string)(getenv('MGW_DATABASE_CONFIG_FILE') ?: ''));
        if ($privateFile === '') {
            $privateFile = dirname($applicationConfigFile) . '/database.php';
        }

        if (!is_file($privateFile)) {
            $config['database_config_loaded'] = false;
            return $config;
        }

        $loaded = require $privateFile;
        if (!is_array($loaded)) {
            throw new RuntimeException('Private database config must return an array.');
        }

        $allowed = [];
        if (isset($loaded['database'])) {
            if (!is_array($loaded['database'])) {
                throw new RuntimeException('Private database settings must be an array.');
            }
            $allowed['database'] = $loaded['database'];
        }
        if (array_key_exists('database_migrations_allow_production', $loaded)) {
            $allowed['database_migrations_allow_production'] = $loaded['database_migrations_allow_production'];
        }
        if (isset($loaded['environment_guard'])) {
            if (!is_array($loaded['environment_guard'])) {
                throw new RuntimeException('Private database environment guard must be an array.');
            }
            $unexpectedGuardKeys = array_diff(array_keys($loaded['environment_guard']), ['production_database_sha256']);
            if ($unexpectedGuardKeys !== []) {
                throw new RuntimeException('Private database config contains unsupported environment guard keys.');
            }
            $allowed['environment_guard'] = [
                'production_database_sha256' => $loaded['environment_guard']['production_database_sha256'] ?? '',
            ];
        }

        $unexpectedTopLevelKeys = array_diff(array_keys($loaded), [
            'database',
            'database_migrations_allow_production',
            'environment_guard',
        ]);
        if ($unexpectedTopLevelKeys !== []) {
            throw new RuntimeException('Private database config contains unsupported keys.');
        }

        $config = array_replace_recursive($config, $allowed);
        $config['database_config_loaded'] = true;
        return $config;
    }
}
