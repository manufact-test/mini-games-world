<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (count($argv ?? []) !== 1) {
    fwrite(STDERR, "This read-only storage resolution inspector accepts no arguments.\n");
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

$environment = strtolower(trim((string)($config['environment'] ?? '')));
if ($environment !== 'staging') {
    fwrite(STDERR, "DB-primary storage resolution inspection is staging-only.\n");
    exit(2);
}

try {
    RuntimePrimaryPrivateConfigGuard::assertExternal(
        (string)($configFile ?? ''),
        $projectRoot
    );
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('DB-primary storage resolution requires an enabled staging database.');
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
    fwrite(STDOUT, json_encode(
        $resolution->safeReport(),
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
        'report_type' => 'mvp-14.8.6i-staging-storage-resolution',
        'action' => 'staging_db_primary_storage_resolution_blocked',
        'resolved' => false,
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
}
