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

    $expectedHost = 'seashell-okapi-889488.hostingersite.com';
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
    ];
    $capabilities = [];
    $fingerprintParts = [];
    foreach ($required as $name => $path) {
        $present = is_file($path);
        $capabilities[$name] = $present;
        if (!$present) {
            throw new RuntimeException('Staging E2E runtime source is incomplete.');
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Staging E2E runtime source cannot be fingerprinted.');
        }
        $fingerprintParts[] = $name . ':' . $hash;
    }

    if ($environment !== 'staging'
        || $baseHost !== $expectedHost
        || $requestHost !== $expectedHost
        || $livePayments) {
        throw new RuntimeException('Staging E2E environment is not isolated.');
    }

    echo json_encode([
        'ok' => true,
        'service' => 'mini-games-world-staging-e2e-readiness',
        'build' => 'mgw-staging-playwright-r13.4-v1',
        'environment' => 'staging',
        'base_host' => $expectedHost,
        'source_fingerprint_sha256' => hash('sha256', implode("\n", $fingerprintParts)),
        'capabilities' => $capabilities,
        'isolation' => [
            'exact_staging_host' => true,
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
