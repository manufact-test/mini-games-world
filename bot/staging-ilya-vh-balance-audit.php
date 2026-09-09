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
    (new GitHubActionsOidcVerifier($config))->verifyAndConsume(trim((string)$match[1]));

    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        throw new RuntimeException('Staging only.');
    }

    $body = json_decode((string)file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    $requestedUsername = strtolower(ltrim(trim((string)($body['username'] ?? '')), '@'));
    if ($requestedUsername !== 'ilya_vh') {
        throw new RuntimeException('This one-time diagnostic is scoped to ilya_vh only.');
    }

    $jsonStorage = new JsonStorageAdapter((string)$config['data_dir']);
    $legacy = $jsonStorage->readOnly(function (array $db) use ($requestedUsername): array {
        $matches = [];
        foreach (($db['users'] ?? []) as $key => $user) {
            if (!is_array($user)) continue;
            $username = strtolower(ltrim(trim((string)($user['username'] ?? '')), '@'));
            if ($username !== $requestedUsername) continue;
            $copy = $user;
            $copy['_storage_key'] = (string)$key;
            $matches[] = $copy;
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Legacy username lookup is not unique.');
        }
        $user = $matches[0];
        $legacyUserId = trim((string)($user['id'] ?? $user['_storage_key'] ?? ''));
        if ($legacyUserId === '') throw new RuntimeException('Legacy user id is missing.');

        $projectTx = static function (array $tx): array {
            $meta = is_array($tx['meta'] ?? null) ? $tx['meta'] : [];
            return [
                'id' => (string)($tx['id'] ?? ''),
                'type' => (string)($tx['type'] ?? ''),
                'category' => (string)($tx['category'] ?? ''),
                'amount' => (int)($tx['amount'] ?? 0),
                'reason' => (string)($tx['reason'] ?? $tx['description'] ?? ''),
                'balance_before' => array_key_exists('balance_before', $tx) ? (int)$tx['balance_before'] : null,
                'balance_after' => array_key_exists('balance_after', $tx) ? (int)$tx['balance_after'] : null,
                'created_at' => (string)($tx['created_at'] ?? ''),
                'actor_ref' => (string)($tx['actor_ref'] ?? ''),
                'request_token' => (string)($tx['request_token'] ?? ''),
                'meta' => array_filter([
                    'balance_before' => array_key_exists('balance_before', $meta) ? (int)$meta['balance_before'] : null,
                    'balance_after' => array_key_exists('balance_after', $meta) ? (int)$meta['balance_after'] : null,
                    'requested_amount' => array_key_exists('requested_amount', $meta) ? (int)$meta['requested_amount'] : null,
                    'source' => isset($meta['source']) ? (string)$meta['source'] : null,
                    'audit_action' => isset($meta['audit_action']) ? (string)$meta['audit_action'] : null,
                ], static fn(mixed $value): bool => $value !== null && $value !== ''),
            ];
        };

        $allUserTransactions = [];
        foreach (array_values($db['transactions'] ?? []) as $tx) {
            if (!is_array($tx)) continue;
            $txUserId = trim((string)($tx['user_id'] ?? $tx['target_user_id'] ?? ''));
            if ($txUserId !== $legacyUserId) continue;
            $allUserTransactions[] = $projectTx($tx);
        }

        $adminGrants = array_values(array_filter(
            $allUserTransactions,
            static fn(array $tx): bool => (string)($tx['category'] ?? '') === 'admin_test_coin_grant'
        ));

        return [
            'legacy_user_id' => $legacyUserId,
            'username' => (string)($user['username'] ?? ''),
            'runtime_balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0),
            'legacy_match_snapshot' => (int)($user['balance_match'] ?? 0),
            'legacy_gold_snapshot' => (int)($user['balance_gold'] ?? 0),
            'migration' => is_array($user['unified_balance_migration'] ?? null)
                ? array_intersect_key($user['unified_balance_migration'], array_flip(['target_balance','ran_at','source_balance_match','source_balance_gold']))
                : null,
            'transaction_count' => count($allUserTransactions),
            'earliest_transactions' => array_slice($allUserTransactions, 0, 20),
            'recent_transactions' => array_slice(array_reverse($allUserTransactions), 0, 20),
            'admin_test_coin_grants' => array_slice($adminGrants, -10),
        ];
    });

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) throw new RuntimeException('Database disabled.');
    $database = PdoConnectionFactory::create($databaseConfig);

    $owners = $database->fetchAll(
        "SELECT account_ref, mgw_id, legacy_user_id
         FROM mgw_account_ownership
         WHERE legacy_user_id = :legacy_user_id AND ownership_status = 'active'",
        ['legacy_user_id' => $legacy['legacy_user_id']]
    );
    if (count($owners) !== 1 || !is_array($owners[0])) {
        throw new RuntimeException('Active ownership is not unique.');
    }
    $owner = $owners[0];
    $accountRef = trim((string)($owner['account_ref'] ?? ''));
    $mgwId = trim((string)($owner['mgw_id'] ?? ''));
    if ($accountRef === '' || $mgwId === '') throw new RuntimeException('Active ownership is incomplete.');

    $balances = $database->fetchAll(
        "SELECT available_amount, reserved_amount, version, updated_at_utc
         FROM mgw_balances
         WHERE account_ref = :account_ref AND asset_code = 'mgw_coin'",
        ['account_ref' => $accountRef]
    );

    $recentEntries = $database->fetchAll(
        "SELECT entry_id, ledger_sequence, available_delta, available_before, available_after,
                reserved_before, reserved_after, category, source_type, source_ref,
                metadata_json, created_at_utc
         FROM mgw_ledger_entries
         WHERE account_ref = :account_ref AND asset_code = 'mgw_coin'
         ORDER BY ledger_sequence DESC LIMIT 30",
        ['account_ref' => $accountRef]
    );

    $earliestEntries = $database->fetchAll(
        "SELECT entry_id, ledger_sequence, available_delta, available_before, available_after,
                reserved_before, reserved_after, category, source_type, source_ref,
                metadata_json, created_at_utc
         FROM mgw_ledger_entries
         WHERE account_ref = :account_ref AND asset_code = 'mgw_coin'
         ORDER BY ledger_sequence ASC LIMIT 20",
        ['account_ref' => $accountRef]
    );

    $projectLedger = static function (array $entries): array {
        $ledger = [];
        foreach ($entries as $row) {
            if (!is_array($row)) continue;
            $metadata = [];
            try {
                $decoded = json_decode((string)($row['metadata_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    foreach ([
                        'database_amount','source_amount','database_version','target_asset',
                        'balance_before','balance_after','requested_amount','audit_action','source',
                        'offending_entry_id','recovered_from_available_before','stale_runtime_source_amount'
                    ] as $key) {
                        if (array_key_exists($key, $decoded)) $metadata[$key] = $decoded[$key];
                    }
                }
            } catch (Throwable) {}
            $ledger[] = [
                'entry_id' => (string)($row['entry_id'] ?? ''),
                'sequence' => (int)($row['ledger_sequence'] ?? 0),
                'delta' => (int)($row['available_delta'] ?? 0),
                'before' => (int)($row['available_before'] ?? 0),
                'after' => (int)($row['available_after'] ?? 0),
                'reserved_before' => (int)($row['reserved_before'] ?? 0),
                'reserved_after' => (int)($row['reserved_after'] ?? 0),
                'category' => (string)($row['category'] ?? ''),
                'source_type' => (string)($row['source_type'] ?? ''),
                'source_ref' => (string)($row['source_ref'] ?? ''),
                'metadata' => $metadata,
                'created_at_utc' => (string)($row['created_at_utc'] ?? ''),
            ];
        }
        return $ledger;
    };

    echo json_encode([
        'ok' => true,
        'read_only' => true,
        'target' => '@ilya_vh',
        'mgw_id' => $mgwId,
        'runtime' => [
            'balance' => $legacy['runtime_balance'],
            'legacy_match_snapshot' => $legacy['legacy_match_snapshot'],
            'legacy_gold_snapshot' => $legacy['legacy_gold_snapshot'],
            'migration' => $legacy['migration'],
            'transaction_count' => $legacy['transaction_count'],
        ],
        'canonical_balance' => $balances === [] ? null : [
            'available' => (int)$balances[0]['available_amount'],
            'reserved' => (int)$balances[0]['reserved_amount'],
            'version' => (int)$balances[0]['version'],
            'updated_at_utc' => (string)$balances[0]['updated_at_utc'],
        ],
        'earliest_runtime_transactions' => $legacy['earliest_transactions'],
        'recent_runtime_transactions' => $legacy['recent_transactions'],
        'admin_test_coin_grants' => $legacy['admin_test_coin_grants'],
        'earliest_ledger' => $projectLedger($earliestEntries),
        'recent_ledger' => $projectLedger($recentEntries),
        'production_changed' => false,
        'live_payments_used' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[MGW staging ilya_vh balance audit] ' . get_class($error) . ': ' . $error->getMessage());
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'balance_audit_unavailable']) . PHP_EOL;
}
