<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/cutover/seal/SealedSnapshotControlService.php';

$options = getopt('', ['seal', 'release', 'status', 'reason:']);
$modes = (int)isset($options['seal']) + (int)isset($options['release']) + (int)isset($options['status']);
$lockHandle = null;
$exitCode = 0;

try {
    if ($modes > 1) throw new InvalidArgumentException('Choose only one mode: --seal, --release or --status.');
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') throw new RuntimeException('Sealed snapshot control is enabled only in staging.');

    $storage = StorageFactory::create($config);
    if ($storage->driver() !== 'json') throw new RuntimeException('Global JSON storage must remain active.');
    $privateDir = is_string($configFile ?? null) ? dirname($configFile) : dirname($projectRoot) . '/_private_mgw';
    if (!is_dir($privateDir)) throw new RuntimeException('Private runtime directory is unavailable.');

    $lockHandle = fopen($privateDir . '/sealed-snapshot-control.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another sealed snapshot command is already running.');
    }

    $service = new SealedSnapshotControlService(
        $config,
        $storage,
        $privateDir . '/cutover-rehearsal.json'
    );
    if (isset($options['seal'])) {
        $result = $service->seal();
        $result['execution_mode'] = 'seal';
    } elseif (isset($options['release'])) {
        $result = $service->release((string)($options['reason'] ?? 'sealed snapshot rehearsal completed'));
        $result['execution_mode'] = 'release';
    } else {
        $result = $service->status();
        $result['execution_mode'] = 'status';
    }
    $result['control_file_private'] = true;
    $result['production_changed'] = false;
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.4-sealed-snapshot-control',
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
