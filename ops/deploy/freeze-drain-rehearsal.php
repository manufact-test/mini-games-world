<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/cutover/FreezeDrainRehearsalService.php';
require_once $projectRoot . '/bot/cutover/seal/SealedSnapshotControlService.php';

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

    $lockHandle = fopen($privateDir . '/cutover-rehearsal.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another cutover rehearsal command is already running.');
    }

    $controlFile = $privateDir . '/cutover-rehearsal.json';
    $service = new FreezeDrainRehearsalService(
        $config,
        $storage,
        $controlFile,
        $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : null
    );
    $sealedService = new SealedSnapshotControlService($config, $storage, $controlFile);

    $controlState = 'absent';
    $controlInvalid = false;
    if (is_file($controlFile)) {
        try {
            $rawControl = file_get_contents($controlFile);
            $decodedControl = json_decode(is_string($rawControl) ? $rawControl : '', true, 512, JSON_THROW_ON_ERROR);
            $controlState = is_array($decodedControl) ? (string)($decodedControl['state'] ?? 'absent') : 'invalid';
        } catch (Throwable) {
            $controlState = 'invalid';
            $controlInvalid = true;
        }
    }
    $dataDir = rtrim(str_replace('\\', '/', trim((string)($config['data_dir'] ?? ''))), '/');
    $writeBlockActive = $dataDir !== '' && is_file($dataDir . '/.cutover-write-block');

    if (isset($options['freeze'])) {
        if ($writeBlockActive || $controlState === 'sealed') {
            throw new RuntimeException('JSON is sealed. Release the sealed snapshot rehearsal before starting another freeze.');
        }
        $result = $service->freeze();
        $result['execution_mode'] = 'freeze';
    } elseif (isset($options['release'])) {
        $reason = (string)($options['reason'] ?? 'manual rehearsal release');
        $result = ($writeBlockActive || $controlInvalid)
            ? $sealedService->emergencyRelease($reason)
            : $sealedService->release($reason);
        $result['execution_mode'] = $writeBlockActive || $controlInvalid ? 'emergency-release' : 'release';
        $result['legacy_freeze_drain_cli'] = true;
    } else {
        $result = ($writeBlockActive || $controlState === 'sealed')
            ? $sealedService->status()
            : $service->status();
        $result['execution_mode'] = 'status';
        $result['legacy_freeze_drain_cli'] = true;
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
    if (($result['ok'] ?? false) !== true && !isset($options['status'])) {
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
