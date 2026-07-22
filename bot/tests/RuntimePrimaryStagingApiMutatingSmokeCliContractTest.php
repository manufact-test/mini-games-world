<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$runner = file_get_contents($projectRoot . '/ops/runtime/run-staging-api-mutating-smoke.php');
$executor = file_get_contents($projectRoot . '/ops/runtime/execute-staging-api-mutating-smoke-request.php');
$cleanup = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup.php');
$input = file_get_contents($projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeInput.php');
$verifierCli = file_get_contents($projectRoot . '/ops/runtime/verify-staging-api-mutating-smoke-evidence.php');
foreach (compact('runner', 'executor', 'cleanup', 'input', 'verifierCli') as $label => $source) {
    if (!is_string($source)) throw new RuntimeException('API mutating smoke source is unavailable: ' . $label . '.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    '--receipt=', '--rollback-report=', '--evidence=', '--output=',
    '--expected-commit=', '--ttl-seconds=',
] as $option) {
    $assertTrue(str_contains($runner, "'{$option}'"), 'Runner option is missing: ' . $option);
}
$assertTrue(
    str_contains($runner, "if (PHP_SAPI !== 'cli')")
        && str_contains($executor, "if (PHP_SAPI !== 'cli')")
        && str_contains($verifierCli, "if (PHP_SAPI !== 'cli')"),
    'Every API mutating smoke entrypoint must remain CLI-only.'
);
$assertTrue(
    str_contains($executor, "require \$projectRoot . '/bot/api.php';")
        && str_contains($executor, "\$_SERVER['SCRIPT_FILENAME'] = \$projectRoot . '/bot/api.php';")
        && str_contains($executor, "stream_wrapper_unregister('php')")
        && str_contains($executor, "stream_wrapper_register('php'"),
    'Child executor must invoke the real API entrypoint through isolated php://input.'
);
$assertTrue(
    str_contains($input, "public const ENV_INPUT_FILE = 'MGW_STAGING_API_SMOKE_INPUT_FILE';")
        && str_contains($input, "PHP_SAPI !== 'cli'")
        && str_contains($input, "expected_payload_sha256")
        && str_contains($input, "expected_repository_commit")
        && str_contains($input, "expected_action")
        && str_contains($input, "The first staging API mutating smoke supports only bootstrap."),
    'Private input gate must bind CLI, payload, commit and exact bootstrap action.'
);

$baselineCapture = strpos($runner, '$baseline = $guard->capture();');
$childExecution = strpos($runner, '$child = executeApiChild(');
$apiCapture = strpos($runner, '$afterApi = $guard->capture();');
$restoreTransaction = strpos($runner, '$storage->transaction(static function (array &$state) use ($baselineSnapshot)');
$mappingCleanup = strpos($runner, '$identityCleanup->removeMappingsBeforeCleanupProjection(');
$cleanupTick = strpos($runner, '$cleanupTick = $worker->runOnce();');
$userCleanup = strpos($runner, '$identityCleanup->removeOrphanUserAfterCleanupProjection($mgwId);');
$finalCapture = strpos($runner, '$final = $guard->capture();');
$assertTrue(
    $baselineCapture !== false && $childExecution !== false && $apiCapture !== false
        && $restoreTransaction !== false && $mappingCleanup !== false
        && $cleanupTick !== false && $userCleanup !== false && $finalCapture !== false
        && $baselineCapture < $childExecution
        && $childExecution < $apiCapture
        && $apiCapture < $restoreTransaction
        && $restoreTransaction < $mappingCleanup
        && $mappingCleanup < $cleanupTick
        && $cleanupTick < $userCleanup
        && $userCleanup < $finalCapture,
    'Runner must commit API state, restore baseline, remove mappings, project cleanup, remove orphan user, then audit.'
);

$earlierProjectionCall = strpos(
    $cleanup,
    '$earlierProjectionProof = $this->completeEarlierProjectionEventsBeforeMappingDeletion();'
);
$deleteSession = strpos($cleanup, 'DELETE FROM mgw_sessions');
$deleteDevice = strpos($cleanup, 'DELETE FROM mgw_devices');
$deleteOwnership = strpos($cleanup, 'DELETE FROM mgw_account_ownership');
$deleteIdentity = strpos($cleanup, 'DELETE FROM mgw_identities');
$deleteUser = strpos($cleanup, 'DELETE FROM mgw_users');
$assertTrue(
    $earlierProjectionCall !== false
        && $deleteSession !== false && $deleteDevice !== false && $deleteOwnership !== false
        && $deleteIdentity !== false && $deleteUser !== false
        && $earlierProjectionCall < $deleteSession
        && $deleteSession < $deleteDevice
        && $deleteDevice < $deleteOwnership
        && $deleteOwnership < $deleteIdentity
        && $deleteIdentity < $deleteUser,
    'Earlier projections must complete before dependency-safe synthetic account deletion.'
);
$assertTrue(
    str_contains($cleanup, "status <> 'completed' AND state_revision < :current_revision")
        && str_contains($cleanup, '($tick[\'action\'] ?? \'\') !== \'projection_completed\'')
        && str_contains($cleanup, "'mapping_deleted_after_earlier_projection' => true")
        && str_contains($cleanup, 'RuntimePrimaryProjectionWorkerAdapter('),
    'Recovery cleanup must complete only earlier revisions and leave the current cleanup event for the runner.'
);
$assertTrue(
    str_contains($cleanup, "'user_row_deferred' => true")
        && str_contains($cleanup, "'identity_cleanup_complete' => true")
        && str_contains($cleanup, "'sensitive_identifiers_exposed' => false"),
    'Identity cleanup must defer user deletion until cleanup projection and expose only safe proof.'
);

foreach ([
    "'committed_api_state_write_count' => 1",
    "'committed_cleanup_state_write_count' => 1",
    "'committed_outbox_event_count' => 2",
    "'state_restored_to_baseline' => true",
    "'all_module_projection_restored' => true",
    "'synthetic_identity_cleanup_verified' => true",
    "'json_rollback_source_unchanged' => true",
] as $proof) {
    $assertTrue(str_contains($runner, $proof), 'Runner success proof is missing: ' . $proof);
}
foreach ([
    "'persistent_config_changed' => false",
    "'http_route_added' => false",
    "'webhook_allowed' => false",
    "'cron_changed' => false",
    "'production_changed' => false",
    "'sensitive_identifiers_exposed' => false",
] as $safety) {
    $assertTrue(str_contains($runner, $safety), 'Runner safety flag is missing: ' . $safety);
}
$assertTrue(
    !str_contains($runner, 'curl_')
        && !str_contains($runner, 'crontab')
        && !str_contains($runner, 'WebhookHandler.php')
        && !str_contains($executor, 'curl_')
        && !str_contains($executor, 'webhook.php'),
    'API mutating smoke must not add HTTP routes, Cron, external curl or webhook execution.'
);
$assertTrue(
    str_contains($runner, "'recovery_attempted' => \$recoveryAttempted")
        && str_contains($runner, "'recovery_verified' => \$recoveryVerified")
        && substr_count($runner, '$state = $baselineSnapshot;') >= 2,
    'Runner must contain fail-closed baseline recovery after partial execution.'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiMutatingSmokeCliContractTest passed: {$assertions} assertions.\n");
