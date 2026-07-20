<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv ?? [], 1);
$mode = '';
$maxEvents = 20;
foreach ($arguments as $argument) {
    $argument = trim((string)$argument);
    if (in_array($argument, ['--status', '--install', '--seed', '--run-once', '--rehearse'], true)) {
        if ($mode !== '') {
            fwrite(STDERR, "Only one rehearsal mode may be selected.\n");
            exit(2);
        }
        $mode = substr($argument, 2);
        continue;
    }
    if (str_starts_with($argument, '--max-events=')) {
        $raw = substr($argument, strlen('--max-events='));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            fwrite(STDERR, "--max-events must be an integer between 1 and 100.\n");
            exit(2);
        }
        $maxEvents = (int)$raw;
        continue;
    }
    fwrite(STDERR, "Unknown rehearsal argument: {$argument}\n");
    exit(2);
}
if ($mode === '') {
    fwrite(STDERR, "Select one mode: --status, --install, --seed, --run-once or --rehearse.\n");
    exit(2);
}
if ($mode !== 'rehearse' && $maxEvents !== 20) {
    fwrite(STDERR, "--max-events is supported only with --rehearse.\n");
    exit(2);
}
if ($maxEvents < 1 || $maxEvents > 100) {
    fwrite(STDERR, "--max-events must be between 1 and 100.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorker.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionBootstrap.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryRehearsalBackendInterface.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRehearsalBackend.php';
require_once $projectRoot . '/bot/runtime/StagingPrimaryRehearsalOperation.php';

$environment = strtolower(trim((string)($config['environment'] ?? '')));
if (!in_array($environment, ['local', 'staging'], true)) {
    fwrite(STDERR, "DB-primary rehearsal is forbidden outside local/staging.\n");
    exit(2);
}

$databaseConfig = DatabaseConfig::fromApplicationConfig($config);
if (!$databaseConfig->enabled()) {
    fwrite(STDERR, "DB-primary rehearsal requires an enabled database configuration.\n");
    exit(2);
}

$lockHandle = null;
if ($mode !== 'status') {
    $privateDir = rtrim(str_replace('\\', '/', dirname((string)$configFile)), '/');
    if ($privateDir === '' || !is_dir($privateDir)) {
        fwrite(STDERR, "Private config directory is unavailable.\n");
        exit(2);
    }
    $lockHandle = fopen($privateDir . '/runtime-primary-rehearsal.lock', 'c+');
    if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another DB-primary rehearsal operation is already running.\n");
        exit(2);
    }
}

try {
    $database = PdoConnectionFactory::create($databaseConfig);
    $jsonStorage = StorageFactory::createJson((string)($config['data_dir'] ?? ''));
    $backend = new RuntimePrimaryStagingRehearsalBackend(
        $config,
        $jsonStorage,
        $database
    );
    $operation = new StagingPrimaryRehearsalOperation($config, $backend, $maxEvents);
    $report = match ($mode) {
        'status' => $operation->status(),
        'install' => $operation->install(),
        'seed' => $operation->seed(),
        'run-once' => $operation->runOnce(),
        'rehearse' => $operation->rehearse(),
        default => throw new LogicException('Unsupported rehearsal mode.'),
    };

    fwrite(STDOUT, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(($report['ok'] ?? false) ? 0 : 1);
} catch (Throwable $error) {
    $message = preg_replace(
        '~/(?:home|var|tmp|srv)/[^\s\'\"]+~',
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    $report = [
        'ok' => false,
        'report_type' => 'mvp-14.8.6e-staging-db-primary-rehearsal',
        'action' => 'rehearsal_failed',
        'environment' => $environment,
        'mode' => $mode,
        'error_class' => get_class($error),
        'error_message' => $message,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    fwrite(STDOUT, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
