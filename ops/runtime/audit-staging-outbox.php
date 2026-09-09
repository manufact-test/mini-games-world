<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$allowedArguments = ['', '--expect-quiet'];
$argument = (string)($argv[1] ?? '');
if ($argc > 2 || !in_array($argument, $allowedArguments, true)) {
    fwrite(STDERR, "Usage: php ops/runtime/audit-staging-outbox.php [--expect-quiet]\n");
    exit(2);
}
$expectQuiet = $argument === '--expect-quiet';

$root = dirname(__DIR__, 2);
require_once $root . '/bot/core/Environment.php';
require_once $root . '/bot/database/DatabaseConfig.php';
require_once $root . '/bot/core/ConfigValidator.php';
require_once $root . '/bot/core/RuntimeConfigLoader.php';
require_once $root . '/bot/core/DatabaseConfigLoader.php';
require_once $root . '/bot/database/DatabaseConnectionInterface.php';
require_once $root . '/bot/database/PdoDatabaseConnection.php';
require_once $root . '/bot/database/PdoConnectionFactory.php';

const MGW_R13_STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
const MGW_R13_OUTBOX_TABLE = 'mgw_runtime_primary_projection_outbox';
const MGW_R13_COMPLETED_LIMIT = 16;
const MGW_R13_DATABASE_SAFE_MAX_MB = 512.0;
const MGW_R13_OUTBOX_SAFE_MAX_MB = 128.0;

$stage = 'load_config';
$connection = null;
$transactionStarted = false;

