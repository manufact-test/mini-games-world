<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';

$options = getopt('', ['expected-invites:']);
$expectedInvites = array_key_exists('expected-invites', $options)
    ? max(0, (int)$options['expected-invites'])
    : null;
$lockHandle = null;
$exitCode = 0;

try {
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Invite runtime audit is enabled only in staging.');
    }

    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter
        ? $runtimeStorageRouter
        : new RuntimeStorageRouter($config);
    if (!$router->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        throw new RuntimeException('Accounts, notifications and invites DB routing must be enabled before the audit.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== 'json') {
        throw new RuntimeException('Global JSON rollback storage must remain active during the invite audit.');
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
    $lockHandle = fopen($privateDir . '/invite-runtime-audit.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another invite runtime audit is already running.');
    }

    $snapshot = $storage->readOnly(static fn(array $data): array => $data);
    $repository = new RuntimeInviteRepository($config, $router, $database);
    $audit = $repository->auditParity($snapshot);
    $blockers = array_values(array_map('strval', (array)($audit['blockers'] ?? [])));
    $sourceCount = (int)($audit['source_count'] ?? 0);
    $databaseCount = (int)($audit['database_count'] ?? 0);

    if ($expectedInvites !== null && $sourceCount !== $expectedInvites) {
        $blockers[] = 'Expected invite count does not match the JSON rollback source.';
    }
    $blockers = array_values(array_unique($blockers));

    $result = [
        'ok' => $blockers === [] && !empty($audit['ok']),
        'read_only' => true,
        'expected_invite_count' => $expectedInvites,
        'source_invite_count' => $sourceCount,
        'database_invite_count' => $databaseCount,
        'parity' => $blockers === [] && $sourceCount === $databaseCount,
        'source_fingerprint' => (string)($audit['source_fingerprint'] ?? ''),
        'database_fingerprint' => (string)($audit['database_fingerprint'] ?? ''),
        'sensitive_identifiers_exposed' => false,
        'blockers' => $blockers,
        'report_type' => 'mvp-14.8.2d-invite-runtime-audit',
        'environment' => $environment,
        'storage_driver' => $storage->driver(),
        'database_driver' => $database->driver(),
        'schema_current' => true,
        'applied_migrations' => (int)($migrationStatus['applied_count'] ?? 0),
        'database_runtime' => $router->publicStatus(),
        'generated_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        'execution_mode' => 'read-only',
    ];
    $result['report_fingerprint'] = hash('sha256', json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    $exitCode = $result['ok'] ? 0 : 1;
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'read_only' => true,
        'report_type' => 'mvp-14.8.2d-invite-runtime-audit',
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
