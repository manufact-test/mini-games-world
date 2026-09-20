<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    if ($method !== 'HEAD') {
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_THROW_ON_ERROR);
    }
    exit;
}

$quietRaw = trim((string)($_GET['quiet'] ?? '0'));
$allowedQueryKeys = array_keys($_GET);
if (!in_array($quietRaw, ['0', '1'], true)
    || array_diff($allowedQueryKeys, ['quiet']) !== []) {
    http_response_code(400);
    if ($method !== 'HEAD') {
        echo json_encode(['ok' => false, 'error' => 'Invalid request.'], JSON_THROW_ON_ERROR);
    }
    exit;
}
$expectQuiet = $quietRaw === '1';

const MGW_R13_BROWSER_AUDIT_STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
const MGW_R13_BROWSER_AUDIT_OUTBOX_TABLE = 'mgw_runtime_primary_projection_outbox';
const MGW_R13_BROWSER_AUDIT_COMPLETED_LIMIT = 16;
const MGW_R13_BROWSER_AUDIT_DATABASE_SAFE_MAX_MB = 512.0;
const MGW_R13_BROWSER_AUDIT_OUTBOX_SAFE_MAX_MB = 128.0;

$stage = 'bootstrap';
$connection = null;
$transactionStarted = false;

try {
    require_once __DIR__ . '/core/bootstrap.php';

    $stage = 'validate_isolation';
    if (($config['environment'] ?? '') !== 'staging') {
        throw new RuntimeException('Unavailable outside staging.');
    }

    $baseHost = strtolower((string)(parse_url((string)($config['base_url'] ?? ''), PHP_URL_HOST) ?: ''));
    if ($baseHost !== MGW_R13_BROWSER_AUDIT_STAGING_HOST) {
        throw new RuntimeException('Unexpected staging host.');
    }

    if (!empty($config['external_payments_enabled'])
        || strtolower(trim((string)($config['payment_mode'] ?? ''))) === 'live') {
        throw new RuntimeException('Live payment mode is forbidden.');
    }

    $guard = is_array($config['environment_guard'] ?? null) ? $config['environment_guard'] : [];
    $productionFingerprint = strtolower(trim((string)($guard['production_database_sha256'] ?? '')));
    if (str_starts_with($productionFingerprint, 'sha256:')) {
        $productionFingerprint = substr($productionFingerprint, 7);
    }
    if (preg_match('/^[a-f0-9]{64}$/', $productionFingerprint) !== 1) {
        throw new RuntimeException('Protected production database fingerprint is missing.');
    }

    $database = DatabaseConfig::fromApplicationConfig($config);
    if (!$database->enabled()) {
        throw new RuntimeException('Staging database is not enabled.');
    }

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

    $stage = 'query_aggregates';
    $statusRows = $connection->fetchAll(
        'SELECT status, COUNT(*) AS row_count,
                ROUND(COALESCE(SUM(OCTET_LENGTH(state_json)), 0) / 1024 / 1024, 2) AS state_json_mb
         FROM ' . MGW_R13_BROWSER_AUDIT_OUTBOX_TABLE . '
         GROUP BY status
         ORDER BY status'
    );
    $completedRows = $connection->fetchAll(
        "SELECT COUNT(*) AS completed_rows,
                MIN(state_revision) AS min_completed_revision,
                MAX(state_revision) AS max_completed_revision,
                ROUND(COALESCE(SUM(OCTET_LENGTH(state_json)), 0) / 1024 / 1024, 2) AS completed_state_json_mb
         FROM " . MGW_R13_BROWSER_AUDIT_OUTBOX_TABLE . "
         WHERE status = 'completed'"
    );
    $tableRows = $connection->fetchAll(
        "SELECT table_rows,
                ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                ROUND(index_length / 1024 / 1024, 2) AS index_mb,
                ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_mb
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = '" . MGW_R13_BROWSER_AUDIT_OUTBOX_TABLE . "'"
    );
    $databaseRows = $connection->fetchAll(
        'SELECT ROUND(COALESCE(SUM(data_length + index_length), 0) / 1024 / 1024, 2) AS database_total_mb
         FROM information_schema.tables
         WHERE table_schema = DATABASE()'
    );

    $connection->execute('ROLLBACK');
    $transactionStarted = false;

    if (count($completedRows) !== 1 || count($tableRows) !== 1 || count($databaseRows) !== 1) {
        throw new RuntimeException('Required staging metrics are incomplete.');
    }

    $status = [];
    $unknownStatuses = [];
    foreach ($statusRows as $row) {
        $name = strtolower(trim((string)($row['status'] ?? '')));
        if ($name === '') {
            continue;
        }
        if (!in_array($name, ['pending', 'processing', 'completed', 'failed'], true)) {
            $unknownStatuses[] = $name;
        }
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
    $activeRows = $status['pending']['row_count']
        + $status['processing']['row_count']
        + $status['failed']['row_count'];

    $checks = [
        'staging_database_isolated' => true,
        'unknown_statuses_absent' => $unknownStatuses === [],
        'completed_retention_bounded' => $completed <= MGW_R13_BROWSER_AUDIT_COMPLETED_LIMIT,
        'outbox_size_safe' => $tableTotalMb < MGW_R13_BROWSER_AUDIT_OUTBOX_SAFE_MAX_MB,
        'database_size_safe' => $databaseTotalMb < MGW_R13_BROWSER_AUDIT_DATABASE_SAFE_MAX_MB,
        'quiet_active_rows_zero' => !$expectQuiet || $activeRows === 0,
    ];

    $report = [
        'ok' => !in_array(false, $checks, true),
        'service' => 'mini-games-world-staging-outbox-browser-audit',
        'environment' => 'staging',
        'base_host' => MGW_R13_BROWSER_AUDIT_STAGING_HOST,
        'expect_quiet' => $expectQuiet,
        'server_time_utc' => gmdate('c'),
        'database_identity_sha256' => $stagingFingerprint,
        'limits' => [
            'completed_rows' => MGW_R13_BROWSER_AUDIT_COMPLETED_LIMIT,
            'outbox_total_mb' => MGW_R13_BROWSER_AUDIT_OUTBOX_SAFE_MAX_MB,
            'database_total_mb' => MGW_R13_BROWSER_AUDIT_DATABASE_SAFE_MAX_MB,
            'hostinger_database_limit_mb' => 3072,
        ],
        'status' => $status,
        'completed' => [
            'row_count' => $completed,
            'min_revision' => $completedRows[0]['min_completed_revision'] === null
                ? null
                : (int)$completedRows[0]['min_completed_revision'],
            'max_revision' => $completedRows[0]['max_completed_revision'] === null
                ? null
                : (int)$completedRows[0]['max_completed_revision'],
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

    http_response_code($report['ok'] ? 200 : 409);
    if ($method !== 'HEAD') {
        echo json_encode(
            $report,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
} catch (Throwable $error) {
    if ($connection instanceof DatabaseConnectionInterface && $transactionStarted) {
        try {
            $connection->execute('ROLLBACK');
        } catch (Throwable) {
        }
    }

    error_log('MGW staging browser outbox audit failure at ' . $stage . ': ' . $error->getMessage());
    http_response_code(404);
    if ($method !== 'HEAD') {
        echo json_encode([
            'ok' => false,
            'service' => 'mini-games-world-staging-outbox-browser-audit',
            'stage' => $stage,
            'error' => 'audit_unavailable',
            'server_time_utc' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
