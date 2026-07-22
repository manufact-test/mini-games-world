<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'receipt' => '',
    'rollback_report' => '',
    'evidence' => '',
    'output' => '',
    'expected_commit' => '',
    'ttl' => '300',
];
$prefixes = [
    '--receipt=' => 'receipt',
    '--rollback-report=' => 'rollback_report',
    '--evidence=' => 'evidence',
    '--output=' => 'output',
    '--expected-commit=' => 'expected_commit',
    '--ttl-seconds=' => 'ttl',
];
$seen = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    $matched = '';
    $prefixMatched = '';
    foreach ($prefixes as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $matched = $name;
        $prefixMatched = $prefix;
        break;
    }
    if ($matched === '') {
        fwrite(STDERR, "Unknown staging API mutating smoke argument.\n");
        exit(2);
    }
    if (isset($seen[$matched])) {
        fwrite(STDERR, "Staging API mutating smoke option may be specified only once: {$prefixMatched}\n");
        exit(2);
    }
    $seen[$matched] = true;
    $values[$matched] = substr($argument, strlen($prefixMatched));
}
foreach (['receipt', 'rollback_report', 'evidence', 'output', 'expected_commit'] as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing staging API mutating smoke option: {$required}.\n");
        exit(2);
    }
}
foreach (['receipt', 'rollback_report', 'evidence', 'output'] as $pathField) {
    if (!str_starts_with($values[$pathField], '/')
        || str_contains($values[$pathField], '\\')
        || str_ends_with($values[$pathField], '/')) {
        fwrite(STDERR, "Staging API mutating smoke path must be exact absolute Linux: {$pathField}.\n");
        exit(2);
    }
}
if (preg_match('/\A[a-f0-9]{40}\z/', $values['expected_commit']) !== 1) {
    fwrite(STDERR, "Staging API mutating smoke requires an exact lowercase commit.\n");
    exit(2);
}
if ($values['ttl'] === '' || preg_match('/\A\d+\z/', $values['ttl']) !== 1) {
    fwrite(STDERR, "Staging API mutating smoke TTL must be an integer.\n");
    exit(2);
}
$ttl = (int)$values['ttl'];
if ($ttl < 60 || $ttl > 600) {
    fwrite(STDERR, "Staging API mutating smoke TTL must be between 60 and 600 seconds.\n");
    exit(2);
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Staging API mutating smoke requires PHP 8.3.x.\n");
    exit(2);
}

umask(0077);
set_error_handler(static function (int $severity): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed staging API mutating smoke warning.', 0, $severity);
    }
    throw new RuntimeException('Staging API mutating smoke filesystem operation failed.');
});

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
$exitCode = 1;
$response = [];
$lockHandle = null;
$temporaryFiles = [];
$database = null;
$storage = null;
$worker = null;
$auditor = null;
$baselineSnapshot = null;
$baseline = null;
$provider = 'telegram';
$subject = '';
$sessionId = '';
$mgwId = '';
$identityCleanup = null;
$recoveryAttempted = false;
$recoveryVerified = false;

