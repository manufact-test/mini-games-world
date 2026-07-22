<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Staging API incident recovery requires PHP 8.3.x.\n");
    exit(2);
}

const MGW_API_INCIDENT_COMMIT = 'ad2a409d8f979a4b79b568efd6b81c5947659aaa';
const MGW_API_INCIDENT_ERROR = 'Legacy economy delta is not ready: Database contains a balance outside the frozen economy source.';

$values = ['receipt' => '', 'output' => '', 'expected_commit' => ''];
$prefixes = [
    '--receipt=' => 'receipt',
    '--output=' => 'output',
    '--expected-commit=' => 'expected_commit',
];
$seen = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    $matched = '';
    foreach ($prefixes as $prefix => $name) {
        if (!str_starts_with($argument, $prefix)) continue;
        $matched = $name;
        if (isset($seen[$name])) {
            fwrite(STDERR, "Recovery option may be specified only once.\n");
            exit(2);
        }
        $seen[$name] = true;
        $values[$name] = substr($argument, strlen($prefix));
        break;
    }
    if ($matched === '') {
        fwrite(STDERR, "Unknown staging API incident recovery argument.\n");
        exit(2);
    }
}
foreach (array_keys($values) as $required) {
    if (!isset($seen[$required]) || $values[$required] === '') {
        fwrite(STDERR, "Missing staging API incident recovery option: {$required}.\n");
        exit(2);
    }
}
if (preg_match('/\A[a-f0-9]{40}\z/', $values['expected_commit']) !== 1) {
    fwrite(STDERR, "Recovery expected commit is invalid.\n");
    exit(2);
}
foreach (['receipt', 'output'] as $field) {
    $path = $values[$field];
    if (!str_starts_with($path, '/') || str_contains($path, '\\') || str_ends_with($path, '/')) {
        fwrite(STDERR, "Recovery path must be exact absolute Linux: {$field}.\n");
        exit(2);
    }
}

umask(0077);
$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
$lockHandle = null;
$exitCode = 1;
$response = [];

