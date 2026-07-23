<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    exit(2);
}

const BASELINE_REVISION = 3;
const API_REVISION = 4;
const CLEANUP_REVISION = 5;
const CLEANUP_FAILURE = 'Weekly bonus DB state contains users missing from JSON rollback storage.';

$mode = $argv[1] ?? '';
$expectedCommit = $argv[2] ?? '';
if (!in_array($mode, ['--inspect', '--apply'], true)
    || preg_match('/\A[a-f0-9]{40}\z/', $expectedCommit) !== 1
    || count($argv) !== 3) {
    exit(2);
}

umask(0077);
$root = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
$lock = null;
$response = [];
$exit = 1;
$databaseWriteExecuted = false;
$workerExecuted = false;

try {
    require $root . '/bot/core/bootstrap.php';
    require_once $root . '/bot/runtime/RuntimePrimaryStagingEntrypointBootstrap.php';
    require_once $root . '/bot/runtime/RuntimePrimaryStagingReadOnlyCheckpointReceipt.php';
    require_once $root . '/bot/runtime/RuntimePrimaryStagingEvidenceWriter.php';

    if (($config['environment'] ?? null) !== 'staging') {
        throw new RuntimeException('staging_only');
    }

    $private = RuntimePrimaryPrivateConfigGuard::assertExternal(
        (string)($configFile ?? ''),
        $root
    );
    $privateDir = (string)($private['private_dir'] ?? '');
    if ($privateDir === '' || !is_dir($privateDir) || is_link($privateDir)) {
        throw new RuntimeException('private_dir');
    }

    $commit = RuntimePrimaryRepositoryCommitResolver::resolve($root);
    if (!hash_equals($expectedCommit, $commit)) {
        throw new RuntimeException('commit_mismatch');
    }

    foreach ([
        RuntimePrimaryStagingActivationConfig::fromApplicationConfig($config)->safeSummary()['enabled'] ?? null,
        RuntimePrimaryStagingEntrypointSelectorConfig::fromApplicationConfig($config)->enabled(),
        RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig($config)->enabled(),
    ] as $enabled) {
        if ($enabled !== false) {
            throw new RuntimeException('persistent_latch_enabled');
        }
    }

    $receipt = exactBaselineReceipt($privateDir, $root, $expectedCommit);
    $dbConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$dbConfig->enabled()
        || !hash_equals(
            (string)$receipt['database_identity_fingerprint'],
            $dbConfig->identityFingerprint()
        )) {
        throw new RuntimeException('database_identity');
    }

    $lockPath = $privateDir . '/runtime-primary-rehearsal.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('lock_symlink');
    }
    $lock = fopen($lockPath, 'c+');
    if (!is_resource($lock)
        || !chmod($lockPath, 0600)
        || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('lock_busy');
    }

    $db = PdoConnectionFactory::create($dbConfig);
    $storage = new DatabasePrimaryStateStorageAdapter(
        $db,
        new RuntimePrimaryProjectionOutboxWriter()
    );
    $status = $storage->status();
    $snapshot = $storage->readOnly(static fn(array $state): array => $state);

    if (!is_array($snapshot)
        || (int)($status['revision'] ?? 0) !== CLEANUP_REVISION
        || !hash_equals(
            (string)$receipt['state_sha256'],
            (string)($status['state_sha256'] ?? '')
        )) {
        throw new RuntimeException('state_identity');
    }

    $events = $db->fetchAll(
        'SELECT state_revision,status,attempt_count,lease_token,lease_expires_at_utc,last_error,state_sha256 '
        . 'FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
        . ' WHERE state_revision>=:api_revision AND state_revision<=:cleanup_revision ORDER BY state_revision',
        [
            'api_revision' => API_REVISION,
            'cleanup_revision' => CLEANUP_REVISION,
        ]
    );

    if (count($events) !== 2
        || (int)($events[0]['state_revision'] ?? 0) !== API_REVISION
        || (string)($events[0]['status'] ?? '') !== 'completed'
        || (int)($events[0]['attempt_count'] ?? 0) !== 1
        || (int)($events[1]['state_revision'] ?? 0) !== CLEANUP_REVISION
        || (string)($events[1]['status'] ?? '') !== 'failed'
        || (int)($events[1]['attempt_count'] ?? 0) !== 1
        || trim((string)($events[1]['lease_token'] ?? '')) !== ''
        || trim((string)($events[1]['lease_expires_at_utc'] ?? '')) !== ''
        || !hash_equals(CLEANUP_FAILURE, (string)($events[1]['last_error'] ?? ''))
        || !hash_equals(
            (string)$receipt['state_sha256'],
            (string)($events[1]['state_sha256'] ?? '')
        )) {
        throw new RuntimeException('outbox_identity');
    }

    [$legacyId, $accountRef] = exactWeeklyOrphan($db, $snapshot);

    if ($mode === '--inspect') {
        $response = safeResponse([
            'ok' => true,
            'action' => 'weekly_bonus_orphan_revision_five_inspected',
            'repository_commit' => $commit,
            'state_revision' => CLEANUP_REVISION,
            'outbox_attempt_count' => 1,
            'exact_weekly_orphan_proven' => true,
            'database_write_executed' => false,
            'worker_executed' => false,
        ]);
        $exit = 0;
    } else {
        $stateSha = (string)$receipt['state_sha256'];
        $nowAvailable = gmdate(DATE_ATOM);
        $nowUpdated = gmdate(DATE_ATOM);

        $deleted = $db->transaction(
            function (DatabaseConnectionInterface $tx) use (
                $legacyId,
                $accountRef,
                $stateSha,
                $nowAvailable,
                $nowUpdated
            ): int {
                $rows = $tx->fetchAll(
                    'SELECT state_json,state_sha256,status_json,status_sha256 '
                    . 'FROM mgw_runtime_weekly_bonus_state '
                    . 'WHERE account_ref=:account_ref AND legacy_user_id=:legacy_user_id FOR UPDATE',
                    [
                        'account_ref' => $accountRef,
                        'legacy_user_id' => $legacyId,
                    ]
                );
                if (count($rows) !== 1
                    || !validPayload(
                        (string)$rows[0]['state_json'],
                        (string)$rows[0]['state_sha256']
                    )
                    || !validPayload(
                        (string)$rows[0]['status_json'],
                        (string)$rows[0]['status_sha256']
                    )) {
                    throw new RuntimeException('locked_weekly_orphan');
                }

                $count = $tx->execute(
                    'DELETE FROM mgw_runtime_weekly_bonus_state '
                    . 'WHERE account_ref=:account_ref AND legacy_user_id=:legacy_user_id',
                    [
                        'account_ref' => $accountRef,
                        'legacy_user_id' => $legacyId,
                    ]
                );
                if ($count !== 1) {
                    throw new RuntimeException('delete_count');
                }

                $reset = $tx->execute(
                    'UPDATE ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
                    . " SET status='pending',lease_token='',lease_expires_at_utc='',"
                    . "available_at_utc=:available_at_utc,last_error='',updated_at_utc=:updated_at_utc "
                    . 'WHERE state_revision=:state_revision AND status=\'failed\' '
                    . 'AND attempt_count=1 AND last_error=:last_error AND state_sha256=:state_sha256',
                    [
                        'available_at_utc' => $nowAvailable,
                        'updated_at_utc' => $nowUpdated,
                        'state_revision' => CLEANUP_REVISION,
                        'last_error' => CLEANUP_FAILURE,
                        'state_sha256' => $stateSha,
                    ]
                );
                if ($reset !== 1) {
                    throw new RuntimeException('reset_count');
                }

                return $count;
            }
        );
        $databaseWriteExecuted = true;

        $worker = new RuntimePrimaryProjectionWorkerAdapter(
            new RuntimePrimaryProjectionWorker(
                $db,
                (new RuntimePrimaryRepositoryProjectorFactory($config, $db))->create(),
                60
            )
        );
        $workerExecuted = true;
        $tick = $worker->runOnce();

        if (($tick['ok'] ?? false) !== true
            || ($tick['action'] ?? '') !== 'projection_completed'
            || ($tick['claimed'] ?? false) !== true
            || (int)($tick['state_revision'] ?? 0) !== CLEANUP_REVISION
            || (int)($tick['attempt_count'] ?? 0) !== 2
            || ($tick['parity_ok'] ?? false) !== true) {
            throw new RuntimeException('cleanup_retry_failed');
        }

        $verifyDb = PdoConnectionFactory::create($dbConfig);
        $verifyStorage = new DatabasePrimaryStateStorageAdapter(
            $verifyDb,
            new RuntimePrimaryProjectionOutboxWriter()
        );
        $finalStatus = $verifyStorage->status();
        $finalSnapshot = $verifyStorage->readOnly(static fn(array $state): array => $state);
        $audit = (new RuntimePrimaryProjectionAuditorAdapter(
            (new RuntimePrimaryRepositoryProjectorFactory($config, $verifyDb))->create()
        ))->auditOnly(
            $finalSnapshot,
            CLEANUP_REVISION,
            (string)$finalStatus['state_sha256']
        );
        $event = $verifyDb->fetchAll(
            'SELECT status,attempt_count,lease_token,lease_expires_at_utc,last_error '
            . 'FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
            . ' WHERE state_revision=:state_revision',
            ['state_revision' => CLEANUP_REVISION]
        );

        $remaining = relatedCount($verifyDb, $legacyId, $accountRef)
            + (int)$verifyDb->fetchValue(
                'SELECT COUNT(*) FROM mgw_runtime_weekly_bonus_state '
                . 'WHERE account_ref=:account_ref OR legacy_user_id=:legacy_user_id',
                [
                    'account_ref' => $accountRef,
                    'legacy_user_id' => $legacyId,
                ]
            );

        if (!is_array($finalSnapshot)
            || (int)($finalStatus['revision'] ?? 0) !== CLEANUP_REVISION
            || !hash_equals(
                (string)$receipt['state_sha256'],
                (string)($finalStatus['state_sha256'] ?? '')
            )
            || ($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || count($event) !== 1
            || (string)$event[0]['status'] !== 'completed'
            || (int)$event[0]['attempt_count'] !== 2
            || trim((string)$event[0]['lease_token']) !== ''
            || trim((string)$event[0]['lease_expires_at_utc']) !== ''
            || trim((string)$event[0]['last_error']) !== ''
            || $remaining !== 0
            || (int)$verifyDb->fetchValue(
                'SELECT COUNT(*) FROM mgw_runtime_weekly_bonus_state'
            ) !== 1) {
            throw new RuntimeException('final_proof');
        }

        $report = safeResponse([
            'ok' => true,
            'report_type' => 'mvp-14.9b-weekly-bonus-orphan-revision-five-recovery',
            'action' => 'weekly_bonus_orphan_revision_five_recovered',
            'repository_commit' => $commit,
            'database_identity_fingerprint' => $dbConfig->identityFingerprint(),
            'read_only_receipt_sha256' => (string)$receipt['receipt_sha256'],
            'state_revision' => CLEANUP_REVISION,
            'weekly_bonus_rows_deleted' => $deleted,
            'cleanup_completed_attempt_count' => 2,
            'all_module_parity_verified' => true,
            'database_write_executed' => true,
            'worker_executed' => true,
        ]);
        $output = $privateDir
            . '/staging-weekly-bonus-orphan-revision-five-'
            . gmdate('Ymd\THis\Z')
            . '-'
            . bin2hex(random_bytes(6))
            . '.json';
        $written = (new RuntimePrimaryStagingEvidenceWriter($root))->write(
            $output,
            $report
        );
        if (($written['ok'] ?? false) !== true) {
            throw new RuntimeException('evidence_write');
        }

        $response = safeResponse([
            'ok' => true,
            'action' => 'weekly_bonus_orphan_revision_five_recovery_passed',
            'repository_commit' => $commit,
            'state_revision' => CLEANUP_REVISION,
            'weekly_bonus_orphan_removed' => true,
            'cleanup_completed_attempt_count' => 2,
            'all_module_parity_verified' => true,
            'evidence_file_sha256' => (string)$written['file_sha256'],
            'database_write_executed' => true,
            'worker_executed' => true,
        ]);
        $exit = 0;
    }
} catch (Throwable $error) {
    $response = safeResponse([
        'ok' => false,
        'action' => 'weekly_bonus_orphan_revision_five_blocked_or_failed',
        'error_class' => get_class($error),
        'error_message' => sanitize($error->getMessage()),
        'database_write_executed' => $databaseWriteExecuted,
        'worker_executed' => $workerExecuted,
    ]);
} finally {
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

echo json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
), PHP_EOL;
exit($exit);

