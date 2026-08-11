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

$stage = 'bootstrap';

try {
    require __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';
    require_once __DIR__ . '/services/StagingTestOnlyInviteOrphanRecoveryService.php';

    $stage = 'authorization_header';
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

    $stage = 'oidc_verify';
    (new GitHubActionsOidcVerifier($config))->verifyAndConsume($credential);

    $stage = 'recovery';
    $service = new StagingTestOnlyInviteOrphanRecoveryService(
        $config,
        $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : null
    );
    $result = $service->reconcile($_SERVER);

    $stage = 'response';
    echo json_encode(
        $result + ['authorization_mode' => 'github_actions_oidc'],
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $error) {
    $reasonCode = mgwStagingTestOnlyRecoveryReasonCode($error, $stage);
    error_log(
        '[MiniGamesWorld staging test-only invite recovery] denied: '
        . get_class($error)
        . ' stage=' . $stage
        . ' reason=' . $reasonCode
    );
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'service' => 'mini-games-world-staging-test-only-invite-orphan-recovery',
        'error' => 'test_only_invite_recovery_unavailable',
        'stage' => $stage,
        'reason_code' => $reasonCode,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function mgwStagingTestOnlyRecoveryReasonCode(Throwable $error, string $stage): string
{
    if ($stage === 'authorization_header') {
        return 'authorization_header_invalid';
    }
    if ($stage === 'oidc_verify') {
        return 'oidc_verification_failed';
    }
    if ($stage === 'bootstrap') {
        return 'bootstrap_failed';
    }
    if ($stage === 'response') {
        return 'response_encoding_failed';
    }

    $message = $error->getMessage();
    $known = [
        'Staging test-only orphan recovery is unavailable.' => 'staging_guard_unavailable',
        'Staging test-only orphan recovery requires DB invite routing.' => 'db_routing_unavailable',
        'Staging test-only orphan recovery refuses live payments.' => 'live_payment_guard',
        'Staging test-only orphan data directory is unavailable.' => 'json_data_dir_unavailable',
        'Staging test-only orphan JSON snapshot is unavailable.' => 'json_snapshot_unavailable',
        'Staging test-only orphan recovery requires database.' => 'database_unavailable',
        'Staging test-only orphan recovery found unsafe A/B invite state.' => 'unsafe_invite_state',
        'Staging test-only orphan recovery refuses excessive candidates.' => 'excessive_candidates',
        'Staging test-only orphan ownership is unavailable.' => 'ownership_unavailable',
        'Staging test-only orphan ownership mismatch.' => 'ownership_mismatch',
        'Staging test-only orphan recovery refuses non-test notification state.' => 'non_test_notification_state',
        'Staging test-only orphan recovery refuses JSON-backed notification state.' => 'json_backed_notification_state',
        'Staging test-only orphan notification delete count is unexpected.' => 'notification_delete_mismatch',
        'Staging test-only orphan event delete count is unexpected.' => 'event_delete_mismatch',
        'Staging test-only orphan invite delete count is unexpected.' => 'invite_delete_mismatch',
        'Staging test-only orphan invite parity did not recover.' => 'invite_parity_failed',
        'Staging test-only orphan notification parity did not recover.' => 'notification_parity_failed',
    ];

    return $known[$message] ?? 'recovery_failed';
}