try {
    require $projectRoot . '/bot/core/bootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingReadOnlyCheckpointReceipt.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup.php';
    require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';

    if (($config['environment'] ?? null) !== 'staging') {
        throw new RuntimeException('Staging API incident recovery is staging-only.');
    }
    $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
        is_string($configFile ?? null) ? $configFile : '',
        $projectRoot
    );
    $privateDir = (string)($private['private_dir'] ?? '');
    if ($privateDir === '' || !is_dir($privateDir) || is_link($privateDir)) {
        throw new RuntimeException('Staging API incident recovery private directory is unavailable.');
    }

    $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($projectRoot);
    if (!hash_equals($values['expected_commit'], $currentCommit)) {
        throw new RuntimeException('Staging API incident recovery checkout does not match expected commit.');
    }
    foreach ([
        'activation' => RuntimePrimaryStagingActivationConfig::fromApplicationConfig($config)->safeSummary()['enabled'] ?? null,
        'selector' => RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig($config)->enabled(),
        'request session' => RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config)->enabled(),
    ] as $label => $enabled) {
        if ($enabled !== false) {
            throw new RuntimeException('Persistent staging latch must remain disabled during recovery: ' . $label . '.');
        }
    }

    $receipt = RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
        canonicalRecoveryPrivateFile($values['receipt'], $projectRoot, 'Read-only receipt'),
        $projectRoot,
        3600
    );
    if (!hash_equals(MGW_API_INCIDENT_COMMIT, (string)$receipt['repository_commit'])) {
        throw new RuntimeException('Read-only receipt does not belong to the exact incident checkout.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()
        || !hash_equals(
            (string)$receipt['database_identity_fingerprint'],
            $databaseConfig->identityFingerprint()
        )) {
        throw new RuntimeException('Staging API incident recovery database identity does not match receipt.');
    }

    $lockPath = $privateDir . '/runtime-primary-rehearsal.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Staging API incident recovery lock must not be a symbolic link.');
    }
    $lockHandle = fopen($lockPath, 'c+');
    if (!is_resource($lockHandle) || !chmod($lockPath, 0600)
        || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another rehearsal, collector or smoke is already running.');
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $storage = new DatabasePrimaryStateStorageAdapter(
        $database,
        new RuntimePrimaryProjectionOutboxWriter()
    );
    $status = $storage->status();
    $snapshot = $storage->readOnly(static fn(array $state): array => $state);
    if (!is_array($snapshot)) {
        throw new RuntimeException('Staging API incident recovery snapshot is unavailable.');
    }

    $baselineRevision = (int)$receipt['state_revision'];
    $cleanupRevision = $baselineRevision + 2;
    if ((int)($status['revision'] ?? 0) !== $cleanupRevision
        || !hash_equals((string)$receipt['state_sha256'], (string)($status['state_sha256'] ?? ''))) {
        throw new RuntimeException('Staging API incident recovery state does not match the exact baseline-plus-two incident.');
    }

    $events = $database->fetchAll(
        'SELECT state_revision, status, attempt_count, lease_token, lease_expires_at_utc,
                last_error, state_sha256
         FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
         WHERE state_revision >= :api_revision
         ORDER BY state_revision ASC',
        ['api_revision' => $baselineRevision + 1]
    );
    if (count($events) !== 2) {
        throw new RuntimeException('Staging API incident recovery outbox suffix is not exact.');
    }
    $apiEvent = $events[0];
    $cleanupEvent = $events[1];
    if ((int)($apiEvent['state_revision'] ?? 0) !== $baselineRevision + 1
        || (string)($apiEvent['status'] ?? '') !== 'completed'
        || (int)($apiEvent['attempt_count'] ?? 0) !== 1
        || (int)($cleanupEvent['state_revision'] ?? 0) !== $cleanupRevision
        || (string)($cleanupEvent['status'] ?? '') !== 'failed'
        || (int)($cleanupEvent['attempt_count'] ?? 0) !== 1
        || (string)($cleanupEvent['lease_token'] ?? '') !== ''
        || (string)($cleanupEvent['lease_expires_at_utc'] ?? '') !== ''
        || !hash_equals(MGW_API_INCIDENT_ERROR, (string)($cleanupEvent['last_error'] ?? ''))
        || !hash_equals((string)$receipt['state_sha256'], (string)($cleanupEvent['state_sha256'] ?? ''))) {
        throw new RuntimeException('Staging API incident recovery outbox identity is invalid.');
    }

    $balances = $database->fetchAll(
        "SELECT b.account_ref, b.mgw_id, b.legacy_user_id, b.asset_code, b.reserved_amount
         FROM mgw_balances b
         WHERE b.legacy_user_id REGEXP '^9[0-9]{17}$'
           AND b.account_ref = CONCAT('legacy:', b.legacy_user_id)
           AND NOT EXISTS (
               SELECT 1 FROM mgw_account_ownership o
               WHERE o.account_ref = b.account_ref OR o.mgw_id = b.mgw_id
           )
           AND NOT EXISTS (
               SELECT 1 FROM mgw_legacy_realtime_shadow s
               WHERE s.entity_type = 'economy_user_balance'
                 AND s.entity_key = b.legacy_user_id
           )
         ORDER BY b.legacy_user_id ASC, b.asset_code ASC"
    );
    if (count($balances) !== 2) {
        throw new RuntimeException('Staging API incident recovery did not find exactly two orphan economy balances.');
    }
    $legacyUserId = (string)($balances[0]['legacy_user_id'] ?? '');
    $mgwId = (string)($balances[0]['mgw_id'] ?? '');
    $assets = [];
    foreach ($balances as $balance) {
        $asset = (string)($balance['asset_code'] ?? '');
        if (!hash_equals($legacyUserId, (string)($balance['legacy_user_id'] ?? ''))
            || !hash_equals($mgwId, (string)($balance['mgw_id'] ?? ''))
            || !hash_equals('legacy:' . $legacyUserId, (string)($balance['account_ref'] ?? ''))
            || !in_array($asset, ['gold_coin', 'match_coin'], true)
            || isset($assets[$asset])
            || (int)($balance['reserved_amount'] ?? -1) !== 0) {
            throw new RuntimeException('Staging API incident recovery orphan economy identity is invalid.');
        }
        $assets[$asset] = true;
    }
    if (array_keys($assets) !== ['gold_coin', 'match_coin']
        || preg_match('/\A9\d{17}\z/', $legacyUserId) !== 1
        || !MgwIdGenerator::isValid($mgwId)
        || isset($snapshot['users'][$legacyUserId])) {
        throw new RuntimeException('Staging API incident recovery orphan candidate does not match the synthetic contract.');
    }

    $cleanup = new RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup($database);
    $economyProof = $cleanup->recoverOrphanEconomyArtifacts($legacyUserId, $mgwId);

    $now = gmdate(DATE_ATOM);
    $reset = $database->execute(
        'UPDATE ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . "
         SET status = 'pending', lease_token = '', lease_expires_at_utc = '',
             available_at_utc = :available_at_utc, last_error = '', updated_at_utc = :updated_at_utc
         WHERE state_revision = :state_revision
           AND status = 'failed'
           AND attempt_count = 1
           AND last_error = :last_error",
        [
            'available_at_utc' => $now,
            'updated_at_utc' => $now,
            'state_revision' => $cleanupRevision,
            'last_error' => MGW_API_INCIDENT_ERROR,
        ]
    );
    if ($reset !== 1) {
        throw new RuntimeException('Staging API incident recovery could not reset the exact failed event.');
    }

    $projector = (new RuntimePrimaryRepositoryProjectorFactory($config, $database))->create();
    $worker = new RuntimePrimaryProjectionWorkerAdapter(
        new RuntimePrimaryProjectionWorker($database, $projector, 60)
    );
    $tick = $worker->runOnce();
    if (($tick['ok'] ?? false) !== true
        || ($tick['action'] ?? '') !== 'projection_completed'
        || ($tick['claimed'] ?? false) !== true
        || (int)($tick['state_revision'] ?? 0) !== $cleanupRevision
        || (int)($tick['attempt_count'] ?? 0) !== 2
        || ($tick['parity_ok'] ?? false) !== true) {
        throw new RuntimeException('Staging API incident recovery cleanup projection did not complete exactly.');
    }

    $userProof = $cleanup->removeOrphanUserAfterCleanupProjection($mgwId);

    $verificationDatabase = PdoConnectionFactory::create($databaseConfig);
    $verificationStorage = new DatabasePrimaryStateStorageAdapter(
        $verificationDatabase,
        new RuntimePrimaryProjectionOutboxWriter()
    );
    $finalStatus = $verificationStorage->status();
    $finalSnapshot = $verificationStorage->readOnly(static fn(array $state): array => $state);
    $verificationProjector = (new RuntimePrimaryRepositoryProjectorFactory(
        $config,
        $verificationDatabase
    ))->create();
    $audit = (new RuntimePrimaryProjectionAuditorAdapter($verificationProjector))->auditOnly(
        $finalSnapshot,
        (int)($finalStatus['revision'] ?? 0),
        (string)($finalStatus['state_sha256'] ?? '')
    );
    $finalEvent = $verificationDatabase->fetchAll(
        'SELECT status, attempt_count, lease_token, lease_expires_at_utc, last_error
         FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
         WHERE state_revision = :state_revision',
        ['state_revision' => $cleanupRevision]
    );

    $remainingEconomy = 0;
    foreach ([
        'SELECT COUNT(*) FROM mgw_balances WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref',
        'SELECT COUNT(*) FROM mgw_ledger_entries WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref',
        'SELECT COUNT(*) FROM mgw_reservations WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref',
    ] as $sql) {
        $remainingEconomy += (int)$verificationDatabase->fetchValue($sql, [
            'mgw_id' => $mgwId,
            'legacy_user_id' => $legacyUserId,
            'account_ref' => 'legacy:' . $legacyUserId,
        ]);
    }
    $remainingEconomy += (int)$verificationDatabase->fetchValue(
        'SELECT COUNT(*) FROM mgw_idempotency_keys WHERE owner_ref = :account_ref',
        ['account_ref' => 'legacy:' . $legacyUserId]
    );
    $remainingIdentity = 0;
    foreach (['mgw_users', 'mgw_identities', 'mgw_account_ownership', 'mgw_devices', 'mgw_sessions'] as $table) {
        $remainingIdentity += (int)$verificationDatabase->fetchValue(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE mgw_id = :mgw_id',
            ['mgw_id' => $mgwId]
        );
    }

    if (!is_array($finalSnapshot)
        || (int)($finalStatus['revision'] ?? 0) !== $cleanupRevision
        || !hash_equals((string)$receipt['state_sha256'], (string)($finalStatus['state_sha256'] ?? ''))
        || ($audit['ok'] ?? false) !== true
        || ($audit['parity_ok'] ?? false) !== true
        || count($finalEvent) !== 1
        || (string)($finalEvent[0]['status'] ?? '') !== 'completed'
        || (int)($finalEvent[0]['attempt_count'] ?? 0) !== 2
        || (string)($finalEvent[0]['lease_token'] ?? '') !== ''
        || (string)($finalEvent[0]['lease_expires_at_utc'] ?? '') !== ''
        || (string)($finalEvent[0]['last_error'] ?? '') !== ''
        || $remainingEconomy !== 0
        || $remainingIdentity !== 0
        || isset($finalSnapshot['users'][$legacyUserId])) {
        throw new RuntimeException('Staging API incident recovery final proof is incomplete.');
    }

    $report = [
        'ok' => true,
        'report_type' => 'mvp-14.9b-staging-api-economy-incident-recovery',
        'action' => 'staging_api_economy_incident_recovered_and_verified',
        'incident_repository_commit' => MGW_API_INCIDENT_COMMIT,
        'recovery_repository_commit' => $currentCommit,
        'database_identity_fingerprint' => $databaseConfig->identityFingerprint(),
        'read_only_receipt_sha256' => (string)$receipt['receipt_sha256'],
        'baseline_state_revision' => $baselineRevision,
        'baseline_state_sha256' => (string)$receipt['state_sha256'],
        'api_state_revision' => $baselineRevision + 1,
        'cleanup_state_revision' => $cleanupRevision,
        'cleanup_failed_attempt_count_before' => 1,
        'cleanup_completed_attempt_count_after' => 2,
        'incident_error_sha256' => hash('sha256', MGW_API_INCIDENT_ERROR),
        'economy_balance_rows_deleted' => (int)$economyProof['economy_balance_rows_deleted'],
        'economy_ledger_rows_deleted' => (int)$economyProof['economy_ledger_rows_deleted'],
        'economy_idempotency_rows_deleted' => (int)$economyProof['economy_idempotency_rows_deleted'],
        'economy_reservation_rows_verified_zero' => true,
        'orphan_user_rows_deleted' => (int)($userProof['user_rows_deleted'] ?? 0),
        'worker_tick_count' => 1,
        'cleanup_projection_completed' => true,
        'state_sha_restored_to_receipt' => true,
        'all_module_parity_verified' => true,
        'synthetic_economy_rows_remaining' => 0,
        'synthetic_identity_rows_remaining' => 0,
        'persistent_config_changed' => false,
        'http_route_added' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
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
        throw new RuntimeException('Staging API incident recovery evidence publication failed.');
    }

    $response = [
        'ok' => true,
        'action' => 'staging_api_economy_incident_recovery_passed',
        'repository_commit' => $currentCommit,
        'incident_repository_commit' => MGW_API_INCIDENT_COMMIT,
        'state_revision' => $cleanupRevision,
        'failed_event_recovered' => true,
        'economy_orphan_rows_removed' => true,
        'all_module_parity_verified' => true,
        'synthetic_identity_cleanup_verified' => true,
        'evidence_file_sha256' => (string)$written['file_sha256'],
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
        'action' => 'staging_api_economy_incident_recovery_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => safeRecoveryMessage($error->getMessage()),
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
        }
    }
}

