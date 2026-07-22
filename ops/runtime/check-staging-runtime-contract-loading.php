<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
$currentContract = 'application_bootstrap';
$response = [];
$exitCode = 1;

set_error_handler(static function (int $severity) use (&$currentContract): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed runtime contract warning at ' . $currentContract . '.', 0, $severity);
    }
    throw new RuntimeException('Runtime contract filesystem operation failed at ' . $currentContract . '.');
});

try {
    require $projectRoot . '/bot/core/bootstrap.php';

    $contracts = [
        'projection_outbox_schema_installer' => '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php',
        'projection_outbox_writer' => '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php',
        'projection_bootstrap' => '/bot/runtime/RuntimePrimaryProjectionBootstrap.php',
        'projection_worker' => '/bot/runtime/RuntimePrimaryProjectionWorker.php',
        'rehearsal_backend_interface' => '/bot/runtime/RuntimePrimaryRehearsalBackendInterface.php',
        'staging_rehearsal_backend' => '/bot/runtime/RuntimePrimaryStagingRehearsalBackend.php',
        'staging_rehearsal_operation' => '/bot/runtime/StagingPrimaryRehearsalOperation.php',
        'entrypoint_evidence' => '/bot/runtime/RuntimePrimaryEntrypointEvidence.php',
        'repository_commit_resolver' => '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php',
        'staging_evidence_verifier' => '/bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php',
        'staging_evidence_v2_verifier' => '/bot/runtime/RuntimePrimaryStagingEvidenceV2Verifier.php',
        'staging_evidence_v2_gate' => '/bot/runtime/RuntimePrimaryStagingEvidenceV2Gate.php',
        'staging_entrypoint_selector_config' => '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php',
        'staging_selector_evidence' => '/bot/runtime/RuntimePrimaryStagingSelectorEvidence.php',
        'staging_evidence_v3_verifier' => '/bot/runtime/RuntimePrimaryStagingEvidenceV3Verifier.php',
        'staging_evidence_v3_gate' => '/bot/runtime/RuntimePrimaryStagingEvidenceV3Gate.php',
        'projection_worker_interface' => '/bot/runtime/RuntimePrimaryProjectionWorkerInterface.php',
        'projection_auditor_interface' => '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php',
        'projection_worker_adapter' => '/bot/runtime/RuntimePrimaryProjectionWorkerAdapter.php',
        'projection_auditor_adapter' => '/bot/runtime/RuntimePrimaryProjectionAuditorAdapter.php',
        'staging_request_session_config' => '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php',
        'staging_request_finalizer' => '/bot/runtime/RuntimePrimaryStagingRequestFinalizer.php',
        'staging_request_session_readiness' => '/bot/runtime/RuntimePrimaryStagingRequestSessionReadiness.php',
        'staging_api_request_finalization_hook' => '/bot/runtime/RuntimePrimaryStagingApiRequestFinalizationHook.php',
        'staging_api_session_coordinator' => '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php',
        'staging_request_lifecycle_evidence' => '/bot/runtime/RuntimePrimaryStagingRequestLifecycleEvidence.php',
        'staging_evidence_v4_verifier' => '/bot/runtime/RuntimePrimaryStagingEvidenceV4Verifier.php',
        'staging_evidence_v4_gate' => '/bot/runtime/RuntimePrimaryStagingEvidenceV4Gate.php',
        'staging_evidence_gate' => '/bot/runtime/RuntimePrimaryStagingEvidenceGate.php',
        'json_evidence' => '/bot/runtime/RuntimePrimaryJsonEvidence.php',
        'staging_concurrency_probe' => '/bot/runtime/RuntimePrimaryStagingConcurrencyProbe.php',
        'staging_evidence_approval' => '/bot/runtime/RuntimePrimaryStagingEvidenceApproval.php',
        'private_config_guard' => '/bot/runtime/RuntimePrimaryPrivateConfigGuard.php',
        'staging_evidence_source_interface' => '/bot/runtime/RuntimePrimaryStagingEvidenceSourceInterface.php',
        'staging_evidence_source' => '/bot/runtime/RuntimePrimaryStagingEvidenceSource.php',
        'staging_evidence_collector' => '/bot/runtime/RuntimePrimaryStagingEvidenceCollector.php',
        'staging_selector_evidence_collector' => '/bot/runtime/RuntimePrimaryStagingSelectorEvidenceCollector.php',
        'staging_lifecycle_evidence_collector' => '/bot/runtime/RuntimePrimaryStagingLifecycleEvidenceCollector.php',
    ];

    foreach ($contracts as $label => $relativePath) {
        $currentContract = $label;
        $path = $projectRoot . $relativePath;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('Runtime contract is unavailable at ' . $label . '.');
        }
        require_once $path;
    }

    $currentContract = 'completed';
    $response = [
        'ok' => true,
        'action' => 'staging_runtime_contract_loading_verified',
        'contract_count' => count($contracts),
        'path_exposed' => false,
        'database_contacted' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
    ];
    $exitCode = 0;
} catch (Throwable $error) {
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($error->getMessage())
    ) ?? trim($error->getMessage());
    $message = function_exists('mb_substr') ? mb_substr($message, 0, 300) : substr($message, 0, 300);

    $response = [
        'ok' => false,
        'action' => 'staging_runtime_contract_loading_blocked_or_failed',
        'failure_contract' => $currentContract,
        'error_class' => get_class($error),
        'error_message' => $message,
        'path_exposed' => false,
        'database_contacted' => false,
        'application_entrypoints_changed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
    ];
} finally {
    restore_error_handler();
}

fwrite(STDOUT, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
exit($exitCode);
