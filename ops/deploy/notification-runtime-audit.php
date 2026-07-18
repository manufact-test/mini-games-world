<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';

$options = getopt('', ['expected-users:']);
$expectedUsers = isset($options['expected-users']) ? max(1, (int)$options['expected-users']) : 1;
$lockHandle = null;
$exitCode = 0;

try {
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Notification runtime audit is enabled only in staging.');
    }

    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter
        ? $runtimeStorageRouter
        : new RuntimeStorageRouter($config);
    if (!$router->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        throw new RuntimeException('Accounts and notifications DB routing must be enabled before the audit.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== 'json') {
        throw new RuntimeException('Global JSON rollback storage must remain active during the notification audit.');
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
    $lockHandle = fopen($privateDir . '/notification-runtime-audit.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another notification runtime audit is already running.');
    }

    $snapshot = $storage->readOnly(static fn(array $data): array => $data);
    $users = is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [];
    $userIds = [];
    foreach ($users as $sourceKey => $record) {
        if (!is_array($record)) {
            throw new RuntimeException('JSON user record is not an object.');
        }
        $legacyUserId = trim((string)($record['id'] ?? (is_string($sourceKey) ? $sourceKey : '')));
        if ($legacyUserId === '') {
            throw new RuntimeException('JSON user record has no stable ID.');
        }
        if (isset($userIds[$legacyUserId])) {
            throw new RuntimeException('JSON users contain a duplicate stable ID.');
        }
        $userIds[$legacyUserId] = true;
    }

    $blockers = [];
    if (count($userIds) !== $expectedUsers) {
        $blockers[] = 'Expected user count does not match the JSON source.';
    }

    $repository = new RuntimeNotificationRepository($config, $router, $database);
    $auditedUsers = 0;
    $sourceCount = 0;
    $databaseCount = 0;
    $userReportFingerprints = [];

    foreach (array_keys($userIds) as $legacyUserId) {
        $userFingerprint = hash('sha256', 'mgw-notification-audit|' . $legacyUserId);
        try {
            $report = $repository->auditParity($snapshot, $legacyUserId);
            $auditedUsers++;
            $sourceCount += (int)($report['source_count'] ?? 0);
            $databaseCount += (int)($report['database_count'] ?? 0);
            foreach ((array)($report['blockers'] ?? []) as $reason) {
                $blockers[] = $userFingerprint . ': ' . (string)$reason;
            }
            $userReportFingerprints[] = hash('sha256', json_encode([
                'user_fingerprint' => $userFingerprint,
                'source_count' => (int)($report['source_count'] ?? 0),
                'database_count' => (int)($report['database_count'] ?? 0),
                'source_fingerprint' => (string)($report['source_fingerprint'] ?? ''),
                'database_fingerprint' => (string)($report['database_fingerprint'] ?? ''),
                'ok' => !empty($report['ok']),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $userError) {
            $blockers[] = $userFingerprint . ': ' . $userError->getMessage();
        }
    }

    sort($userReportFingerprints, SORT_STRING);
    $blockers = array_values(array_unique($blockers));
    $result = [
        'ok' => $blockers === [] && $auditedUsers === $expectedUsers,
        'read_only' => true,
        'expected_user_count' => $expectedUsers,
        'audited_user_count' => $auditedUsers,
        'source_notification_count' => $sourceCount,
        'database_notification_count' => $databaseCount,
        'parity' => $blockers === [] && $sourceCount === $databaseCount,
        'user_report_fingerprints' => $userReportFingerprints,
        'sensitive_identifiers_exposed' => false,
        'blockers' => $blockers,
        'report_type' => 'mvp-14.8.2c-notification-runtime-audit',
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
        'report_type' => 'mvp-14.8.2c-notification-runtime-audit',
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
