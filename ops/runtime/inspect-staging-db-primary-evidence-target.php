<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv ?? []) !== [($argv[0] ?? '')]) {
    fwrite(STDERR, "This read-only inspector accepts no arguments.\n");
    exit(2);
}

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
require $projectRoot . '/bot/core/bootstrap.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceApproval.php';

$environment = strtolower(trim((string)($config['environment'] ?? '')));
if ($environment !== 'staging') {
    fwrite(STDERR, "Staging evidence target inspection is staging-only.\n");
    exit(2);
}

try {
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $report = [
        'ok' => true,
        'report_type' => 'mvp-14.8.6g-staging-evidence-target',
        'action' => 'target_inspected',
        'environment' => $environment,
        'repository_commit' => RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot),
        'database_enabled' => $databaseConfig->enabled(),
        'database_driver' => $databaseConfig->driver(),
        'database_identity_fingerprint' => $databaseConfig->identityFingerprint(),
        'approval' => RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->safeSummary(),
        'database_connection_opened' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
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
        'report_type' => 'mvp-14.8.6g-staging-evidence-target',
        'action' => 'target_inspection_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'database_connection_opened' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
