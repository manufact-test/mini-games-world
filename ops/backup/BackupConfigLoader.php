<?php
declare(strict_types=1);

final class BackupConfigLoader
{
    public static function load(string $projectRoot, string $environment): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        if ($projectRoot === '') {
            throw new RuntimeException('Project root is required for backup configuration.');
        }

        $privateFile = trim((string)(getenv('MGW_BACKUP_CONFIG_FILE') ?: ''));
        if ($privateFile === '') {
            $privateFile = dirname($projectRoot) . '/_private_mgw/backup.php';
        }

        $private = [];
        if (is_file($privateFile)) {
            $loaded = require $privateFile;
            if (!is_array($loaded)) {
                throw new RuntimeException('Private backup config must return an array.');
            }
            $private = $loaded;
        }

        $backupRoot = self::envOrConfig('MGW_BACKUP_ROOT', $private, 'backup_root')
            ?: dirname($projectRoot) . '/mgw_backups';
        $externalDir = self::envOrConfig('MGW_BACKUP_EXTERNAL_DIR', $private, 'external_dir');
        $retentionDays = self::positiveInt(
            getenv('MGW_BACKUP_RETENTION_DAYS') ?: ($private['retention_days'] ?? 7),
            7
        );
        $retentionCount = self::positiveInt(
            getenv('MGW_BACKUP_RETENTION_COUNT') ?: ($private['retention_count'] ?? 7),
            7
        );
        $includeRelease = self::boolValue(
            getenv('MGW_BACKUP_INCLUDE_RELEASE') !== false
                ? getenv('MGW_BACKUP_INCLUDE_RELEASE')
                : ($private['include_release_files'] ?? true),
            true
        );
        $requireExternal = self::boolValue(
            getenv('MGW_BACKUP_REQUIRE_EXTERNAL') !== false
                ? getenv('MGW_BACKUP_REQUIRE_EXTERNAL')
                : ($private['require_external_copy'] ?? strtolower(trim($environment)) === 'production'),
            strtolower(trim($environment)) === 'production'
        );

        if ($requireExternal && ($externalDir === null || trim($externalDir) === '')) {
            throw new RuntimeException('External backup directory is required for this environment.');
        }

        return [
            'private_config_loaded' => is_file($privateFile),
            'backup_root' => (string)$backupRoot,
            'external_dir' => $externalDir !== null && trim((string)$externalDir) !== '' ? (string)$externalDir : null,
            'retention_days' => $retentionDays,
            'retention_count' => $retentionCount,
            'include_release_files' => $includeRelease,
            'require_external_copy' => $requireExternal,
        ];
    }

    private static function envOrConfig(string $environmentKey, array $config, string $configKey): ?string
    {
        $environmentValue = getenv($environmentKey);
        if ($environmentValue !== false && trim((string)$environmentValue) !== '') {
            return trim((string)$environmentValue);
        }
        $value = $config[$configKey] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function positiveInt(mixed $value, int $fallback): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        return $number !== false && $number > 0 ? (int)$number : $fallback;
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