try {
    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingReadOnlyCheckpointReceipt.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeInput.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';

    if (($config['environment'] ?? null) !== 'staging') {
        throw new RuntimeException('Staging API mutating smoke is staging-only.');
    }
    $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
        is_string($configFile ?? null) ? $configFile : '',
        $projectRoot
    );
    $privateDir = (string)($private['private_dir'] ?? '');
    if ($privateDir === '' || !is_dir($privateDir) || is_link($privateDir)) {
        throw new RuntimeException('Staging API mutating smoke private directory is unavailable.');
    }
    $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot);
    if (!hash_equals($values['expected_commit'], $currentCommit)) {
        throw new RuntimeException('Staging API mutating smoke checkout does not match expected commit.');
    }

    foreach ([
        'activation' => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($config)->safeSummary()['enabled'] ?? null,
        'selector' => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig($config)->enabled(),
        'request session' => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config)->enabled(),
    ] as $label => $enabled) {
        if ($enabled !== false) {
            throw new RuntimeException('Persistent staging latch must remain disabled during API mutating smoke: ' . $label . '.');
        }
    }

    $receipt = RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
        canonicalPrivateFile($values['receipt'], $projectRoot, 'Read-only receipt'),
        $projectRoot,
        3600
    );
    if (!hash_equals($currentCommit, (string)$receipt['repository_commit'])) {
        throw new RuntimeException('Read-only receipt belongs to a different checkout.');
    }

    $rollbackPath = canonicalPrivateFile(
        $values['rollback_report'],
        $projectRoot,
        'Rollback-only mutating smoke report'
    );
    $rollbackRaw = file_get_contents($rollbackPath);
    if (!is_string($rollbackRaw) || strlen($rollbackRaw) < 2 || strlen($rollbackRaw) > 131_072) {
        throw new RuntimeException('Rollback-only mutating smoke report size is invalid.');
    }
    try {
        $rollbackCandidate = json_decode($rollbackRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Rollback-only mutating smoke report JSON is invalid.', 0, $error);
    }
    $approvalId = is_array($rollbackCandidate) ? ($rollbackCandidate['approval_id'] ?? null) : null;
    if (!is_string($approvalId) || preg_match('/\A[a-f0-9]{64}\z/', $approvalId) !== 1) {
        throw new RuntimeException('Rollback-only mutating smoke approval identity is invalid.');
    }
    $rollbackVerified = (new RuntimePrimaryStagingBoundedMutatingSmokeEvidenceVerifier(
        $rollbackPath,
        $currentCommit,
        (string)$receipt['database_identity_fingerprint'],
        (string)$receipt['read_only_report_sha256'],
        (string)$receipt['receipt_sha256'],
        $approvalId,
        3600
    ))->verify();
    if (($rollbackVerified['ok'] ?? false) !== true
        || ($rollbackVerified['rollback_verified'] ?? false) !== true
        || ($rollbackVerified['committed_state_write_count'] ?? null) !== 0
        || ($rollbackVerified['committed_outbox_event_count'] ?? null) !== 0) {
        throw new RuntimeException('Rollback-only mutating smoke prerequisite is incomplete.');
    }

    $evidencePath = canonicalPrivateFile($values['evidence'], $projectRoot, 'Lifecycle evidence v4');
    $overlayResult = (new RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(
        $projectRoot,
        $config,
        (string)$configFile,
        $evidencePath,
        $ttl
    ))->build(time());
    $overlay = is_array($overlayResult['config'] ?? null) ? $overlayResult['config'] : [];
    $overlayProof = is_array($overlayResult['report'] ?? null) ? $overlayResult['report'] : [];
    if ($overlay === []
        || ($overlayProof['ok'] ?? false) !== true
        || ($overlayProof['api_only'] ?? false) !== true
        || ($overlayProof['webhook_allowed'] ?? null) !== false
        || ($overlayProof['persistent_config_changed'] ?? null) !== false) {
        throw new RuntimeException('Staging API mutating smoke in-memory overlay proof is incomplete.');
    }

    $lockPath = $privateDir . '/runtime-primary-rehearsal.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Staging API mutating smoke lock must not be a symbolic link.');
    }
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle) || !chmod($lockPath, 0600)) {
        throw new RuntimeException('Staging API mutating smoke lock is unavailable.');
    }
    clearstatcache(true, $lockPath);
    $lockMode = fileperms($lockPath);
    if (!is_int($lockMode) || ($lockMode & 0777) !== 0600
        || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another rehearsal, collector or smoke is already running.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()
        || !hash_equals(
            (string)$receipt['database_identity_fingerprint'],
            $databaseConfig->identityFingerprint()
        )) {
        throw new RuntimeException('Staging API mutating smoke database identity does not match receipt.');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $verificationDatabase = PdoConnectionFactory::create($databaseConfig);
    if ($database === $verificationDatabase) {
        throw new RuntimeException('Staging API mutating smoke requires independent database connections.');
    }
    $projector = (new RuntimePrimaryRepositoryProjectorFactory($config, $database))->create();
    $verificationProjector = (new RuntimePrimaryRepositoryProjectorFactory($config, $verificationDatabase))->create();
    $auditor = new RuntimePrimaryProjectionAuditorAdapter($verificationProjector);
    $storage = new DatabasePrimaryStateStorageAdapter(
        $database,
        new RuntimePrimaryProjectionOutboxWriter()
    );
    $verificationStorage = new DatabasePrimaryStateStorageAdapter(
        $verificationDatabase,
        new RuntimePrimaryProjectionOutboxWriter()
    );
    $worker = new RuntimePrimaryProjectionWorkerAdapter(
        new RuntimePrimaryProjectionWorker($database, $projector, 60)
    );
    $guard = new RuntimePrimaryStagingMutatingSmokeRollbackGuard(
        $verificationStorage,
        $verificationDatabase,
        $auditor
    );
    $baseline = $guard->capture();
    if ((int)$baseline['state_revision'] !== (int)$receipt['state_revision']
        || !hash_equals((string)$baseline['state_sha256'], (string)$receipt['state_sha256'])) {
        throw new RuntimeException('Live staging baseline moved after the read-only checkpoint.');
    }
    $baselineSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
    if (!is_array($baselineSnapshot)) {
        throw new RuntimeException('Staging API mutating smoke baseline snapshot is unavailable.');
    }
    $jsonFingerprintBefore = directoryFingerprint((string)($config['data_dir'] ?? ''), $projectRoot);

    $subject = '9' . decimalDigits(17);
    $sessionId = bin2hex(random_bytes(32));
    if (isset($baselineSnapshot['users'][$subject])) {
        throw new RuntimeException('Staging API mutating smoke generated a colliding synthetic user.');
    }
    $identityCleanup = new RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup($verificationDatabase);
    $identityCleanup->assertFresh($provider, $subject, $subject, $sessionId);

    $payload = signedBootstrapPayload($config, $subject, $sessionId);
    $payloadRaw = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $runId = gmdate('Ymd\THis\Z') . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $inputFile = $privateDir . '/staging-api-mutating-smoke-input-' . $runId . '.json';
    $overlayFile = $privateDir . '/staging-api-mutating-smoke-overlay-' . $runId . '.php';
    foreach ([$inputFile, $overlayFile] as $path) {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Fresh staging API mutating smoke temporary path already exists.');
        }
        $temporaryFiles[] = $path;
    }
    if (file_put_contents($inputFile, $payloadRaw, LOCK_EX) !== strlen($payloadRaw)
        || !chmod($inputFile, 0600)) {
        throw new RuntimeException('Staging API mutating smoke input could not be published safely.');
    }

    $overlay['staging_api_mutating_smoke_input'] = [
        'contract_version' => RuntimePrimaryStagingApiMutatingSmokeInput::CONTRACT_VERSION,
        'enabled' => true,
        'expected_action' => 'bootstrap',
        'expected_payload_sha256' => hash('sha256', $payloadRaw),
        'expected_repository_commit' => $currentCommit,
        'expires_at_utc' => gmdate(DATE_ATOM, time() + $ttl),
    ];
    $overlayPhp = "<?php\ndeclare(strict_types=1);\nreturn "
        . var_export($overlay, true)
        . ";\n";
    if (file_put_contents($overlayFile, $overlayPhp, LOCK_EX) !== strlen($overlayPhp)
        || !chmod($overlayFile, 0600)) {
        throw new RuntimeException('Staging API mutating smoke overlay could not be published safely.');
    }

    $child = executeApiChild(
        $projectRoot . '/ops/runtime/execute-staging-api-mutating-smoke-request.php',
        $overlayFile,
        $inputFile,
        60
    );
    if (($child['exit_code'] ?? -1) !== 0) {
        throw new RuntimeException('Real staging API mutating smoke child process failed.');
    }
    try {
        $apiResponse = json_decode((string)$child['stdout'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Real staging API mutating smoke response JSON is invalid.', 0, $error);
    }
    if (!is_array($apiResponse)
        || ($apiResponse['ok'] ?? null) !== true
        || !is_array($apiResponse['user'] ?? null)
        || !hash_equals($subject, (string)($apiResponse['user']['id'] ?? ''))) {
        throw new RuntimeException('Real staging API mutating smoke response identity is invalid.');
    }

    $afterApi = $guard->capture();
    if ((int)$afterApi['state_revision'] !== (int)$baseline['state_revision'] + 1
        || hash_equals((string)$afterApi['state_sha256'], (string)$baseline['state_sha256'])
        || (int)$afterApi['outbox_event_count'] !== (int)$baseline['outbox_event_count'] + 1) {
        throw new RuntimeException('Real staging API mutation did not produce exactly one completed revision.');
    }
    $afterApiSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
    if (!is_array($afterApiSnapshot)
        || !isset($afterApiSnapshot['users'][$subject])
        || !is_array($afterApiSnapshot['users'][$subject])) {
        throw new RuntimeException('Real staging API mutation did not persist the synthetic user in DB-primary state.');
    }
    $mgwId = $identityCleanup->resolveCreatedMgwId($provider, $subject, $subject);

    $storage->transaction(static function (array &$state) use ($baselineSnapshot): null {
        $state = $baselineSnapshot;
        return null;
    });
    $cleanupStatus = $storage->status();
    if ((int)($cleanupStatus['revision'] ?? 0) !== (int)$baseline['state_revision'] + 2
        || !hash_equals((string)$baseline['state_sha256'], (string)($cleanupStatus['state_sha256'] ?? ''))) {
        throw new RuntimeException('Staging API mutating smoke cleanup revision did not restore baseline state.');
    }

    $mappingCleanup = $identityCleanup->removeMappingsBeforeCleanupProjection(
        $provider,
        $subject,
        $subject,
        $sessionId,
        $mgwId
    );
    $cleanupTick = $worker->runOnce();
    if (($cleanupTick['ok'] ?? false) !== true
        || ($cleanupTick['action'] ?? '') !== 'projection_completed'
        || ($cleanupTick['claimed'] ?? false) !== true
        || (int)($cleanupTick['state_revision'] ?? 0) !== (int)$baseline['state_revision'] + 2
        || ($cleanupTick['parity_ok'] ?? false) !== true) {
        throw new RuntimeException('Staging API mutating smoke cleanup projection did not complete exactly once.');
    }
    $userCleanup = $identityCleanup->removeOrphanUserAfterCleanupProjection($mgwId);
    $identityProof = $identityCleanup->assertAbsent(
        $provider,
        $subject,
        $subject,
        $sessionId,
        $mgwId
    );

    $final = $guard->capture();
    $finalSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
    $jsonFingerprintAfter = directoryFingerprint((string)($config['data_dir'] ?? ''), $projectRoot);
    if (!is_array($finalSnapshot)
        || !hash_equals(
            hash('sha256', canonicalJson($baselineSnapshot)),
            hash('sha256', canonicalJson($finalSnapshot))
        )
        || (int)$final['state_revision'] !== (int)$baseline['state_revision'] + 2
        || !hash_equals((string)$final['state_sha256'], (string)$baseline['state_sha256'])
        || !hash_equals((string)$final['snapshot_sha256'], (string)$baseline['snapshot_sha256'])
        || !hash_equals((string)$final['all_module_fingerprint'], (string)$baseline['all_module_fingerprint'])
        || (int)$final['outbox_event_count'] !== (int)$baseline['outbox_event_count'] + 2
        || isset($finalSnapshot['users'][$subject])
        || !hash_equals($jsonFingerprintBefore, $jsonFingerprintAfter)
        || ($identityProof['ok'] ?? false) !== true) {
        throw new RuntimeException('Staging API mutating smoke final restoration proof is incomplete.');
    }

    $report = [
        'ok' => true,
        'report_type' => 'mvp-14.9b-staging-api-mutating-smoke',
        'action' => 'staging_api_mutation_committed_projected_cleaned_and_verified',
        'repository_commit' => $currentCommit,
        'database_identity_fingerprint' => (string)$receipt['database_identity_fingerprint'],
        'read_only_receipt_sha256' => (string)$receipt['receipt_sha256'],
        'rollback_smoke_report_sha256' => hash('sha256', $rollbackRaw),
        'lifecycle_evidence_fingerprint' => (string)$overlayProof['evidence_fingerprint'],
        'baseline_state_revision' => (int)$baseline['state_revision'],
        'baseline_state_sha256' => (string)$baseline['state_sha256'],
        'baseline_snapshot_sha256' => (string)$baseline['snapshot_sha256'],
        'baseline_outbox_event_count' => (int)$baseline['outbox_event_count'],
        'baseline_outbox_fingerprint' => (string)$baseline['outbox_fingerprint'],
        'baseline_all_module_fingerprint' => (string)$baseline['all_module_fingerprint'],
        'api_action' => 'bootstrap',
        'api_child_exit_code' => 0,
        'api_response_ok' => true,
        'api_user_id_sha256' => hash('sha256', $subject),
        'api_state_revision' => (int)$afterApi['state_revision'],
        'api_state_sha256' => (string)$afterApi['state_sha256'],
        'api_outbox_event_count' => (int)$afterApi['outbox_event_count'],
        'api_projection_completed' => true,
        'api_all_module_audit_passed' => true,
        'cleanup_state_revision' => (int)$final['state_revision'],
        'cleanup_state_sha256' => (string)$final['state_sha256'],
        'cleanup_worker_tick_count' => 1,
        'cleanup_projection_completed' => true,
        'mapping_session_rows_deleted' => (int)$mappingCleanup['session_rows_deleted'],
        'mapping_device_rows_deleted' => (int)$mappingCleanup['device_rows_deleted'],
        'mapping_ownership_rows_deleted' => (int)$mappingCleanup['ownership_rows_deleted'],
        'mapping_identity_rows_deleted' => (int)$mappingCleanup['identity_rows_deleted'],
        'orphan_user_rows_deleted' => (int)$userCleanup['user_rows_deleted'],
        'final_state_revision' => (int)$final['state_revision'],
        'final_state_sha256' => (string)$final['state_sha256'],
        'final_snapshot_sha256' => (string)$final['snapshot_sha256'],
        'final_outbox_event_count' => (int)$final['outbox_event_count'],
        'final_outbox_fingerprint' => (string)$final['outbox_fingerprint'],
        'final_all_module_fingerprint' => (string)$final['all_module_fingerprint'],
        'state_restored_to_baseline' => true,
        'snapshot_restored_to_baseline' => true,
        'all_module_projection_restored' => true,
        'synthetic_state_user_absent' => true,
        'synthetic_identity_cleanup_verified' => true,
        'json_rollback_source_unchanged' => true,
        'committed_api_state_write_count' => 1,
        'committed_cleanup_state_write_count' => 1,
        'committed_outbox_event_count' => 2,
        'input_enabled_in_memory_only' => true,
        'selector_enabled_in_memory_only' => true,
        'request_session_enabled_in_memory_only' => true,
        'activation_enabled_in_memory_only' => true,
        'persistent_config_changed' => false,
        'http_route_added' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'recovery_attempted' => false,
        'recovery_verified' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    $written = (new RuntimePrimaryStagingEvidenceWriter($projectRoot))->write(
        $values['output'],
        $report
    );
    if (($written['ok'] ?? false) !== true
        || ($written['written'] ?? false) !== true
        || ($written['permissions'] ?? '') !== '0600'
        || ($written['publish_mode'] ?? '') !== 'atomic_no_clobber_link') {
        throw new RuntimeException('Staging API mutating smoke report publication proof is incomplete.');
    }

    $response = [
        'ok' => true,
        'action' => 'staging_api_mutating_smoke_passed',
        'repository_commit' => $currentCommit,
        'baseline_state_revision' => (int)$baseline['state_revision'],
        'api_state_revision' => (int)$afterApi['state_revision'],
        'final_state_revision' => (int)$final['state_revision'],
        'committed_api_state_write_count' => 1,
        'committed_cleanup_state_write_count' => 1,
        'committed_outbox_event_count' => 2,
        'state_restored_to_baseline' => true,
        'all_module_projection_restored' => true,
        'synthetic_identity_cleanup_verified' => true,
        'json_rollback_source_unchanged' => true,
        'report_file_sha256' => (string)$written['file_sha256'],
        'report_permissions' => '0600',
        'persistent_config_changed' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
    $exitCode = 0;
} catch (Throwable $error) {
    if ($database instanceof DatabaseConnectionInterface
        && $storage instanceof DatabasePrimaryStateStorageAdapter
        && $worker instanceof RuntimePrimaryProjectionWorkerInterface
        && is_array($baselineSnapshot)
        && is_array($baseline)) {
        $recoveryAttempted = true;
        try {
            $current = $storage->status();
            $currentSnapshot = $storage->readOnly(static fn(array $state): array => $state);
            if (!is_array($currentSnapshot)
                || !hash_equals(
                    hash('sha256', canonicalJson($baselineSnapshot)),
                    hash('sha256', canonicalJson($currentSnapshot))
                )) {
                $storage->transaction(static function (array &$state) use ($baselineSnapshot): null {
                    $state = $baselineSnapshot;
                    return null;
                });
            }
            if ($identityCleanup instanceof RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup
                && $subject !== '') {
                if ($mgwId === '') {
                    try {
                        $mgwId = $identityCleanup->resolveCreatedMgwId($provider, $subject, $subject);
                    } catch (Throwable) {
                        $mgwId = '';
                    }
                }
                if ($mgwId !== '') {
                    try {
                        $identityCleanup->removeMappingsBeforeCleanupProjection(
                            $provider,
                            $subject,
                            $subject,
                            $sessionId,
                            $mgwId
                        );
                    } catch (Throwable) {
                    }
                }
            }
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $status = $storage->status();
                $revision = (int)($status['revision'] ?? 0);
                $eventStatus = (string)$database->fetchValue(
                    'SELECT status FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
                    . ' WHERE state_revision = :state_revision',
                    ['state_revision' => $revision]
                );
                if ($eventStatus === 'completed') break;
                $worker->runOnce();
            }
            if ($identityCleanup instanceof RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup
                && $mgwId !== '') {
                try {
                    $identityCleanup->removeOrphanUserAfterCleanupProjection($mgwId);
                } catch (Throwable) {
                }
                $identityCleanup->assertAbsent(
                    $provider,
                    $subject,
                    $subject,
                    $sessionId,
                    $mgwId
                );
            }
            if ($auditor instanceof RuntimePrimaryProjectionAuditorInterface) {
                $verificationDatabase = PdoConnectionFactory::create(
                    DatabaseConfig::fromApplicationConfig($config)
                );
                $verificationStorage = new DatabasePrimaryStateStorageAdapter(
                    $verificationDatabase,
                    new RuntimePrimaryProjectionOutboxWriter()
                );
                $finalSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
                $finalStatus = $verificationStorage->status();
                $audit = $auditor->auditOnly(
                    $finalSnapshot,
                    (int)$finalStatus['revision'],
                    (string)$finalStatus['state_sha256']
                );
                $recoveryVerified = is_array($finalSnapshot)
                    && hash_equals(
                        hash('sha256', canonicalJson($baselineSnapshot)),
                        hash('sha256', canonicalJson($finalSnapshot))
                    )
                    && hash_equals((string)$baseline['state_sha256'], (string)$finalStatus['state_sha256'])
                    && ($audit['ok'] ?? false) === true
                    && ($audit['parity_ok'] ?? false) === true;
            }
        } catch (Throwable) {
            $recoveryVerified = false;
        }
    }
    $response = [
        'ok' => false,
        'action' => 'staging_api_mutating_smoke_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => safeMessage($error->getMessage()),
        'recovery_attempted' => $recoveryAttempted,
        'recovery_verified' => $recoveryVerified,
        'persistent_config_changed' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
} finally {
    foreach ($temporaryFiles as $path) {
        if (is_string($path) && $path !== '' && is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }
    if (is_resource($lockHandle)) {
        try {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        } catch (Throwable) {
        }
    }
    restore_error_handler();
}

fwrite(STDOUT, json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL);
exit($exitCode);

function canonicalPrivateFile(string $path, string $projectRoot, string $label): string
{
    if ($path === '' || trim($path) !== $path || str_contains($path, '\\')
        || !str_starts_with($path, '/') || str_ends_with($path, '/')
        || is_link($path) || !is_file($path)) {
        throw new RuntimeException($label . ' must be a regular exact absolute Linux file.');
    }
    $real = realpath($path);
    if (!is_string($real) || !hash_equals($path, $real)) {
        throw new RuntimeException($label . ' path must be canonical.');
    }
    if ($real === $projectRoot || str_starts_with($real, $projectRoot . '/')
        || preg_match('~(?:\A|/)public_html(?:/|\z)~', $real) === 1) {
        throw new RuntimeException($label . ' must remain outside the deployed project.');
    }
    $parent = dirname($real);
    clearstatcache(true, $real);
    clearstatcache(true, $parent);
    $mode = fileperms($real);
    $parentMode = fileperms($parent);
    if (!is_int($mode) || ($mode & 0777) !== 0600
        || !is_int($parentMode) || ($parentMode & 0022) !== 0) {
        throw new RuntimeException($label . ' permissions are unsafe.');
    }
    return $real;
}

function signedBootstrapPayload(array $config, string $subject, string $sessionId): array
{
    $botToken = $config['bot_token'] ?? null;
    if (!is_string($botToken) || strlen($botToken) < 20 || strlen($botToken) > 256) {
        throw new RuntimeException('Staging API mutating smoke bot token is unavailable.');
    }
    $user = json_encode([
        'id' => $subject,
        'first_name' => 'MGW API Smoke',
        'username' => 'mgw_smoke_' . substr(hash('sha256', $subject), 0, 12),
        'language_code' => 'ru',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $init = [
        'auth_date' => (string)time(),
        'query_id' => bin2hex(random_bytes(16)),
        'user' => $user,
    ];
    ksort($init, SORT_STRING);
    $parts = [];
    foreach ($init as $key => $value) $parts[] = $key . '=' . $value;
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $init['hash'] = hash_hmac('sha256', implode("\n", $parts), $secret);
    return [
        'action' => 'bootstrap',
        'sessionId' => $sessionId,
        'initData' => http_build_query($init, '', '&', PHP_QUERY_RFC3986),
    ];
}

function executeApiChild(string $script, string $overlayFile, string $inputFile, int $timeoutSeconds): array
{
    if (!is_file($script) || is_link($script)) {
        throw new RuntimeException('Staging API mutating smoke child executor is unavailable.');
    }
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['MGW_CONFIG_FILE'] = $overlayFile;
    $environment[RuntimePrimaryStagingApiMutatingSmokeInput::ENV_INPUT_FILE] = $inputFile;
    unset($environment['MGW_DATABASE_CONFIG_FILE'], $environment['MGW_REHEARSAL_COMMIT_SHA']);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Staging API mutating smoke child process could not start.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $timedOut = false;
    while (true) {
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        if (strlen($stdout) > 1_048_576 || strlen($stderr) > 65_536) {
            proc_terminate($process, 9);
            throw new RuntimeException('Staging API mutating smoke child output exceeded safe bounds.');
        }
        $status = proc_get_status($process);
        if (!is_array($status) || ($status['running'] ?? false) !== true) break;
        if (microtime(true) - $started > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process, 15);
            usleep(200_000);
            $status = proc_get_status($process);
            if (is_array($status) && ($status['running'] ?? false) === true) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(20_000);
    }
    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($timedOut) {
        throw new RuntimeException('Staging API mutating smoke child process timed out.');
    }
    if (!is_int($exit)) $exit = -1;
    return ['exit_code' => $exit, 'stdout' => $stdout, 'stderr_sha256' => hash('sha256', $stderr)];
}

function directoryFingerprint(string $directory, string $projectRoot): string
{
    if ($directory === '' || trim($directory) !== $directory || str_contains($directory, '\\')
        || !str_starts_with($directory, '/') || is_link($directory) || !is_dir($directory)) {
        throw new RuntimeException('JSON rollback directory is unavailable or unsafe.');
    }
    $real = realpath($directory);
    if (!is_string($real) || !hash_equals($real, $directory)
        || $real === $projectRoot || str_starts_with($real, $projectRoot . '/')) {
        throw new RuntimeException('JSON rollback directory must be canonical and external.');
    }
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) continue;
        $path = str_replace('\\', '/', $file->getPathname());
        if ($file->isLink()) {
            throw new RuntimeException('JSON rollback directory contains a symbolic link.');
        }
        if (!$file->isFile()) continue;
        $relative = ltrim(substr($path, strlen($real)), '/');
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('JSON rollback file fingerprint could not be calculated.');
        }
        $entries[$relative] = ['size' => $file->getSize(), 'sha256' => $hash];
        if (count($entries) > 10_000) {
            throw new RuntimeException('JSON rollback directory contains too many files.');
        }
    }
    ksort($entries, SORT_STRING);
    return hash('sha256', canonicalJson($entries));
}

function canonicalJson(array $value): string
{
    return json_encode(
        canonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}

function canonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = canonicalize($item);
    return $value;
}

function decimalDigits(int $count): string
{
    if ($count < 1 || $count > 32) {
        throw new InvalidArgumentException('Decimal random length is invalid.');
    }
    $digits = '';
    while (strlen($digits) < $count) {
        foreach (str_split(bin2hex(random_bytes(16))) as $character) {
            $value = hexdec($character);
            if ($value < 10) $digits .= (string)$value;
            if (strlen($digits) === $count) break;
        }
    }
    return $digits;
}

function safeMessage(string $message): string
{
    $message = preg_replace(
        "~/(?:home|var|tmp|srv|opt)/[^\\s'\"]+~",
        '[private-path]',
        trim($message)
    ) ?? trim($message);
    return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
}