try {
    $externalConfigFile = getenv('MGW_CONFIG_FILE') ?: dirname($root) . '/_private_mgw/config.php';
    $legacyConfigFile = $root . '/bot/config/config.php';
    $configFile = is_file($externalConfigFile) ? $externalConfigFile : $legacyConfigFile;
    if (!is_file($configFile)) throw new RuntimeException('Private configuration is unavailable.');

    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Private configuration is invalid.');

    $localConfigFile = $root . '/bot/config/config.local.php';
    if (is_file($localConfigFile)) {
        $localConfig = require $localConfigFile;
        if (is_array($localConfig)) $config = array_replace_recursive($config, $localConfig);
    }

    $config = RuntimeConfigLoader::merge($config, $configFile);
    $config = DatabaseConfigLoader::merge($config, $configFile);
    $config = ConfigValidator::validate($config, []);

    $stage = 'validate_isolation';
    if (($config['environment'] ?? '') !== 'staging') {
        throw new RuntimeException('Audit is restricted to staging.');
    }
    $baseHost = strtolower((string)(parse_url((string)($config['base_url'] ?? ''), PHP_URL_HOST) ?: ''));
    if ($baseHost !== MGW_R13_STAGING_HOST) {
        throw new RuntimeException('Unexpected staging host.');
    }
    if (!empty($config['external_payments_enabled'])
        || strtolower(trim((string)($config['payment_mode'] ?? ''))) === 'live') {
        throw new RuntimeException('Live payment mode is forbidden during staging audit.');
    }

    $guard = is_array($config['environment_guard'] ?? null) ? $config['environment_guard'] : [];
    $productionFingerprint = strtolower(trim((string)($guard['production_database_sha256'] ?? '')));
    if (str_starts_with($productionFingerprint, 'sha256:')) $productionFingerprint = substr($productionFingerprint, 7);
    if (preg_match('/^[a-f0-9]{64}$/', $productionFingerprint) !== 1) {
        throw new RuntimeException('Protected production database fingerprint is missing.');
    }

    $database = DatabaseConfig::fromApplicationConfig($config);
    if (!$database->enabled()) throw new RuntimeException('Staging database is not enabled.');
    $stagingFingerprint = $database->identityFingerprint();
    if (preg_match('/^[a-f0-9]{64}$/', $stagingFingerprint) !== 1) {
        throw new RuntimeException('Staging database fingerprint is unavailable.');
    }
    if (hash_equals($productionFingerprint, $stagingFingerprint)) {
        throw new RuntimeException('Staging database identity matches production.');
    }

    $stage = 'connect_read_only';
    $connection = PdoConnectionFactory::create($database);
    $connection->execute('SET SESSION TRANSACTION READ ONLY');
    $connection->execute('START TRANSACTION READ ONLY');
    $transactionStarted = true;

    $stage = 'query_outbox';
    $statusRows = $connection->fetchAll(
        'SELECT status, COUNT(*) AS row_count,
                ROUND(COALESCE(SUM(OCTET_LENGTH(state_json)), 0) / 1024 / 1024, 2) AS state_json_mb
         FROM ' . MGW_R13_OUTBOX_TABLE . '
         GROUP BY status
         ORDER BY status'
    );
    $completedRows = $connection->fetchAll(
        "SELECT COUNT(*) AS completed_rows,
                MIN(state_revision) AS min_completed_revision,
                MAX(state_revision) AS max_completed_revision,
                ROUND(COALESCE(SUM(OCTET_LENGTH(state_json)), 0) / 1024 / 1024, 2) AS completed_state_json_mb
         FROM " . MGW_R13_OUTBOX_TABLE . "
         WHERE status = 'completed'"
    );
    $tableRows = $connection->fetchAll(
        "SELECT table_rows,
                ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                ROUND(index_length / 1024 / 1024, 2) AS index_mb,
                ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_mb
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = '" . MGW_R13_OUTBOX_TABLE . "'"
    );
    $databaseRows = $connection->fetchAll(
        'SELECT ROUND(COALESCE(SUM(data_length + index_length), 0) / 1024 / 1024, 2) AS database_total_mb
         FROM information_schema.tables
         WHERE table_schema = DATABASE()'
    );

    $connection->execute('ROLLBACK');
    $transactionStarted = false;

    if (count($completedRows) !== 1 || count($tableRows) !== 1 || count($databaseRows) !== 1) {
        throw new RuntimeException('Required staging database metrics are incomplete.');
    }

    $status = [];
    $unknownStatuses = [];
    foreach ($statusRows as $row) {
        $name = strtolower(trim((string)($row['status'] ?? '')));
        if ($name === '') continue;
        if (!in_array($name, ['pending', 'processing', 'completed', 'failed'], true)) $unknownStatuses[] = $name;
        $status[$name] = [
            'row_count' => (int)($row['row_count'] ?? 0),
            'state_json_mb' => (float)($row['state_json_mb'] ?? 0),
        ];
    }
    foreach (['pending', 'processing', 'completed', 'failed'] as $name) {
        $status[$name] ??= ['row_count' => 0, 'state_json_mb' => 0.0];
    }
    ksort($status, SORT_STRING);

    $completed = (int)($completedRows[0]['completed_rows'] ?? 0);
    $tableTotalMb = (float)($tableRows[0]['total_mb'] ?? 0);
    $databaseTotalMb = (float)($databaseRows[0]['database_total_mb'] ?? 0);
    $activeRows = $status['pending']['row_count'] + $status['processing']['row_count'] + $status['failed']['row_count'];

    $checks = [
        'staging_database_isolated' => true,
        'unknown_statuses_absent' => $unknownStatuses === [],
        'completed_retention_bounded' => $completed <= MGW_R13_COMPLETED_LIMIT,
        'outbox_size_safe' => $tableTotalMb < MGW_R13_OUTBOX_SAFE_MAX_MB,
        'database_size_safe' => $databaseTotalMb < MGW_R13_DATABASE_SAFE_MAX_MB,
        'quiet_active_rows_zero' => !$expectQuiet || $activeRows === 0,
    ];

    $report = [
        'ok' => !in_array(false, $checks, true),
        'service' => 'mini-games-world-staging-outbox-audit',
        'environment' => 'staging',
        'base_host' => MGW_R13_STAGING_HOST,
        'expect_quiet' => $expectQuiet,
        'server_time_utc' => gmdate('c'),
        'database_identity_sha256' => $stagingFingerprint,
        'limits' => [
            'completed_rows' => MGW_R13_COMPLETED_LIMIT,
            'outbox_total_mb' => MGW_R13_OUTBOX_SAFE_MAX_MB,
            'database_total_mb' => MGW_R13_DATABASE_SAFE_MAX_MB,
            'hostinger_database_limit_mb' => 3072,
        ],
        'status' => $status,
        'completed' => [
            'row_count' => $completed,
            'min_revision' => $completedRows[0]['min_completed_revision'] === null ? null : (int)$completedRows[0]['min_completed_revision'],
            'max_revision' => $completedRows[0]['max_completed_revision'] === null ? null : (int)$completedRows[0]['max_completed_revision'],
            'state_json_mb' => (float)($completedRows[0]['completed_state_json_mb'] ?? 0),
        ],
        'outbox_table' => [
            'estimated_rows' => (int)($tableRows[0]['table_rows'] ?? 0),
            'data_mb' => (float)($tableRows[0]['data_mb'] ?? 0),
            'index_mb' => (float)($tableRows[0]['index_mb'] ?? 0),
            'total_mb' => $tableTotalMb,
        ],
        'database' => [
            'total_mb' => $databaseTotalMb,
            'hostinger_limit_used_percent' => round(($databaseTotalMb / 3072) * 100, 4),
        ],
        'checks' => $checks,
    ];

    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($report['ok'] ? 0 : 5);
} catch (Throwable $error) {
    if ($connection instanceof DatabaseConnectionInterface && $transactionStarted) {
        try {
            $connection->execute('ROLLBACK');
        } catch (Throwable) {
        }
    }
    $failure = [
        'ok' => false,
        'service' => 'mini-games-world-staging-outbox-audit',
        'stage' => $stage,
        'error' => 'audit_failed',
        'server_time_utc' => gmdate('c'),
    ];
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(10);
}
