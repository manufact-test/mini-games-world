<?php
declare(strict_types=1);

require_once __DIR__ . '/BackupManager.php';
require_once __DIR__ . '/BackupConfigLoader.php';

final class BackupCli
{
    public static function boot(): array
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(404);
            exit;
        }

        $projectRoot = dirname(__DIR__, 2);
        require $projectRoot . '/bot/core/bootstrap.php';
        if (!isset($config) || !is_array($config)) {
            throw new RuntimeException('Application config was not loaded.');
        }

        $environmentValue = $config['environment'] ?? 'unknown';
        $environment = $environmentValue instanceof BackedEnum
            ? (string)$environmentValue->value
            : (string)$environmentValue;
        $settings = BackupConfigLoader::load($projectRoot, $environment);

        return [
            'project_root' => $projectRoot,
            'config' => $config,
            'environment' => $environment,
            'build' => self::detectBuild($projectRoot),
            'settings' => $settings,
        ];
    }

    public static function manager(array $context, array $overrides = []): BackupManager
    {
        $settings = array_replace($context['settings'], array_filter(
            $overrides,
            static fn(mixed $value): bool => $value !== null
        ));
        $dataDir = (string)($overrides['data_dir'] ?? $context['config']['data_dir'] ?? '');

        return new BackupManager(
            (string)$context['project_root'],
            $dataDir,
            (string)$settings['backup_root'],
            isset($settings['external_dir']) ? (string)$settings['external_dir'] : null,
            (int)$settings['retention_days'],
            (int)$settings['retention_count'],
            (bool)$settings['include_release_files']
        );
    }

    public static function printJson(array $result): never
    {
        fwrite(STDOUT, json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL);
        exit(0);
    }

    public static function fail(Throwable $error): never
    {
        fwrite(STDERR, json_encode([
            'ok' => false,
            'error' => $error->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }

    private static function detectBuild(string $projectRoot): string
    {
        $indexFile = $projectRoot . '/app/index.html';
        $source = is_file($indexFile) ? (file_get_contents($indexFile) ?: '') : '';
        if (preg_match('/data-build=["\']([^"\']+)["\']/', $source, $matches)) {
            return trim($matches[1]);
        }
        return 'unknown';
    }
}
