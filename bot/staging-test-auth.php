<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

try {
    require __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/services/StagingTestAuthService.php';
    require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 4096) {
        throw new RuntimeException('Invalid staging test authorization request.');
    }
    $payload = json_decode($raw !== '' ? $raw : '{}', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid staging test authorization request.');
    }

    $allowedKeys = ['action', 'slot'];
    foreach (array_keys($payload) as $key) {
        if (!in_array((string)$key, $allowedKeys, true)) {
            throw new RuntimeException('Unknown staging test authorization field.');
        }
    }

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    $providedCredential = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
        $providedCredential = trim((string)$match[1]);
    }

    $service = new StagingTestAuthService($config);
    $action = strtolower(trim((string)($payload['action'] ?? 'issue')));
    $authorizationMode = 'shared_secret';

    if ($action === 'issue') {
        $providedSecret = $providedCredential;
        if (substr_count($providedCredential, '.') === 2) {
            (new GitHubActionsOidcVerifier($config))->verifyAndConsume($providedCredential);
            $providedSecret = trim((string)($config['staging_test_auth_secret'] ?? ''));
            if ($providedSecret === '') {
                $providedSecret = trim((string)($config['setup_secret'] ?? ''));
            }
            $authorizationMode = 'github_actions_oidc';
        }

        $issued = $service->issue((string)($payload['slot'] ?? ''), $providedSecret, $_SERVER);
        setcookie(
            StagingTestAuthService::COOKIE_NAME,
            (string)$issued['token'],
            $service->cookieOptions((int)$issued['expires_at'])
        );

        echo json_encode([
            'ok' => true,
            'service' => 'mini-games-world-staging-test-auth',
            'action' => 'issued',
            'authorization_mode' => $authorizationMode,
            'player_slot' => $issued['slot'],
            'expires_at_utc' => $issued['expires_at_utc'],
            'ttl_seconds' => $issued['ttl_seconds'],
            'cookie' => [
                'http_only' => true,
                'secure' => true,
                'same_site' => 'Strict',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }

    if ($action === 'revoke') {
        $service->revokeCurrent($_COOKIE, $_SERVER);
        setcookie(
            StagingTestAuthService::COOKIE_NAME,
            '',
            $service->cookieOptions(time() - 3600)
        );
        echo json_encode([
            'ok' => true,
            'service' => 'mini-games-world-staging-test-auth',
            'action' => 'revoked',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }

    throw new RuntimeException('Unknown staging test authorization action.');
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging test auth] denied: ' . get_class($error));
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'service' => 'mini-games-world-staging-test-auth',
        'error' => 'test_auth_unavailable',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
