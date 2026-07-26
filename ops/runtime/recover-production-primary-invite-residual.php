<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Production invite residual recovery requires PHP 8.3.x.\n");
    exit(2);
}

$options = [
    'mode' => '',
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
        fwrite(STDERR, "Unknown production invite residual recovery argument.\n");
        exit(2);
    }
}

foreach (['mode', 'expected_commit'] as $required) {
    if ($options[$required] === '') {
        fwrite(STDERR, "Missing production invite residual recovery option: {$required}.\n");
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
    // Recovery validates the runtime that is serving production requests, so the
    // normal bootstrap must merge the private runtime.php overlay. The cutover
    // control bypass is intentionally not enabled here.
    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/ProductionPrimaryEntrypointBootstrap.php';
    require_once $projectRoot . '/bot/runtime/ProductionPrimaryInviteResidualRecoveryService.php';

    if (($config['environment'] ?? null) !== 'production') {
        throw new RuntimeException('Production invite residual recovery is production-only.');
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
        throw new RuntimeException('Production invite residual recovery checkout does not match expected commit.');
    }

    $activation = (new ProductionPrimaryRuntimeActivationContract(
        $projectRoot,
        $config,
        $configPath
    ))->inspect();
    if (($activation['ready'] ?? false) !== true
        || ($activation['state'] ?? '') !== 'completed'
        || ($activation['json_write_block_active'] ?? true) !== false) {
        throw new RuntimeException('Production invite residual recovery requires the completed activation contract.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Production invite residual recovery database configuration is disabled.');
    }
    if (!hash_equals(
        (string)($activation['database_identity_fingerprint'] ?? ''),
        $databaseConfig->identityFingerprint()
    )) {
        throw new RuntimeException('Production invite residual recovery database identity is not approved.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    if ((int)$database->fetchValue('SELECT 1') !== 1) {
        throw new RuntimeException('Production invite residual recovery database probe failed.');
    }

    $lockPath = $privateDir . '/production-primary-invite-residual-recovery.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Production invite residual recovery lock must not be a symbolic link.');
    }
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle)
        || !chmod($lockPath, 0600)
        || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another production invite residual recovery is already running.');
    }

    $projector = (new ProductionPrimaryProjectorFactory(
        $config,
        $database,
        $activation
    ))->create();
    $service = new ProductionPrimaryInviteResidualRecoveryService(
        $database,
        new RuntimePrimaryProjectionAuditorAdapter($projector)
    );
    $preview = $service->preview();

    if ($options['mode'] === 'preview') {
        $public = publicRecoveryReport($preview);
        $public['mode'] = 'preview';
        $public['repository_commit'] = $currentCommit;
        $public['database_write_executed'] = false;
        echo json_encode(
            $public,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ), PHP_EOL;
        $exitCode = ($preview['ready'] ?? false) ? 0 : 3;
    } else {
        if (($preview['ready'] ?? false) !== true) {
            throw new RuntimeException(
                'Production invite residual recovery preview is blocked: '
                . implode('; ', array_map('strval', (array)($preview['blocking_reasons'] ?? [])))
            );
        }
        if (!hash_equals(
            $options['expected_plan_fingerprint'],
            (string)($preview['plan_fingerprint'] ?? '')
        )) {
            throw new RuntimeException('Execution plan fingerprint does not match the current preview.');
        }

        $receiptPath = canonicalPrivateRecoveryPath(
            $options['receipt'],
            $privateDir,
            $projectRoot
        );
        $preimageReceipt = [
            'schema_version' => 1,
            'operation' => 'production_primary_invite_residual_recovery',
            'status' => 'preimage_recorded',
            'contract_version' => ProductionPrimaryInviteResidualRecoveryService::CONTRACT_VERSION,
            'repository_commit' => $currentCommit,
            'database_identity_fingerprint' => $databaseConfig->identityFingerprint(),
            'activation_contract_fingerprint' => (string)($activation['contract_fingerprint'] ?? ''),
            'plan_fingerprint' => (string)$preview['plan_fingerprint'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'preview' => $preview,
            'database_write_executed' => false,
            'cutover_executed' => false,
            'release_executed' => false,
            'rollback_executed' => false,
        ];
        writePrivateRecoveryReceipt($receiptPath, $preimageReceipt);

        $result = $service->run($options['expected_plan_fingerprint']);
        $completed = $preimageReceipt;
        $completed['status'] = 'completed';
        $completed['completed_at_utc'] = gmdate(DATE_ATOM);
        $completed['result'] = $result;
        $completed['database_write_executed'] = true;
        replacePrivateRecoveryReceipt($receiptPath, $completed);

        $public = publicRecoveryReport($result);
        $public['mode'] = 'execute';
        $public['repository_commit'] = $currentCommit;
        $public['receipt_path'] = $receiptPath;
        echo json_encode(
            $public,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
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
            'primary_state_changed' => false,
            'cutover_executed' => false,
            'release_executed' => false,
            'rollback_executed' => false,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);

function publicRecoveryReport(array $report): array
{
    unset($report['private_preimage']);
    $report['sensitive_identifiers_exposed'] = false;
    return $report;
}

function canonicalPrivateRecoveryPath(string $path, string $privateDir, string $projectRoot): string
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

function writePrivateRecoveryReceipt(string $path, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
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

function replacePrivateRecoveryReceipt(string $path, array $payload): void
{
    $temporary = $path . '.completed.' . getmypid();
    writePrivateRecoveryReceipt($temporary, $payload);
    if (!rename($temporary, $path) || !chmod($path, 0600)) {
        @unlink($temporary);
        throw new RuntimeException('Completed recovery receipt could not replace the preimage receipt.');
    }
}
