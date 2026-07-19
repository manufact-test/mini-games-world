<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';

$lockHandle = null;
$exitCode = 0;

try {
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Economy runtime audit is enabled only in staging.');
    }

    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter
        ? $runtimeStorageRouter
        : new RuntimeStorageRouter($config);
    if (!$router->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        throw new RuntimeException('Accounts and economy DB routing must be enabled before the audit.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
        throw new RuntimeException('Global JSON rollback storage must remain active during the economy audit.');
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
    $lockHandle = fopen($privateDir . '/economy-runtime-audit.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another economy runtime audit is already running.');
    }

    $snapshot = $storage->readOnly(static fn(array $data): array => $data);
    if (!is_array($snapshot)) {
        throw new RuntimeException('JSON rollback snapshot is invalid.');
    }

    $audit = (new RuntimeEconomyRepository($config, $router, $database))->auditParity($snapshot);
    $result = $audit + [
        'report_type' => 'mvp-14.8.4d-economy-runtime-audit',
        'environment' => $environment,
        'database_driver' => $database->driver(),
        'schema_current' => true,
        'applied_migrations' => (int)($migrationStatus['applied_count'] ?? 0),
        'database_runtime' => $router->publicStatus(),
        'generated_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        'execution_mode' => 'read-only',
    ];
    $result['report_fingerprint'] = hash('sha256', LedgerIntegrity::canonicalJson($result));

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    $exitCode = !empty($result['ok']) ? 0 : 1;
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'read_only' => true,
        'report_type' => 'mvp-14.8.4d-economy-runtime-audit',
        'sensitive_identifiers_exposed' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);