function exactBaselineReceipt(
    string $privateDir,
    string $root,
    string $expectedCommit
): array {
    $files = glob(
        $privateDir . '/staging-read-only-checkpoint-receipt-*.json'
    ) ?: [];
    usort(
        $files,
        static fn(string $left, string $right): int =>
            (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0)
    );

    foreach ($files as $file) {
        if (is_link($file) || !is_file($file)) {
            continue;
        }
        $raw = file_get_contents($file);
        if (!is_string($raw)) {
            continue;
        }
        try {
            $candidate = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }
        if (!is_array($candidate)
            || ($candidate['repository_commit'] ?? '') !== $expectedCommit
            || (int)($candidate['state_revision'] ?? 0) !== BASELINE_REVISION) {
            continue;
        }
        $generated = strtotime((string)($candidate['generated_at_utc'] ?? ''));
        if ($generated === false) {
            continue;
        }
        try {
            $receipt = RuntimePrimaryStagingReadOnlyCheckpointReceipt::verifyFile(
                $file,
                $root,
                60,
                $generated
            );
            if (($receipt['mutating_smoke_authorized'] ?? null) === false) {
                return $receipt;
            }
        } catch (Throwable) {
        }
    }

    throw new RuntimeException('baseline_receipt');
}

function exactWeeklyOrphan(
    DatabaseConnectionInterface $db,
    array $snapshot
): array {
    $allUsers = [];
    $sourceUsers = [];
    $sourceAccounts = [];

    foreach (is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [] as $key => $user) {
        if (!is_array($user)) {
            continue;
        }
        $id = trim((string)($user['id'] ?? $key));
        if ($id === '' || isset($allUsers[$id])) {
            throw new RuntimeException('snapshot_identity');
        }
        $allUsers[$id] = true;
        if (!empty($user['is_dev_user'])) {
            continue;
        }
        $rows = $db->fetchAll(
            "SELECT account_ref FROM mgw_account_ownership WHERE legacy_user_id=:legacy_user_id AND ownership_status='active'",
            ['legacy_user_id' => $id]
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('source_identity');
        }
        $account = trim((string)$rows[0]['account_ref']);
        if ($account === ''
            || isset($sourceUsers[$id])
            || isset($sourceAccounts[$account])) {
            throw new RuntimeException('source_duplicate');
        }
        $sourceUsers[$id] = true;
        $sourceAccounts[$account] = true;
    }

    if (count($sourceUsers) !== 1) {
        throw new RuntimeException('source_count');
    }

    $rows = $db->fetchAll(
        'SELECT account_ref,legacy_user_id,state_json,state_sha256,status_json,status_sha256 '
        . 'FROM mgw_runtime_weekly_bonus_state ORDER BY account_ref'
    );
    if (count($rows) !== 2) {
        throw new RuntimeException('weekly_count');
    }

    $extra = array_values(array_filter(
        $rows,
        static fn(array $row): bool =>
            !isset($sourceAccounts[(string)($row['account_ref'] ?? '')])
    ));
    if (count($extra) !== 1) {
        throw new RuntimeException('weekly_extra_count');
    }

    $row = $extra[0];
    $id = trim((string)$row['legacy_user_id']);
    $account = trim((string)$row['account_ref']);
    if (preg_match('/\A9\d{17}\z/', $id) !== 1
        || !hash_equals('legacy:' . $id, $account)
        || isset($allUsers[$id])
        || !validPayload((string)$row['state_json'], (string)$row['state_sha256'])
        || !validPayload((string)$row['status_json'], (string)$row['status_sha256'])
        || relatedCount($db, $id, $account) !== 0) {
        throw new RuntimeException('weekly_orphan_identity');
    }

    foreach ([
        "SELECT COUNT(*) FROM mgw_balances WHERE legacy_user_id REGEXP '^9[0-9]{17}$' AND account_ref=CONCAT('legacy:',legacy_user_id)",
        "SELECT COUNT(*) FROM mgw_ledger_entries WHERE legacy_user_id REGEXP '^9[0-9]{17}$' AND account_ref=CONCAT('legacy:',legacy_user_id)",
        "SELECT COUNT(*) FROM mgw_reservations WHERE legacy_user_id REGEXP '^9[0-9]{17}$' AND account_ref=CONCAT('legacy:',legacy_user_id)",
        "SELECT COUNT(*) FROM mgw_account_ownership WHERE legacy_user_id REGEXP '^9[0-9]{17}$' OR account_ref REGEXP '^legacy:9[0-9]{17}$'",
        "SELECT COUNT(*) FROM mgw_identities WHERE provider_subject REGEXP '^9[0-9]{17}$'",
        "SELECT COUNT(*) FROM mgw_idempotency_keys WHERE owner_ref REGEXP '^legacy:9[0-9]{17}$'",
    ] as $sql) {
        if ((int)$db->fetchValue($sql) !== 0) {
            throw new RuntimeException('synthetic_cleanup');
        }
    }

    return [$id, $account];
}

