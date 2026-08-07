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
    require_once __DIR__ . '/services/StagingFreshInviteReplacementRecoveryService.php';

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) !== 1) {
        throw new RuntimeException('GitHub OIDC authorization is required.');
    }
    $credential = trim((string)$match[1]);
    if (substr_count($credential, '.') !== 2) {
        throw new RuntimeException('GitHub OIDC authorization is required.');
    }

    (new GitHubActionsOidcVerifier($config))->verifyAndConsume($credential);
    $service = new StagingFreshInviteReplacementRecoveryService(
        $config,
        $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : null
    );
    $result = $service->reconcile($_SERVER);

    echo json_encode(
        $result + ['authorization_mode' => 'github_actions_oidc'],
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging fresh invite recovery] denied: ' . get_class($error));
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'service' => 'mini-games-world-staging-fresh-invite-replacement-recovery',
        'error' => 'recovery_unavailable',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
