<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/ops/backup/BackupManager.php';
require_once $projectRoot . '/ops/backup/BackupConfigLoader.php';

$options = getopt('', ['status', 'run']);
$mode = array_key_exists('status', $options) ? 'status' : 'run';

$normalizePath = static function (string $path): string {
    $path = str_replace('\\', '/', trim($path));
    return rtrim($path, '/');
};
$isInside = static function (string $path, string $parent) use ($normalizePath): bool {
    $path = $normalizePath($path);
    $parent = $normalizePath($parent);
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
};

$configPath = isset($configFile) && is_string($configFile) ? $configFile : '';
$privateDir = $configPath !== '' ? dirname($configPath) : dirname($projectRoot) . '/_private_mgw';
if ($isInside($privateDir, $projectRoot)) {
    $privateDir = dirname($projectRoot) . '/_private_mgw';
}
if (!is_dir($privateDir) && !mkdir($privateDir, 0700, true) && !is_dir($privateDir)) {
    throw new RuntimeException('Could not create the private managed migration directory.');
}

$settings = isset($config['managed_migrations']) && is_array($config['managed_migrations'])
    ? $config['managed_migrations']
    : [];
$logFile = trim((string)($settings['log_file'] ?? ($privateDir . '/managed-migrations.log')));
$lockFile = trim((string)($settings['lock_file'] ?? ($privateDir . '/managed-migrations.lock')));
foreach ([$logFile, $lockFile] as $privatePath) {
    if ($privatePath === '' || $isInside($privatePath, $projectRoot)) {
        throw new RuntimeException('Managed migration state files must be outside the deployed project directory.');
    }
}

$appendLog = static function (string $file, array $entry): void {
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Could not write the managed migration log.');
    }
    @chmod($file, 0600);
};
$print = static function (array $result, int $exitCode = 0): never {
    $stream = $exitCode === 0 ? STDOUT : STDERR;
    fwrite($stream, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit($exitCode);
};

$lockHandle = fopen($lockFile, 'c+');
if ($lockHandle === false) {
    $print(['ok' => false, 'error' => 'Could not open the managed migration lock.'], 1);
}
@chmod($lockFile, 0600);
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $print(['ok' => true, 'action' => 'skipped', 'reason' => 'already_running']);
}

$environmentValue = $config['environment'] ?? 'production';
$environment = $environmentValue instanceof BackedEnum
    ? strtolower(trim((string)$environmentValue->value))
    : strtolower(trim((string)$environmentValue));
$startedAt = gmdate(DATE_ATOM);

try {
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Database is not enabled in the private configuration.');
    }

    $connection = PdoConnectionFactory::create($databaseConfig);
    $runner = new MigrationRunner($connection, $projectRoot . '/bot/database/migrations');
    $policy = ManagedMigrationConfig::fromApplicationConfig($config);

    $backup = static function () use ($projectRoot, $config, $environment): array {
        $backupSettings = BackupConfigLoader::load($projectRoot, $environment);
        $manager = new BackupManager(
            $projectRoot,
            (string)($config['data_dir'] ?? ''),
            (string)$backupSettings['backup_root'],
            isset($backupSettings['external_dir']) ? (string)$backupSettings['external_dir'] : null,
            (int)$backupSettings['retention_days'],
            (int)$backupSettings['retention_count'],
            (bool)$backupSettings['include_release_files']
        );
        return $manager->create($environment, FeatureFlagService::BUILD);
    };

    $controller = new ManagedMigrationController($runner, $policy, $backup);
    $result = $mode === 'status' ? $controller->inspect() : $controller->run();
    $result['mode'] = $mode;
    $result['environment'] = $environment;
    $result['database'] = $databaseConfig->safeSummary();
    $result['started_at_utc'] = $startedAt;
    $result['finished_at_utc'] = gmdate(DATE_ATOM);

    $appendLog($logFile, [
        'ok' => true,
        'mode' => $mode,
        'environment' => $environment,
        'action' => $result['action'] ?? 'status',
        'plan_fingerprint' => $result['plan_fingerprint'] ?? null,
        'executed_count' => (int)($result['executed_count'] ?? 0),
        'started_at_utc' => $startedAt,
        'finished_at_utc' => $result['finished_at_utc'],
    ]);
    $print($result);
} catch (Throwable $error) {
    $message = str_replace(
        [$normalizePath($projectRoot), $normalizePath($privateDir)],
        ['[project]', '[private]'],
        $error->getMessage()
    );
    $failure = [
        'ok' => false,
        'mode' => $mode,
        'environment' => $environment,
        'error' => $message,
        'started_at_utc' => $startedAt,
        'failed_at_utc' => gmdate(DATE_ATOM),
    ];
    try {
        $appendLog($logFile, $failure);
    } catch (Throwable) {
        // Preserve the original failure.
    }
    $print($failure, 1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
