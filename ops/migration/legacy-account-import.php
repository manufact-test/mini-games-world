<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/bot/core/bootstrap.php';
require_once dirname(__DIR__, 2) . '/bot/ledger/LedgerIntegrity.php';
require_once dirname(__DIR__, 2) . '/bot/accounts/LegacyAccountImportService.php';

$options = getopt('', ['status', 'dry-run', 'run', 'allow-production']);
$mode = array_key_exists('run', $options)
    ? 'run'
    : (array_key_exists('dry-run', $options) ? 'dry-run' : 'status');
$lockHandle = null;
$exitCode = 0;

try {
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment === 'production') {
        $explicitApproval = array_key_exists('allow-production', $options);
        $privateApproval = !empty($config['legacy_account_import_allow_production']);
        if (!$explicitApproval || !$privateApproval) {
            throw new RuntimeException('Production legacy account import requires private approval and --allow-production.');
        }
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Database is not enabled in the private configuration.');
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $migrationStatus = (new MigrationRunner(
        $database,
        dirname(__DIR__, 2) . '/bot/database/migrations'
    ))->status();
    if ((int)($migrationStatus['pending_count'] ?? 0) > 0) {
        throw new RuntimeException('Database schema has pending migrations.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname(__DIR__, 2) . '/_private_mgw';
    if (!is_dir($privateDir)) {
        throw new RuntimeException('Private runtime directory is unavailable.');
    }
    $lockHandle = fopen($privateDir . '/legacy-account-import.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another legacy account import is already running.');
    }

    $service = new LegacyAccountImportService(
        StorageFactory::create($config),
        $database
    );
    $result = $mode === 'run' ? $service->run() : $service->preview();
    $result['mode'] = $mode;
    $result['environment'] = $environment;
    $result['storage_driver'] = StorageFactory::create($config)->driver();
    $result['database'] = $databaseConfig->safeSummary();

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);
