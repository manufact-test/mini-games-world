<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
if ($_GET !== []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'query_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

function mgw_staging_runtime_primary_diagnostic(array $config): array
{
    try {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) {
            return ['available' => false, 'reason' => 'database_disabled'];
        }
        $database = PdoConnectionFactory::create($databaseConfig);
        $stateRows = $database->fetchAll(
            'SELECT revision, state_json, state_sha256 FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE . ' WHERE singleton_id = 1'
        );
        if (count($stateRows) !== 1 || !is_array($stateRows[0])) {
            return ['available' => false, 'reason' => 'runtime_primary_state_unavailable'];
        }

        $stateRow = $stateRows[0];
        $stateJson = (string)($stateRow['state_json'] ?? '');
        $state = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            return ['available' => false, 'reason' => 'runtime_primary_state_invalid'];
        }
        $users = is_array($state['users'] ?? null) ? $state['users'] : [];
        $userFieldCounts = [
            'mgw_nickname' => 0,
            'mgw_avatar_item_id' => 0,
            'provider_first_name' => 0,
            'provider_username' => 0,
            'provider_photo_url' => 0,
        ];
        foreach ($users as $user) {
            if (!is_array($user)) continue;
            foreach (array_keys($userFieldCounts) as $field) {
                if (trim((string)($user[$field] ?? '')) !== '') $userFieldCounts[$field]++;
            }
        }

        $outboxRows = $database->fetchAll(
            'SELECT state_revision, status, attempt_count, last_error, available_at_utc '
            . 'FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . ' '
            . 'ORDER BY state_revision DESC LIMIT 1'
        );
        $outbox = is_array($outboxRows[0] ?? null) ? $outboxRows[0] : [];
        $lastError = strtolower(trim((string)($outbox['last_error'] ?? '')));

        $shadowCounts = ['economy_user_balance' => 0, 'economy_transaction' => 0];
        foreach ($database->fetchAll(
            "SELECT entity_type, COUNT(*) AS row_count FROM mgw_legacy_realtime_shadow "
            . "WHERE entity_type IN ('economy_user_balance','economy_transaction') GROUP BY entity_type"
        ) as $row) {
            $type = (string)($row['entity_type'] ?? '');
            if (array_key_exists($type, $shadowCounts)) $shadowCounts[$type] = max(0, (int)($row['row_count'] ?? 0));
        }

        $balanceCounts = ['match_coin' => 0, 'gold_coin' => 0, 'mgw_coin' => 0, 'other' => 0];
        foreach ($database->fetchAll('SELECT asset_code, COUNT(*) AS row_count FROM mgw_balances GROUP BY asset_code') as $row) {
            $asset = (string)($row['asset_code'] ?? '');
            $count = max(0, (int)($row['row_count'] ?? 0));
            if (array_key_exists($asset, $balanceCounts)) $balanceCounts[$asset] = $count;
            else $balanceCounts['other'] += $count;
        }

        $storedSha = strtolower(trim((string)($stateRow['state_sha256'] ?? '')));
        $canonicalState = json_encode(
            (static function (array $value): array {
                $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
                    if (!is_array($item)) return $item;
                    if (!array_is_list($item)) ksort($item, SORT_STRING);
                    foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
                    return $item;
                };
                return $canonicalize($value);
            })($state),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return [
            'available' => true,
            'state_revision' => max(0, (int)($stateRow['revision'] ?? 0)),
            'state_integrity_ok' => preg_match('/^[a-f0-9]{64}$/', $storedSha) === 1
                && hash_equals($storedSha, hash('sha256', $canonicalState)),
            'snapshot_counts' => [
                'users' => count($users),
                'transactions' => count(is_array($state['transactions'] ?? null) ? $state['transactions'] : []),
                'games' => count(is_array($state['games'] ?? null) ? $state['games'] : []),
            ],
            'profile_runtime_field_counts' => $userFieldCounts,
            'outbox' => [
                'state_revision' => max(0, (int)($outbox['state_revision'] ?? 0)),
                'status' => strtolower(trim((string)($outbox['status'] ?? ''))),
                'attempt_count' => max(0, (int)($outbox['attempt_count'] ?? 0)),
                'legacy_economy_delta_not_ready' => str_contains($lastError, 'legacy economy delta is not ready'),
                'economy_shadow_missing' => str_contains($lastError, 'no economy balance shadow rows were found'),
                'legacy_balance_outside_source' => str_contains($lastError, 'balance outside the frozen economy source'),
            ],
            'economy_shadow_counts' => $shadowCounts,
            'balance_row_counts' => $balanceCounts,
            'active_ownership_count' => max(0, (int)$database->fetchValue(
                "SELECT COUNT(*) FROM mgw_account_ownership WHERE ownership_status = 'active'"
            )),
            'unified_cutover_completed_count' => max(0, (int)$database->fetchValue(
                "SELECT COUNT(*) FROM mgw_idempotency_keys WHERE operation_type = 'unified_balance_cutover' AND status = 'completed'"
            )),
            'read_only' => true,
            'sensitive_identifiers_exposed' => false,
        ];
    } catch (Throwable $error) {
        return [
            'available' => false,
            'reason' => 'diagnostic_unavailable',
            'read_only' => true,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}

try {
    require __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/helpers/StagingE2eSourceFingerprint.php';

    $environment = strtolower(trim((string)($config['environment'] ?? '')));
    $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
    $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
    $requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if (str_contains($requestHost, ':')) {
        $requestHost = explode(':', $requestHost, 2)[0];
    }

    $livePayments = !empty($config['external_payments_enabled']);
    foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
        if (strtolower(trim((string)($config[$key] ?? ''))) === 'live') {
            $livePayments = true;
        }
    }

    $required = [
        'oidc_verifier' => __DIR__ . '/services/GitHubActionsOidcVerifier.php',
        'rsa_jwk_public_key' => __DIR__ . '/helpers/RsaJwkPublicKey.php',
        'test_auth_broker' => __DIR__ . '/staging-test-auth.php',
        'runtime_fingerprint_helper' => __DIR__ . '/helpers/StagingE2eSourceFingerprint.php',
        'runtime_fingerprint_manifest' => __DIR__ . '/helpers/staging-e2e-runtime-files.txt',
        'canonical_profile_endpoint' => __DIR__ . '/profile.php',
        'canonical_profile_service' => __DIR__ . '/accounts/MgwProfileService.php',
        'unified_balance_rule' => __DIR__ . '/economy/UnifiedBalanceMigrationRule.php',
        'unified_balance_coordinator' => __DIR__ . '/economy/UnifiedBalanceMigrationCoordinator.php',
        'unified_balance_runtime_sync' => __DIR__ . '/economy/UnifiedEconomyRuntimeSyncService.php',
    ];
    $capabilities = [];
    foreach ($required as $name => $path) {
        $present = is_file($path);
        $capabilities[$name] = $present;
        if (!$present) {
            throw new RuntimeException('Staging E2E runtime source is incomplete.');
        }
    }

    $fingerprint = (new StagingE2eSourceFingerprint(
        dirname(__DIR__),
        __DIR__ . '/helpers/staging-e2e-runtime-files.txt'
    ))->calculate();
    $capabilities['exact_runtime_fingerprint'] = true;

    // The staging host is owned by the private environment config rather than
    // hardcoded in repository code. This keeps host migrations from leaving CI
    // pointed at a dead historical domain while still requiring request/base
    // host identity, explicit staging mode and disabled live payments.
    if ($environment !== 'staging'
        || $baseHost === ''
        || $requestHost !== $baseHost
        || $livePayments) {
        throw new RuntimeException('Staging E2E environment is not isolated.');
    }

    echo json_encode([
        'ok' => true,
        'service' => 'mini-games-world-staging-e2e-readiness',
        'build' => 'mgw-staging-playwright-r15.3-v1',
        'environment' => 'staging',
        'base_host' => $baseHost,
        'source_fingerprint_sha256' => $fingerprint['sha256'],
        'runtime_file_count' => $fingerprint['file_count'],
        'capabilities' => $capabilities,
        'isolation' => [
            'request_matches_configured_staging_host' => true,
            'live_payments_disabled' => true,
            'production_changed' => false,
        ],
        'runtime_primary_diagnostic' => mgw_staging_runtime_primary_diagnostic($config),
        'server_time_utc' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging E2E readiness] unavailable: ' . get_class($error));
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'service' => 'mini-games-world-staging-e2e-readiness',
        'error' => 'e2e_readiness_unavailable',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
