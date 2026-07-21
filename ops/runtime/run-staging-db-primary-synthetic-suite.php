<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (count($argv ?? []) !== 1) {
    fwrite(STDERR, "This staging synthetic suite accepts no arguments.\n");
    exit(2);
}

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryPrivateConfigGuard.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Verifier.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Gate.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryJsonEvidence.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingSchemaInspector.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationConfig.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationEvidenceLoader.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionBootstrap.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationGuard.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingStorageResolverConfig.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingStorageResolution.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingStorageResolver.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimarySyntheticRollback.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingSyntheticSuite.php';

$environment = strtolower(trim((string)($config['environment'] ?? '')));
if ($environment !== 'staging') {
    fwrite(STDERR, "DB-primary synthetic suite is staging-only.\n");
    exit(2);
}

$lockHandle = null;
$lockPath = '';
try {
    $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
        (string)($configFile ?? ''),
        $projectRoot
    );
    $privateDir = (string)$private['private_dir'];
    $lockPath = $privateDir . '/runtime-primary-synthetic-suite.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Synthetic suite lock must not be a symbolic link.');
    }
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle)) {
        throw new RuntimeException('Synthetic suite lock is unavailable.');
    }
    @chmod($lockPath, 0600);
    clearstatcache(true, $lockPath);
    if (is_link($lockPath)) {
        throw new RuntimeException('Synthetic suite lock became a symbolic link.');
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another staging synthetic suite is already running.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('DB-primary synthetic suite requires an enabled staging database.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $jsonStorage = StorageFactory::createJson((string)($config['data_dir'] ?? ''));
    $projector = (new RuntimePrimaryRepositoryProjectorFactory(
        $config,
        $database
    ))->create();
    $resolution = (new RuntimePrimaryStagingStorageResolver(
        $projectRoot,
        $config,
        (string)($configFile ?? ''),
        $jsonStorage,
        $database,
        $projector
    ))->resolve();
    $report = (new RuntimePrimaryStagingSyntheticSuite(
        $config,
        $resolution,
        $database
    ))->run();
    fwrite(STDOUT, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.6j-staging-synthetic-suite',
        'action' => 'synthetic_suite_blocked_or_failed',
        'transaction_rolled_back' => null,
        'error_class' => get_class($error),
        'error_message' => $message,
        'application_entrypoint_routed' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    if ($lockPath !== '' && is_file($lockPath) && !is_link($lockPath)) {
        @unlink($lockPath);
    }
}
