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
    require_once __DIR__ . '/services/StagingTestOnlyInviteOrphanRecoveryService.php';

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

    $service = new StagingTestOnlyInviteOrphanRecoveryService(
        $config,
        $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : null
    );
    $result = $service->reconcile($_SERVER);
    echo json_encode(
        $result + ['authorization_mode' => 'github_actions_oidc'],
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $safeCode = $error instanceof ParseError ? 'parse_error' : 'unclassified_failure';
    $safeMap = [
        'unsafe A/B invite state' => 'unsafe_status_or_source',
        'matched A/B invite' => 'matched_invite',
        'match-referenced A/B invite' => 'match_referenced_invite',
        'ownership is unavailable' => 'ownership_unavailable',
        'ownership mismatch' => 'ownership_mismatch',
        'non-test notification state' => 'non_test_notification',
        'JSON-backed notification state' => 'json_backed_notification',
        'excessive candidates' => 'candidate_limit',
        'notification delete count is unexpected' => 'notification_delete_count',
        'event delete count is unexpected' => 'event_delete_count',
        'invite delete count is unexpected' => 'invite_delete_count',
        'invite parity did not recover' => 'invite_parity',
        'notification parity did not recover' => 'notification_parity',
        'requires DB invite routing' => 'routing_guard',
        'refuses live payments' => 'payment_guard',
        'requires database' => 'database_unavailable',
    ];
    foreach ($safeMap as $needle => $code) {
        if (str_contains($message, $needle)) {
            $safeCode = $code;
            break;
        }
    }
    error_log('[MiniGamesWorld staging test-only invite recovery] denied: ' . get_class($error) . ' code=' . $safeCode);
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'service' => 'mini-games-world-staging-test-only-invite-orphan-recovery',
        'error' => $safeCode,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
