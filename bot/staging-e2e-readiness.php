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
