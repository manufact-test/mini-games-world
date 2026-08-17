<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']) . PHP_EOL;
    exit;
}

try {
    require __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';

    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) !== 1) {
        throw new RuntimeException('OIDC bearer token is required.');
    }
    (new GitHubActionsOidcVerifier($config))->verifyAndConsume(trim((string)$match[1]));

    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        throw new RuntimeException('Staging only.');
    }

    $body = json_decode((string)file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $mgwId = strtoupper(trim((string)($body['mgw_id'] ?? '')));
    if (preg_match('/^[A-Z0-9]{12,24}$/', $mgwId) !== 1) {
        throw new RuntimeException('Invalid MGW id.');
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) throw new RuntimeException('Database disabled.');
    $db = PdoConnectionFactory::create($databaseConfig);

    $owners = $db->fetchAll(
        "SELECT account_ref, legacy_user_id FROM mgw_account_ownership WHERE mgw_id = :mgw_id AND ownership_status = 'active'",
        ['mgw_id' => $mgwId]
    );
    if (count($owners) !== 1) throw new RuntimeException('Active ownership is not unique.');
    $accountRef = (string)$owners[0]['account_ref'];

    $balances = $db->fetchAll(
        "SELECT available_amount, reserved_amount, version, updated_at_utc FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = 'mgw_coin'",
        ['account_ref' => $accountRef]
    );

    $entries = $db->fetchAll(
        "SELECT entry_id, available_delta, available_before, available_after, category, source_type, metadata_json, created_at_utc
         FROM mgw_ledger_entries
         WHERE account_ref = :account_ref AND asset_code = 'mgw_coin'
         ORDER BY ledger_sequence DESC LIMIT 8",
        ['account_ref' => $accountRef]
    );

    $safeEntries = [];
    foreach ($entries as $row) {
        $metadata = [];
        try {
            $decoded = json_decode((string)($row['metadata_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach (['database_amount', 'source_amount', 'database_version', 'target_asset'] as $key) {
                    if (array_key_exists($key, $decoded)) $metadata[$key] = $decoded[$key];
                }
            }
        } catch (Throwable) {}
        $safeEntries[] = [
            'entry_id' => (string)$row['entry_id'],
            'delta' => (int)$row['available_delta'],
            'before' => (int)$row['available_before'],
            'after' => (int)$row['available_after'],
            'category' => (string)$row['category'],
            'source_type' => (string)$row['source_type'],
            'metadata' => $metadata,
            'created_at_utc' => (string)$row['created_at_utc'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'mgw_id' => $mgwId,
        'balance' => $balances === [] ? null : [
            'available' => (int)$balances[0]['available_amount'],
            'reserved' => (int)$balances[0]['reserved_amount'],
            'version' => (int)$balances[0]['version'],
            'updated_at_utc' => (string)$balances[0]['updated_at_utc'],
        ],
        'recent_ledger' => $safeEntries,
        'read_only' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[MGW staging balance rollback diagnostic] ' . get_class($error) . ': ' . $error->getMessage());
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'diagnostic_unavailable']) . PHP_EOL;
}
