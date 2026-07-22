<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'receipt' => '',
    'approval' => '',
    'consumed_approval' => '',
    'challenge' => '',
    'output' => '',
    'expected_commit' => '',
];
$prefixes = [
    '--receipt=' => 'receipt',
    '--approval=' => 'approval',
    '--consumed-approval=' => 'consumed_approval',
    '--challenge=' => 'challenge',
    '--output=' => 'output',
    '--expected-commit=' => 'expected_commit',
];
$seen = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    $matchedName = '';
    $matchedPrefix = '';
    foreach ($prefixes as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $matchedName = $name;
        $matchedPrefix = $prefix;
        break;
    }
    if ($matchedName === '') {
        fwrite(STDERR, "Unknown bounded mutating smoke execution argument.\n");
        exit(2);
    }
    if (isset($seen[$matchedName])) {
        fwrite(STDERR, "Bounded mutating smoke execution option may be specified only once: {$matchedPrefix}\n");
        exit(2);
    }
    $seen[$matchedName] = true;
    $values[$matchedName] = substr($argument, strlen($matchedPrefix));
}
foreach (array_keys($values) as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing bounded mutating smoke execution option: {$required}.\n");
        exit(2);
    }
}
foreach (['receipt', 'approval', 'consumed_approval', 'output'] as $pathField) {
    if (!str_starts_with($values[$pathField], '/')
        || str_contains($values[$pathField], '\\')
        || str_ends_with($values[$pathField], '/')) {
        fwrite(STDERR, "Bounded mutating smoke execution path must be exact absolute Linux: {$pathField}.\n");
        exit(2);
    }
}
if (preg_match('/\A[a-f0-9]{64}\z/', $values['challenge']) !== 1) {
    fwrite(STDERR, "Bounded mutating smoke execution challenge must be exact lowercase hexadecimal.\n");
    exit(2);
}
if (preg_match('/\A[a-f0-9]{40}\z/', $values['expected_commit']) !== 1) {
    fwrite(STDERR, "Bounded mutating smoke execution requires exact lowercase commit.\n");
    exit(2);
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Bounded mutating smoke execution requires PHP 8.3.x.\n");
    exit(2);
}

umask(0077);
set_error_handler(static function (int $severity): never {
    if (!(error_reporting() & $severity)) {
        throw new ErrorException('Suppressed bounded mutating smoke execution warning.', 0, $severity);
    }
    throw new RuntimeException('Bounded mutating smoke execution filesystem operation failed.');
});