fwrite(STDOUT, json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL);
exit($exitCode);

function canonicalRecoveryPrivateFile(string $path, string $projectRoot, string $label): string
{
    if ($path === '' || trim($path) !== $path || str_contains($path, '\\')
        || !str_starts_with($path, '/') || str_ends_with($path, '/')
        || is_link($path) || !is_file($path)) {
        throw new RuntimeException($label . ' must be a regular exact absolute Linux file.');
    }
    $real = realpath($path);
    if (!is_string($real) || !hash_equals($path, $real)
        || $real === $projectRoot || str_starts_with($real, $projectRoot . '/')
        || preg_match('~(?:\A|/)public_html(?:/|\z)~', $real) === 1) {
        throw new RuntimeException($label . ' must remain outside the deployed project.');
    }
    clearstatcache(true, $real);
    $mode = fileperms($real);
    $parentMode = fileperms(dirname($real));
    if (!is_int($mode) || ($mode & 0777) !== 0600
        || !is_int($parentMode) || ($parentMode & 0022) !== 0) {
        throw new RuntimeException($label . ' permissions are unsafe.');
    }
    return $real;
}

function safeRecoveryMessage(string $message): string
{
    $message = preg_replace(
        [
            '~/(?:home|var|tmp|srv)/[^\s\'\"]+~',
            '/\b9\d{17}\b/',
            '/\bMGW-[0-9A-Z]{16}\b/',
        ],
        ['[private-path]', '[synthetic-user]', '[synthetic-mgw-id]'],
        $message
    ) ?? $message;
    return mb_substr(trim($message), 0, 500);
}
