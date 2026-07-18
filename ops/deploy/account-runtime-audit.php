<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/accounts/AccountRuntimeAuditService.php';

$options = getopt('', ['expected-users:', 'recent-minutes:']);
$expectedUsers = isset($options['expected-users']) ? (int)$options['expected-users'] : 1;
$recentMinutes = isset($options['recent-minutes']) ? (int)$options['recent-minutes'] : 20;
$lockHandle = null;
$exitCode = 0;

try {
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Account runtime audit is enabled only in staging.');
    }

    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter
        ? $runtimeStorageRouter
        : new RuntimeStorageRouter($config);
    if (!$router->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        throw new RuntimeException('Staging account DB routing is not enabled.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== 'json') {
        throw new RuntimeException('Global JSON rollback storage must remain active during the account audit.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Database is not enabled in the private configuration.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $migrationStatus = (new MigrationRunner(
        $database,
        $projectRoot . '/bot/database/migrations'
    ))->status();
    if ((int)($migrationStatus['pending_count'] ?? -1) !== 0) {
        throw new RuntimeException('Database schema has pending migrations.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir)) {
        throw new RuntimeException('Private runtime directory is unavailable.');
    }
    $lockHandle = fopen($privateDir . '/account-runtime-audit.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another account runtime audit is already running.');
    }

    $result = (new AccountRuntimeAuditService($database))->audit(
        max(1, $expectedUsers),
        max(1, $recentMinutes),
        true
    );
    $result['report_type'] = 'mvp-14.8.2b-account-runtime-audit';
    $result['environment'] = $environment;
    $result['storage_driver'] = $storage->driver();
    $result['database_driver'] = $database->driver();
    $result['schema_current'] = true;
    $result['applied_migrations'] = (int)($migrationStatus['applied_count'] ?? 0);
    $result['database_runtime'] = $router->publicStatus();
    $result['generated_at_utc'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->format(DATE_ATOM);
    $result['execution_mode'] = 'read-only';

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    $exitCode = $result['ok'] ? 0 : 1;
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.2b-account-runtime-audit',
        'read_only' => true,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);
