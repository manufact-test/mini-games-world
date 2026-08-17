<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

try {
    require __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';
    require_once __DIR__ . '/services/StagingInviteMismatchDiagnosticService.php';

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

    $service = new StagingInviteMismatchDiagnosticService(
        $config,
        $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : null
    );
    $result = $service->diagnose($_SERVER);

    // TEMPORARY READ-ONLY rollback evidence for the real staging account.
    // The canonical staging workflow already calls this OIDC-protected endpoint,
    // so the evidence appears in Actions without creating a second auth surface.
    $targetMgwId = 'N67MQT6PZG0M84Y2';
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if ($databaseConfig->enabled()) {
        $database = PdoConnectionFactory::create($databaseConfig);
        $owners = $database->fetchAll(
            "SELECT account_ref FROM mgw_account_ownership
             WHERE mgw_id = :mgw_id AND ownership_status = 'active'",
            ['mgw_id' => $targetMgwId]
        );
        if (count($owners) === 1) {
            $accountRef = trim((string)$owners[0]['account_ref']);
            $balances = $database->fetchAll(
                "SELECT available_amount, reserved_amount, version, updated_at_utc
                 FROM mgw_balances
                 WHERE account_ref = :account_ref AND asset_code = 'mgw_coin'",
                ['account_ref' => $accountRef]
            );
            $entries = $database->fetchAll(
                "SELECT entry_id, available_delta, available_before, available_after,
                        category, source_type, metadata_json, created_at_utc
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
                    'entry_id' => (string)($row['entry_id'] ?? ''),
                    'delta' => (int)($row['available_delta'] ?? 0),
                    'before' => (int)($row['available_before'] ?? 0),
                    'after' => (int)($row['available_after'] ?? 0),
                    'category' => (string)($row['category'] ?? ''),
                    'source_type' => (string)($row['source_type'] ?? ''),
                    'metadata' => $metadata,
                    'created_at_utc' => (string)($row['created_at_utc'] ?? ''),
                ];
            }
            $result['report']['balance_zero_probe'] = [
                'mgw_id' => $targetMgwId,
                'current' => $balances === [] ? null : [
                    'available' => (int)$balances[0]['available_amount'],
                    'reserved' => (int)$balances[0]['reserved_amount'],
                    'version' => (int)$balances[0]['version'],
                    'updated_at_utc' => (string)$balances[0]['updated_at_utc'],
                ],
                'recent_ledger' => $safeEntries,
                'read_only' => true,
            ];
        } else {
            $result['report']['balance_zero_probe'] = [
                'mgw_id' => $targetMgwId,
                'ownership_count' => count($owners),
                'read_only' => true,
            ];
        }
    }

    echo json_encode(
        $result + ['authorization_mode' => 'github_actions_oidc'],
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging invite mismatch diagnostic] denied: ' . get_class($error));
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'service' => 'mini-games-world-staging-invite-mismatch-diagnostic',
        'error' => 'diagnostic_unavailable',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}