<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$expectedCommit = '';
$seenExpectedCommit = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (!str_starts_with($argument, '--expected-commit=')) {
        fwrite(STDERR, "Unknown staging read-only prerequisite argument.\n");
        exit(2);
    }
    if ($seenExpectedCommit) {
        fwrite(STDERR, "--expected-commit may be specified only once.\n");
        exit(2);
    }
    $seenExpectedCommit = true;
    $expectedCommit = substr($argument, strlen('--expected-commit='));
}
if (!$seenExpectedCommit || preg_match('/\A[a-f0-9]{40}\z/', $expectedCommit) !== 1) {
    fwrite(STDERR, "Staging read-only prerequisite check requires an exact lowercase 40-character --expected-commit.\n");
    exit(2);
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Staging read-only prerequisite check requires PHP 8.3.x.\n");
    exit(2);
}

set_error_handler(static function (int $severity): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed staging read-only prerequisite warning.', 0, $severity);
    }
    throw new RuntimeException('Staging read-only prerequisite filesystem operation failed.');
});

$exitCode = 1;
$response = [];
try {
    $projectRootRaw = dirname(__DIR__, 2);
    $projectRootReal = realpath($projectRootRaw);
    if (!is_string($projectRootReal) || !is_dir($projectRootReal) || is_link($projectRootRaw)) {
        throw new RuntimeException('Staging project root is unavailable or noncanonical.');
    }
    $projectRoot = rtrim(str_replace('\\', '/', $projectRootReal), '/');

    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryPrivateConfigGuard.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceApproval.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationConfig.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';

    if (($config['environment'] ?? null) !== 'staging') {
        throw new RuntimeException('Staging read-only prerequisite check is staging-only.');
    }

    RuntimePrimaryPrivateConfigGuard::assertExternal(
        is_string($configFile ?? null) ? $configFile : '',
        $projectRoot
    );

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Staging read-only prerequisite check requires an enabled staging database.');
    }
    $databaseIdentity = $databaseConfig->identityFingerprint();
    if (preg_match('/\A[a-f0-9]{64}\z/', $databaseIdentity) !== 1) {
        throw new RuntimeException('Staging database identity fingerprint is unavailable.');
    }

    $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot);
    if (!hash_equals($expectedCommit, $currentCommit)) {
        throw new RuntimeException('Deployed staging checkout does not match the expected commit.');
    }

    $evidenceApproval = RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->safeSummary();
    $activation = RuntimePrimaryStagingActivationConfig::fromApplicationConfig($config)->safeSummary();
    $selector = RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig($config)->safeSummary();
    $session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config)->safeSummary();
    foreach ([
        'staging_db_primary_evidence' => $evidenceApproval['enabled'] ?? null,
        'staging_db_primary_activation' => $activation['enabled'] ?? null,
        'staging_db_primary_entrypoint_selector' => $selector['enabled'] ?? null,
        'staging_db_primary_request_session' => $session['enabled'] ?? null,
    ] as $label => $enabled) {
        if ($enabled !== false) {
            throw new RuntimeException($label . ' must remain persistently disabled before the read-only checkpoint.');
        }
    }
    if (($selector['webhook_allowed'] ?? null) !== false
        || ($session['webhook_allowed'] ?? null) !== false) {
        throw new RuntimeException('Webhook must remain disabled for the staging read-only checkpoint.');
    }

    $dataDir = $config['data_dir'] ?? null;
    if (!is_string($dataDir)
        || $dataDir === ''
        || trim($dataDir) !== $dataDir
        || str_contains($dataDir, '\\')
        || !str_starts_with($dataDir, '/')
        || str_ends_with($dataDir, '/')) {
        throw new RuntimeException('JSON rollback data directory must be an exact absolute Linux path.');
    }
    if (is_link($dataDir)) {
        throw new RuntimeException('JSON rollback data directory must not be a symbolic link.');
    }
    $dataDirReal = realpath($dataDir);
    if (!is_string($dataDirReal) || !is_dir($dataDirReal)) {
        throw new RuntimeException('JSON rollback data directory is unavailable.');
    }
    $dataDirCanonical = rtrim(str_replace('\\', '/', $dataDirReal), '/');
    if (!hash_equals($dataDir, $dataDirCanonical)) {
        throw new RuntimeException('JSON rollback data directory must be canonical.');
    }
    if ($dataDirCanonical === $projectRoot
        || str_starts_with($dataDirCanonical . '/', $projectRoot . '/')) {
        throw new RuntimeException('JSON rollback data directory must remain outside the deployed project.');
    }

    $response = [
        'ok' => true,
        'report_type' => 'mvp-14.8.6v-staging-read-only-prerequisite-check',
        'action' => 'staging_read_only_prerequisites_verified',
        'php_version' => PHP_VERSION,
        'repository_commit' => $currentCommit,
        'database_identity_fingerprint' => $databaseIdentity,
        'environment' => 'staging',
        'database_enabled' => true,
        'private_config_external' => true,
        'rollback_data_dir_external' => true,
        'evidence_approval_enabled' => false,
        'activation_enabled' => false,
        'selector_enabled' => false,
        'request_session_enabled' => false,
        'webhook_allowed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    $exitCode = 0;
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 500)
        : substr($message, 0, 500);
    $response = [
        'ok' => false,
        'report_type' => 'mvp-14.8.6v-staging-read-only-prerequisite-check',
        'action' => 'staging_read_only_prerequisites_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => $message,
        'path_exposed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
} finally {
    restore_error_handler();
}

fwrite(STDOUT, json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL);
exit($exitCode);
