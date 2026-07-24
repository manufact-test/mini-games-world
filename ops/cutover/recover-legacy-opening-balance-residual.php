<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Opening residual recovery requires PHP 8.3.x.\n");
    exit(2);
}

$options = [
    'mode' => '',
    'legacy_user_id' => '',
    'expected_mgw_id' => '',
    'expected_commit' => '',
    'expected_plan_fingerprint' => '',
    'receipt' => '',
];
$seen = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--preview' || $argument === '--execute') {
        if ($options['mode'] !== '') {
            fwrite(STDERR, "Specify exactly one recovery mode.\n");
            exit(2);
        }
        $options['mode'] = $argument === '--preview' ? 'preview' : 'execute';
        continue;
    }
    $matched = false;
    foreach ([
        '--legacy-user-id=' => 'legacy_user_id',
        '--expected-mgw-id=' => 'expected_mgw_id',
        '--expected-commit=' => 'expected_commit',
        '--expected-plan-fingerprint=' => 'expected_plan_fingerprint',
        '--receipt=' => 'receipt',
    ] as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        if (isset($seen[$name])) {
            fwrite(STDERR, "Recovery option may be specified only once: {$name}.\n");
            exit(2);
        }
        $seen[$name] = true;
        $options[$name] = substr($argument, strlen($prefix));
        $matched = true;
        break;
    }
    if (!$matched) {
        fwrite(STDERR, "Unknown opening residual recovery argument.\n");
        exit(2);
    }
}

foreach (['mode', 'legacy_user_id', 'expected_mgw_id', 'expected_commit'] as $required) {
    if ($options[$required] === '') {
        fwrite(STDERR, "Missing opening residual recovery option: {$required}.\n");
        exit(2);
    }
}
if (preg_match('/\A[a-f0-9]{40}\z/', $options['expected_commit']) !== 1) {
    fwrite(STDERR, "Expected repository commit is invalid.\n");
    exit(2);
}
if ($options['mode'] === 'execute') {
    foreach (['expected_plan_fingerprint', 'receipt'] as $required) {
        if ($options[$required] === '') {
            fwrite(STDERR, "Missing execution-only recovery option: {$required}.\n");
            exit(2);
        }
    }
    if (preg_match('/\A[a-f0-9]{64}\z/', $options['expected_plan_fingerprint']) !== 1) {
        fwrite(STDERR, "Expected recovery plan fingerprint is invalid.\n");
        exit(2);
    }
}

umask(0077);
$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
$lockHandle = null;
$exitCode = 1;