$exitCode = 1;
$response = [];
$lockHandle = null;
$approvalConsumed = false;
$rollbackVerified = false;
$projectRoot = '';
try {
    $projectRootRaw = dirname(__DIR__, 2);
    $projectRootReal = realpath($projectRootRaw);
    if (!is_string($projectRootReal) || !is_dir($projectRootReal) || is_link($projectRootRaw)) {
        throw new RuntimeException('Bounded mutating smoke project root is unavailable.');
    }
    $projectRoot = rtrim(str_replace('\\', '/', $projectRootReal), '/');

    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeApproval.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeRollbackSignal.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingBoundedMutatingSmoke.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeRollbackGuard.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingReadOnlyCheckpointReceipt.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';

    if (($config['environment'] ?? null) !== 'staging') {
        throw new RuntimeException('Bounded mutating smoke execution is staging-only.');
    }
    $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
        is_string($configFile ?? null) ? $configFile : '',
        $projectRoot
    );
    $privateDir = (string)($private['private_dir'] ?? '');
    if ($privateDir === '' || !is_dir($privateDir) || is_link($privateDir)) {
        throw new RuntimeException('Bounded mutating smoke private directory is unavailable.');
    }

    $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot);
    if (!hash_equals($values['expected_commit'], $currentCommit)) {
        throw new RuntimeException('Bounded mutating smoke deployed checkout does not match expected commit.');
    }
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('Bounded mutating smoke requires enabled staging database config.');
    }
    $databaseIdentity = $databaseConfig->identityFingerprint();
    if (preg_match('/\A[a-f0-9]{64}\z/', $databaseIdentity) !== 1) {
        throw new RuntimeException('Bounded mutating smoke staging database identity is invalid.');
    }

    $evidenceApproval = RuntimePrimaryStagingEvidenceApproval::fromConfig($config)->safeSummary();
    $activation = RuntimePrimaryStagingActivationConfig::fromApplicationConfig($config)->safeSummary();
    $selector = RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig($config)->safeSummary();
    $session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config)->safeSummary();
    foreach ([
        'evidence approval' => $evidenceApproval['enabled'] ?? null,
        'activation' => $activation['enabled'] ?? null,
        'entrypoint selector' => $selector['enabled'] ?? null,
        'request session' => $session['enabled'] ?? null,
    ] as $label => $enabled) {
        if ($enabled !== false) {
            throw new RuntimeException('Persistent staging latch must remain disabled during mutating smoke: ' . $label . '.');
        }
    }
    if (($selector['webhook_allowed'] ?? null) !== false
        || ($session['webhook_allowed'] ?? null) !== false) {
        throw new RuntimeException('Webhook must remain forbidden during bounded mutating smoke.');
    }

    $receipt = RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
        $values['receipt'],
        $projectRoot,
        1800
    );
    if (!hash_equals($currentCommit, (string)$receipt['repository_commit'])
        || !hash_equals($databaseIdentity, (string)$receipt['database_identity_fingerprint'])) {
        throw new RuntimeException('Read-only receipt does not match current staging identity.');
    }
    $verifiedReadOnly = (new RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier(
        (string)$receipt['read_only_report_file'],
        $currentCommit,
        $databaseIdentity,
        (string)$receipt['evidence_fingerprint'],
        1800
    ))->verify();
    if (!hash_equals(
        (string)$receipt['read_only_report_sha256'],
        (string)$verifiedReadOnly['report_sha256']
    )
        || (int)$receipt['state_revision'] !== (int)$verifiedReadOnly['state_revision']
        || !hash_equals((string)$receipt['state_sha256'], (string)$verifiedReadOnly['state_sha256'])) {
        throw new RuntimeException('Read-only receipt no longer matches strict report verification.');
    }

    $approvalPath = canonicalPrivateFile($values['approval'], $projectRoot, 'Mutating smoke approval');
    if (file_exists($values['consumed_approval']) || is_link($values['consumed_approval'])) {
        throw new RuntimeException('Consumed mutating smoke approval output already exists.');
    }
    $consumedParent = realpath(dirname($values['consumed_approval']));
    if (!is_string($consumedParent)
        || !hash_equals($consumedParent . '/' . basename($values['consumed_approval']), $values['consumed_approval'])
        || !hash_equals($consumedParent, dirname($approvalPath))) {
        throw new RuntimeException('Consumed mutating smoke approval path must be canonical and colocated.');
    }
    $approvalRaw = file_get_contents($approvalPath);
    if (!is_string($approvalRaw) || strlen($approvalRaw) < 2 || strlen($approvalRaw) > 65_536) {
        throw new RuntimeException('Mutating smoke approval file size is invalid.');
    }
    try {
        $approvalPayload = json_decode($approvalRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Mutating smoke approval JSON is invalid.', 0, $error);
    }
    if (!is_array($approvalPayload) || array_is_list($approvalPayload)) {
        throw new RuntimeException('Mutating smoke approval must be a JSON object.');
    }
    $approval = RuntimePrimaryStagingMutatingSmokeApproval::fromArray($approvalPayload);
    $approval->assertApproved(
        $databaseConfig,
        $currentCommit,
        (string)$verifiedReadOnly['report_sha256'],
        (int)$verifiedReadOnly['state_revision'],
        (string)$verifiedReadOnly['state_sha256'],
        $values['challenge'],
        time()
    );

    $lockPath = $privateDir . '/runtime-primary-rehearsal.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Bounded mutating smoke lock must not be a symbolic link.');
    }
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle)) {
        throw new RuntimeException('Bounded mutating smoke lock is unavailable.');
    }
    if (!chmod($lockPath, 0600)) {
        throw new RuntimeException('Bounded mutating smoke lock permissions could not be secured.');
    }
    $lockMode = fileperms($lockPath);
    if (!is_int($lockMode) || ($lockMode & 0777) !== 0600 || is_link($lockPath)) {
        throw new RuntimeException('Bounded mutating smoke lock must have exact mode 0600.');
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another rehearsal, evidence collection or smoke is already running.');
    }

    if (!link($approvalPath, $values['consumed_approval'])) {
        throw new RuntimeException('Mutating smoke approval could not be atomically marked as consumed.');
    }
    if (!chmod($values['consumed_approval'], 0600)) {
        @unlink($values['consumed_approval']);
        throw new RuntimeException('Consumed mutating smoke approval permissions could not be secured.');
    }
    if (!unlink($approvalPath)) {
        @unlink($values['consumed_approval']);
        throw new RuntimeException('Original mutating smoke approval could not be removed after consumption.');
    }
    $approvalConsumed = true;

    $mutationDatabase = PdoConnectionFactory::create($databaseConfig);
    $verificationDatabase = PdoConnectionFactory::create($databaseConfig);
    if ($mutationDatabase === $verificationDatabase) {
        throw new RuntimeException('Bounded mutating smoke requires two independent database connections.');
    }
    $mutationProjector = (new RuntimePrimaryRepositoryProjectorFactory(
        $config,
        $mutationDatabase
    ))->create();
    $verificationProjector = (new RuntimePrimaryRepositoryProjectorFactory(
        $config,
        $verificationDatabase
    ))->create();
    $mutationAuditor = new RuntimePrimaryProjectionAuditorAdapter($mutationProjector);
    $verificationAuditor = new RuntimePrimaryProjectionAuditorAdapter($verificationProjector);
    $mutationStorage = new DatabasePrimaryStateStorageAdapter(
        $mutationDatabase,
        new RuntimePrimaryProjectionOutboxWriter()
    );
    $verificationStorage = new DatabasePrimaryStateStorageAdapter(
        $verificationDatabase,
        new RuntimePrimaryProjectionOutboxWriter()
    );
    $worker = new RuntimePrimaryProjectionWorkerAdapter(
        new RuntimePrimaryProjectionWorker($mutationDatabase, $mutationProjector, 120)
    );
    $rollbackGuard = new RuntimePrimaryStagingMutatingSmokeRollbackGuard(
        $verificationStorage,
        $verificationDatabase,
        $verificationAuditor
    );
    $baseline = $rollbackGuard->capture();
    if ((int)$baseline['state_revision'] !== (int)$receipt['state_revision']
        || !hash_equals((string)$baseline['state_sha256'], (string)$receipt['state_sha256'])) {
        throw new RuntimeException('Live DB-primary baseline moved after approval preparation.');
    }

    try {
        $coreReport = (new RuntimePrimaryStagingBoundedMutatingSmoke(
            $mutationDatabase,
            $verificationDatabase,
            $mutationStorage,
            $verificationStorage,
            $worker,
            $mutationAuditor,
            $verificationAuditor
        ))->run(
            $approval->approvalId(),
            (int)$receipt['state_revision'],
            (string)$receipt['state_sha256'],
            hash('sha256', $values['challenge'])
        );
    } catch (Throwable $smokeError) {
        $rollbackGuard->assertRestored($baseline);
        $rollbackVerified = true;
        throw new RuntimeException(
            'Bounded staging mutating smoke failed after approval consumption, but exact rollback was verified: '
            . safeMessage($smokeError->getMessage()),
            0,
            $smokeError
        );
    }
    $rollbackProof = $rollbackGuard->assertRestored($baseline);
    $rollbackVerified = true;
    if (($coreReport['ok'] ?? false) !== true
        || ($coreReport['action'] ?? '') !== RuntimePrimaryStagingBoundedMutatingSmoke::ACTION
        || ($rollbackProof['ok'] ?? false) !== true
        || ($coreReport['committed_state_write_count'] ?? null) !== 0
        || ($coreReport['committed_outbox_event_count'] ?? null) !== 0) {
        throw new RuntimeException('Bounded mutating smoke success proof is incomplete.');
    }

    $report = $coreReport + [
        'approval_contract_version' => RuntimePrimaryStagingMutatingSmokeApproval::CONTRACT_VERSION,
        'approval_consumed' => true,
        'repository_commit' => $currentCommit,
        'database_identity_fingerprint' => $databaseIdentity,
        'read_only_receipt_sha256' => (string)$receipt['receipt_sha256'],
        'read_only_report_sha256' => (string)$verifiedReadOnly['report_sha256'],
        'challenge_sha256' => hash('sha256', $values['challenge']),
        'rollback_guard_verified' => true,
    ];
    $written = (new RuntimePrimaryStagingEvidenceWriter($projectRoot))->write(
        $values['output'],
        $report
    );
    if (($written['ok'] ?? false) !== true
        || ($written['written'] ?? false) !== true
        || ($written['permissions'] ?? '') !== '0600'
        || ($written['publish_mode'] ?? '') !== 'atomic_no_clobber_link') {
        throw new RuntimeException('Bounded mutating smoke report publication proof is incomplete.');
    }

    $response = [
        'ok' => true,
        'action' => RuntimePrimaryStagingBoundedMutatingSmoke::ACTION,
        'approval_id' => $approval->approvalId(),
        'approval_consumed' => true,
        'repository_commit' => $currentCommit,
        'database_identity_fingerprint' => $databaseIdentity,
        'read_only_report_sha256' => $verifiedReadOnly['report_sha256'],
        'baseline_state_revision' => $coreReport['baseline_state_revision'],
        'mutation_state_revision' => $coreReport['mutation_state_revision'],
        'worker_tick_count' => $coreReport['worker_tick_count'],
        'temporary_state_write_count' => $coreReport['temporary_state_write_count'],
        'rollback_verified' => true,
        'committed_state_write_count' => 0,
        'committed_outbox_event_count' => 0,
        'report_file_sha256' => $written['file_sha256'],
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
    $response = [
        'ok' => false,
        'action' => 'staging_bounded_mutating_smoke_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => safeMessage($error->getMessage()),
        'approval_consumed' => $approvalConsumed,
        'rollback_verified' => $rollbackVerified,
        'committed_state_write_count' => $rollbackVerified ? 0 : null,
        'committed_outbox_event_count' => $rollbackVerified ? 0 : null,
        'persistent_config_changed' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
} finally {
    if (is_resource($lockHandle)) {
        try {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        } catch (Throwable) {
            // Process termination remains the final private lock release fallback.
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
        || !str_starts_with($path, '/') || str_ends_with($path, '/')) {
        throw new RuntimeException($label . ' path must be exact absolute Linux.');
    }
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException($label . ' must be a regular non-symlink file.');
    }
    $real = realpath($path);
    if (!is_string($real) || !hash_equals($path, $real)) {
        throw new RuntimeException($label . ' path must be canonical.');
    }
    if ($real === $projectRoot || str_starts_with($real, rtrim($projectRoot, '/') . '/')
        || preg_match('~(?:\A|/)public_html(?:/|\z)~', $real) === 1) {
        throw new RuntimeException($label . ' must remain outside the deployed project.');
    }
    $mode = fileperms($real);
    $parentMode = fileperms(dirname($real));
    if (!is_int($mode) || ($mode & 0777) !== 0600
        || !is_int($parentMode) || ($parentMode & 0022) !== 0) {
        throw new RuntimeException($label . ' permissions are unsafe.');
    }
    return $real;
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
