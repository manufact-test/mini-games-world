<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']) . PHP_EOL;
    exit;
}

try {
    require __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) !== 1) {
        throw new RuntimeException('OIDC bearer token is required.');
    }
    $credential = trim((string)$match[1]);
    if (substr_count($credential, '.') !== 2) {
        throw new RuntimeException('GitHub OIDC JWT is required.');
    }
    (new GitHubActionsOidcVerifier($config))->verifyAndConsume($credential);

    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        throw new RuntimeException('Staging only.');
    }

    $targetMgwId = 'MGW-N67MQT6PZG0M84Y2';
    $offendingEntryId = 'led_830c42a8d888d42b37f59ade47644312cd9a4c0aca9a1227';

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) throw new RuntimeException('Database disabled.');
    $database = PdoConnectionFactory::create($databaseConfig);

    $owners = $database->fetchAll(
        "SELECT account_ref, mgw_id, legacy_user_id
         FROM mgw_account_ownership
         WHERE mgw_id = :mgw_id AND ownership_status = 'active'",
        ['mgw_id' => $targetMgwId]
    );
    if (count($owners) !== 1 || !is_array($owners[0])) {
        throw new RuntimeException('Active ownership is not unique.');
    }
    $owner = $owners[0];
    $accountRef = trim((string)($owner['account_ref'] ?? ''));
    $legacyUserId = trim((string)($owner['legacy_user_id'] ?? ''));
    if ($accountRef === '' || $legacyUserId === '') {
        throw new RuntimeException('Active ownership is incomplete.');
    }

    $offendingRows = $database->fetchAll(
        "SELECT entry_id, available_delta, available_before, available_after,
                reserved_before, reserved_after, category, source_type, metadata_json
         FROM mgw_ledger_entries
         WHERE entry_id = :entry_id AND account_ref = :account_ref AND asset_code = 'mgw_coin'",
        ['entry_id' => $offendingEntryId, 'account_ref' => $accountRef]
    );
    if (count($offendingRows) !== 1 || !is_array($offendingRows[0])) {
        throw new RuntimeException('Rollback ledger evidence is missing.');
    }
    $offending = $offendingRows[0];
    $before = (int)($offending['available_before'] ?? -1);
    $after = (int)($offending['available_after'] ?? -1);
    $delta = (int)($offending['available_delta'] ?? 0);
    if ($before <= 0
        || $after !== 0
        || $delta !== -$before
        || (int)($offending['reserved_before'] ?? -1) !== 0
        || (int)($offending['reserved_after'] ?? -1) !== 0
        || (string)($offending['category'] ?? '') !== 'unified_runtime_sync'
        || (string)($offending['source_type'] ?? '') !== 'runtime_primary_state') {
        throw new RuntimeException('Rollback ledger evidence does not match the zeroing incident.');
    }
    $metadata = json_decode((string)($offending['metadata_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($metadata)
        || (int)($metadata['source_amount'] ?? -1) !== 0
        || (int)($metadata['database_amount'] ?? -1) !== $before) {
        throw new RuntimeException('Rollback ledger metadata does not match the zeroing incident.');
    }
    $restoreAmount = $before;

    $balances = $database->fetchAll(
        "SELECT available_amount, reserved_amount, version
         FROM mgw_balances
         WHERE account_ref = :account_ref AND asset_code = 'mgw_coin'",
        ['account_ref' => $accountRef]
    );
    if (count($balances) !== 1 || !is_array($balances[0])) {
        throw new RuntimeException('Canonical balance is not unique.');
    }
    $dbAvailableBeforeRecovery = (int)($balances[0]['available_amount'] ?? -1);
    $dbReserved = (int)($balances[0]['reserved_amount'] ?? -1);
    if ($dbReserved !== 0 || !in_array($dbAvailableBeforeRecovery, [0, $restoreAmount], true)) {
        throw new RuntimeException('Canonical balance changed after the rollback incident.');
    }

    // Repair the live JSON runtime source first. Otherwise the next API success
    // hook could immediately project the stale zero back into the canonical DB.
    $jsonStorage = new JsonStorageAdapter((string)$config['data_dir']);
    $jsonResult = $jsonStorage->transaction(function (array &$db) use ($legacyUserId, $restoreAmount): array {
        if (!isset($db['users'][$legacyUserId]) || !is_array($db['users'][$legacyUserId])) {
            throw new RuntimeException('Rollback runtime user is missing.');
        }
        $current = (int)($db['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] ?? -1);
        if (!in_array($current, [0, $restoreAmount], true)) {
            throw new RuntimeException('Rollback runtime balance changed before recovery.');
        }
        $migration = $db['users'][$legacyUserId]['unified_balance_migration'] ?? null;
        $migrationTarget = is_array($migration) ? (int)($migration['target_balance'] ?? -1) : null;
        if ($current === 0) {
            $db['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] = $restoreAmount;
        }
        return [
            'before' => $current,
            'after' => (int)$db['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD],
            'migration_target' => $migrationTarget,
        ];
    });

    if ($dbAvailableBeforeRecovery === 0) {
        $ledger = new LedgerWriteService($database);
        $ledgerResult = $ledger->postAvailableDelta([
            'operation_key' => 'staging_rollback_balance_recovery:v1:' . $offendingEntryId,
            'account_ref' => $accountRef,
            'mgw_id' => $targetMgwId,
            'legacy_user_id' => $legacyUserId,
            'asset_code' => 'mgw_coin',
            'available_delta' => $restoreAmount,
            'category' => 'rollback_recovery',
            'source_type' => 'staging_rollback_recovery',
            'source_ref' => $offendingEntryId,
            'metadata' => [
                'offending_entry_id' => $offendingEntryId,
                'recovered_from_available_before' => $restoreAmount,
                'stale_runtime_source_amount' => 0,
            ],
        ]);
    } else {
        $ledgerResult = ['replayed' => true, 'balance' => $balances[0]];
    }

    $finalBalances = $database->fetchAll(
        "SELECT available_amount, reserved_amount, version
         FROM mgw_balances
         WHERE account_ref = :account_ref AND asset_code = 'mgw_coin'",
        ['account_ref' => $accountRef]
    );
    if (count($finalBalances) !== 1
        || (int)($finalBalances[0]['available_amount'] ?? -1) !== $restoreAmount
        || (int)($finalBalances[0]['reserved_amount'] ?? -1) !== 0) {
        throw new RuntimeException('Canonical balance recovery verification failed.');
    }

    $jsonVerification = $jsonStorage->readOnly(function (array $db) use ($legacyUserId): int {
        if (!isset($db['users'][$legacyUserId]) || !is_array($db['users'][$legacyUserId])) return -1;
        return (int)($db['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD] ?? -1);
    });
    if ($jsonVerification !== $restoreAmount) {
        throw new RuntimeException('Runtime balance recovery verification failed.');
    }

    echo json_encode([
        'ok' => true,
        'status' => $dbAvailableBeforeRecovery === 0 ? 'recovered' : 'already_recovered',
        'recovered_amount' => $restoreAmount,
        'runtime_before' => $jsonResult['before'],
        'runtime_after' => $jsonVerification,
        'runtime_migration_target' => $jsonResult['migration_target'],
        'database_before' => $dbAvailableBeforeRecovery,
        'database_after' => (int)$finalBalances[0]['available_amount'],
        'ledger_replayed' => !empty($ledgerResult['replayed']),
        'source_entry' => $offendingEntryId,
        'production_changed' => false,
        'live_payments_used' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[MGW staging rollback balance recovery] ' . get_class($error) . ': ' . $error->getMessage());
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'rollback_balance_recovery_unavailable',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