try {
    if (!defined('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP')) {
        define('MINIGAMES_CUTOVER_CONTROL_BOOTSTRAP', true);
    }
    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/cutover/LegacyOpeningBalanceResidualRecoveryService.php';

    if (($config['environment'] ?? null) !== 'production') {
        throw new RuntimeException('Opening residual recovery is production-only.');
    }
    $configPath = is_string($configFile ?? null) ? str_replace('\\', '/', $configFile) : '';
    if ($configPath === '' || !str_starts_with($configPath, '/') || !is_file($configPath) || is_link($configPath)) {
        throw new RuntimeException('External production configuration file is unavailable.');
    }
    $privateDir = rtrim(str_replace('\\', '/', dirname($configPath)), '/');
    if ($privateDir === '' || !is_dir($privateDir) || is_link($privateDir)) {
        throw new RuntimeException('Private production directory is unavailable.');
    }

    $currentCommit = trim((string)shell_exec(
        'git -C ' . escapeshellarg($projectRoot) . ' rev-parse HEAD 2>/dev/null'
    ));
    if (!hash_equals($options['expected_commit'], $currentCommit)) {
        throw new RuntimeException('Opening residual recovery checkout does not match expected commit.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== 'json') {
        throw new RuntimeException('Opening residual recovery requires JSON-first production runtime.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Opening residual recovery database configuration is disabled.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    if ((int)$database->fetchValue('SELECT 1') !== 1) {
        throw new RuntimeException('Opening residual recovery database read probe failed.');
    }

    $lockPath = $privateDir . '/legacy-opening-residual-recovery.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Opening residual recovery lock must not be a symbolic link.');
    }
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle)
        || !chmod($lockPath, 0600)
        || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another opening residual recovery operation is already running.');
    }

    $service = new LegacyOpeningBalanceResidualRecoveryService(
        $storage,
        $database,
        new LedgerIntegrityVerifier($database)
    );
    $preview = $service->preview(
        $options['legacy_user_id'],
        $options['expected_mgw_id']
    );

    if ($options['mode'] === 'preview') {
        echo json_encode(
            [
                'ok' => $preview['ok'],
                'mode' => 'preview',
                'ready' => $preview['ready'],
                'status' => $preview['status'],
                'repository_commit' => $currentCommit,
                'legacy_user_id' => $preview['legacy_user_id'],
                'mgw_id' => $preview['mgw_id'],
                'mgw_account_ref' => $preview['mgw_account_ref'],
                'legacy_account_ref' => $preview['legacy_account_ref'],
                'plan_fingerprint' => $preview['plan_fingerprint'],
                'balance_row_count' => $preview['balance_row_count'],
                'ledger_row_count' => $preview['ledger_row_count'],
                'idempotency_row_count' => $preview['idempotency_row_count'],
                'blocking_reasons' => $preview['blocking_reasons'],
                'database_write_executed' => false,
                'rearm_executed' => false,
                'cutover_executed' => false,
                'release_executed' => false,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        ), PHP_EOL;
        $exitCode = $preview['ready'] ? 0 : 3;
    } else {
        if (!$preview['ready']) {
            throw new RuntimeException(
                'Opening residual recovery preview is blocked: '
                . implode('; ', $preview['blocking_reasons'])
            );
        }
        if (!hash_equals(
            $options['expected_plan_fingerprint'],
            (string)$preview['plan_fingerprint']
        )) {
            throw new RuntimeException('Execution plan fingerprint does not match the current preview.');
        }

        $receiptPath = canonicalPrivateOutputPath(
            $options['receipt'],
            $privateDir,
            $projectRoot
        );
        $startedAt = gmdate(DATE_ATOM);
        $preimageReceipt = [
            'schema_version' => 1,
            'operation' => 'legacy_opening_balance_residual_recovery',
            'status' => 'preimage_recorded',
            'repository_commit' => $currentCommit,
            'database_identity_fingerprint' => $databaseConfig->identityFingerprint(),
            'plan_fingerprint' => $preview['plan_fingerprint'],
            'started_at_utc' => $startedAt,
            'preview' => $preview,
            'database_write_executed' => false,
        ];
        writePrivateNoClobber($receiptPath, $preimageReceipt);

        $result = $service->run(
            $options['legacy_user_id'],
            $options['expected_mgw_id'],
            $options['expected_plan_fingerprint']
        );
        $completedReceipt = $preimageReceipt + [
            'completed_at_utc' => gmdate(DATE_ATOM),
            'result' => $result,
        ];
        $completedReceipt['status'] = 'completed';
        $completedReceipt['database_write_executed'] = true;
        replacePrivateReceipt($receiptPath, $completedReceipt);

        echo json_encode(
            [
                'ok' => true,
                'mode' => 'execute',
                'status' => 'completed',
                'repository_commit' => $currentCommit,
                'plan_fingerprint' => $result['plan_fingerprint'],
                'deleted' => $result['deleted'],
                'preserved' => $result['preserved'],
                'receipt_path' => $receiptPath,
                'database_write_executed' => true,
                'rearm_executed' => false,
                'cutover_executed' => false,
                'release_executed' => false,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        ), PHP_EOL;
        $exitCode = 0;
    }
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(
        [
            'ok' => false,
            'error_class' => get_class($error),
            'error_message' => $error->getMessage(),
            'database_write_executed' => false,
            'rearm_executed' => false,
            'cutover_executed' => false,
            'release_executed' => false,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    ) . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);

function canonicalPrivateOutputPath(string $path, string $privateDir, string $projectRoot): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || !str_starts_with($path, '/') || str_ends_with($path, '/')) {
        throw new RuntimeException('Recovery receipt path must be an exact absolute Linux file path.');
    }
    $parent = realpath(dirname($path));
    $privateReal = realpath($privateDir);
    $projectReal = realpath($projectRoot);
    if (!is_string($parent) || !is_string($privateReal) || !is_string($projectReal)) {
        throw new RuntimeException('Recovery receipt directory cannot be resolved.');
    }
    $parent = str_replace('\\', '/', $parent);
    $privateReal = rtrim(str_replace('\\', '/', $privateReal), '/');
    $projectReal = rtrim(str_replace('\\', '/', $projectReal), '/');
    if (!str_starts_with($parent . '/', $privateReal . '/')
        || str_starts_with($parent . '/', $projectReal . '/')) {
        throw new RuntimeException('Recovery receipt must remain inside the external private directory.');
    }
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('Recovery receipt path already exists.');
    }
    return $path;
}

function writePrivateNoClobber(string $path, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    $handle = fopen($path, 'x');
    if (!is_resource($handle) || !chmod($path, 0600)) {
        if (is_resource($handle)) fclose($handle);
        @unlink($path);
        throw new RuntimeException('Recovery preimage receipt could not be created safely.');
    }
    try {
        $written = fwrite($handle, $json);
        if ($written !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException('Recovery preimage receipt could not be written completely.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Recovery preimage receipt could not be synchronized.');
        }
    } catch (Throwable $error) {
        fclose($handle);
        @unlink($path);
        throw $error;
    }
    fclose($handle);
}

function replacePrivateReceipt(string $path, array $payload): void
{
    $temporary = $path . '.completed.' . getmypid();
    writePrivateNoClobber($temporary, $payload);
    if (!rename($temporary, $path) || !chmod($path, 0600)) {
        @unlink($temporary);
        throw new RuntimeException('Completed recovery receipt could not replace the preimage receipt.');
    }
}