function relatedCount(
    DatabaseConnectionInterface $db,
    string $id,
    string $account
): int {
    $total = 0;
    foreach ([
        ['SELECT COUNT(*) FROM mgw_account_ownership WHERE account_ref=:account_ref OR legacy_user_id=:legacy_user_id', ['account_ref' => $account, 'legacy_user_id' => $id]],
        ['SELECT COUNT(*) FROM mgw_identities WHERE provider_subject=:legacy_user_id', ['legacy_user_id' => $id]],
        ['SELECT COUNT(*) FROM mgw_balances WHERE account_ref=:account_ref OR legacy_user_id=:legacy_user_id', ['account_ref' => $account, 'legacy_user_id' => $id]],
        ['SELECT COUNT(*) FROM mgw_ledger_entries WHERE account_ref=:account_ref OR legacy_user_id=:legacy_user_id', ['account_ref' => $account, 'legacy_user_id' => $id]],
        ['SELECT COUNT(*) FROM mgw_reservations WHERE account_ref=:account_ref OR legacy_user_id=:legacy_user_id', ['account_ref' => $account, 'legacy_user_id' => $id]],
        ['SELECT COUNT(*) FROM mgw_idempotency_keys WHERE owner_ref=:account_ref', ['account_ref' => $account]],
    ] as [$sql, $parameters]) {
        $total += (int)$db->fetchValue($sql, $parameters);
    }
    return $total;
}

function validPayload(string $json, string $sha): bool
{
    $sha = strtolower(trim($sha));
    if (preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1
        || !hash_equals($sha, hash('sha256', $json))) {
        return false;
    }
    try {
        return is_array(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    } catch (Throwable) {
        return false;
    }
}

function safeResponse(array $data): array
{
    return $data + [
        'persistent_config_changed' => false,
        'webhook_allowed' => false,
        'cron_changed' => false,
        'production_changed' => false,
        'sensitive_identifiers_exposed' => false,
        'generated_at_utc' => gmdate(DATE_ATOM),
    ];
}

function sanitize(string $message): string
{
    return mb_substr(trim(preg_replace(
        [
            '~/(?:home|var|tmp|srv)/[^\s\'\"]+~',
            '/legacy:9\d{17}/',
            '/\b9\d{17}\b/',
            '/\bMGW-[0-9A-Z]{16}\b/',
        ],
        [
            '[private-path]',
            'legacy:[synthetic-user]',
            '[synthetic-user]',
            '[synthetic-mgw-id]',
        ],
        $message
    ) ?? $message), 0, 500);
}
