<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/cutover/FreezeDrainRehearsalService.php';

$options = getopt('', ['freeze', 'release', 'status', 'require-drained', 'reason:']);
$modeCount = (int)isset($options['freeze']) + (int)isset($options['release']) + (int)isset($options['status']);
$lockHandle = null;
$exitCode = 0;

try {
    if ($modeCount > 1) {
        throw new InvalidArgumentException('Choose only one mode: --freeze, --release or --status.');
    }

    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Freeze/drain rehearsal CLI is enabled only in staging.');
    }

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
        throw new RuntimeException('Global JSON rollback storage must remain active during the rehearsal.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir)) {
        throw new RuntimeException('Private runtime directory is unavailable.');
    }

    $lockHandle = fopen($privateDir . '/freeze-drain-rehearsal.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another freeze/drain rehearsal command is already running.');
    }

    $service = new FreezeDrainRehearsalService(
        $config,
        $storage,
        $privateDir . '/cutover-rehearsal.json',
        $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : null
    );

    if (isset($options['freeze'])) {
        $result = $service->freeze();
        $result['execution_mode'] = 'freeze';
    } elseif (isset($options['release'])) {
        $result = $service->release((string)($options['reason'] ?? 'manual rehearsal release'));
        $result['execution_mode'] = 'release';
    } else {
        $result = $service->status();
        $result['execution_mode'] = 'status';
    }

    $result['storage_driver'] = $storage->driver();
    $result['control_file_private'] = true;
    $result['production_changed'] = false;

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);

    if (isset($options['require-drained']) && empty($result['drain']['ready'])) {
        $exitCode = 2;
    }
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.4-freeze-drain-rehearsal',
        'environment' => (string)($config['environment'] ?? 'unknown'),
        'error_class' => get_class($error),
        'error_message' => $error->getMessage(),
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

exit($exitCode);
