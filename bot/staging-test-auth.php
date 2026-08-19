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
    require_once __DIR__ . '/services/StagingTestInviteResidualRecoveryService.php';
    require_once __DIR__ . '/services/StagingTestPlayerStateResetService.php';

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

    $residualService = static function () use ($config, $runtimeStorageRouter): StagingTestInviteResidualRecoveryService {
        return new StagingTestInviteResidualRecoveryService(
            $config,
            $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : null
        );
    };
    $playerResetService = static function () use ($config, $runtimeStorageRouter): StagingTestPlayerStateResetService {
        return new StagingTestPlayerStateResetService(
            $config,
            $runtimeStorageRouter instanceof RuntimeStorageRouter ? $runtimeStorageRouter : null
        );
    };
    $resetReasonCode = static function (StagingTestPlayerResetStageException $error): string {
        $previous = $error->getPrevious();
        $message = $previous instanceof Throwable ? $previous->getMessage() : '';

        return match (true) {
            $message === 'Staging test users are unavailable.' => 'test_users_unavailable',
            $message === 'Staging test player is not initialized.' => 'test_player_uninitialized',
            $message === 'Canonical balance has legacy fields but no unification audit metadata.' => 'test_user_balance_metadata_missing',
            $message === 'Unified balance migration version mismatch.' => 'test_user_balance_version_mismatch',
            $message === 'Unified balance legacy breakdown is inconsistent.' => 'test_user_balance_breakdown_invalid',
            $message === 'Unified balance migration target is invalid.' => 'test_user_balance_target_invalid',
            str_starts_with($message, 'Invalid unified balance field:') => 'test_user_balance_field_invalid',
            str_starts_with($message, 'Negative unified balance field:') => 'test_user_balance_field_negative',
            str_starts_with($message, 'Unified balance field exceeds integer range:') => 'test_user_balance_field_overflow',
            $message === 'Unified balance migration would overflow integer range.' => 'test_user_balance_overflow',
            $message === 'Staging test reset refuses an active game with a non-test player.' => 'active_test_game_mixed_owner',
            $message === 'Staging test active-game participant is unavailable.' => 'active_test_game_participant_missing',
            $message === 'Staging test reset refuses an invite with a non-test player.' => 'test_invite_mixed_owner',
            $message === 'Staging test reset refuses an invite without stable identity.' => 'test_invite_identity_missing',
            $message === 'Staging test reset refuses an active invite without game identity.' => 'started_invite_game_id_missing',
            $message === 'Staging test reset refuses an active invite with malformed game state.' => 'started_invite_game_malformed',
            $message === 'Staging test reset refuses an active invite with unknown linked game state.' => 'started_invite_game_status_unknown',
            $message === 'Staging test reset cannot prove linked game ownership.' => 'started_invite_game_owner_unknown',
            $message === 'Staging test reset refuses an active invite linked to a non-test game.' => 'started_invite_game_mixed_owner',
            default => $error->stage() . '_unclassified',
        };
    };

    if ($action === 'diagnose_invite_residuals') {
        if (array_key_exists('slot', $payload) || substr_count($providedCredential, '.') !== 2) {
            throw new RuntimeException('Staging test invite residual diagnosis requires GitHub OIDC.');
        }
        (new GitHubActionsOidcVerifier($config))->verifyAndConsume($providedCredential);
        $result = $residualService()->diagnose($_SERVER);

        echo json_encode(
            $result + ['authorization_mode' => 'github_actions_oidc'],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit;
    }

    if ($action === 'reconcile_invite_residuals') {
        if (array_key_exists('slot', $payload) || substr_count($providedCredential, '.') !== 2) {
            throw new RuntimeException('Staging test invite residual recovery requires GitHub OIDC.');
        }
        (new GitHubActionsOidcVerifier($config))->verifyAndConsume($providedCredential);
        $result = $residualService()->reconcile($_SERVER);

        echo json_encode(
            $result + ['authorization_mode' => 'github_actions_oidc'],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit;
    }

    if ($action === 'reset_test_players') {
        if (array_key_exists('slot', $payload) || substr_count($providedCredential, '.') !== 2) {
            throw new RuntimeException('Staging test-player reset requires GitHub OIDC.');
        }
        (new GitHubActionsOidcVerifier($config))->verifyAndConsume($providedCredential);
        try {
            $result = $playerResetService()->reset($_SERVER);
        } catch (StagingTestPlayerResetStageException $error) {
            $reasonCode = $resetReasonCode($error);
            error_log('[MiniGamesWorld staging test reset] failed stage=' . $error->stage() . ' reason=' . $reasonCode);
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'service' => 'mini-games-world-staging-test-auth',
                'error' => 'test_player_reset_unavailable',
                'stage' => $error->stage(),
                'reason_code' => $reasonCode,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit;
        }

        echo json_encode(
            $result + ['authorization_mode' => 'github_actions_oidc'],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit;
    }

    if ($action === 'issue') {
        $slot = strtoupper(trim((string)($payload['slot'] ?? '')));
        $providedSecret = $providedCredential;
        if (substr_count($providedCredential, '.') === 2) {
            (new GitHubActionsOidcVerifier($config))->verifyAndConsume($providedCredential);
            $providedSecret = trim((string)($config['staging_test_auth_secret'] ?? ''));
            if ($providedSecret === '') {
                $providedSecret = trim((string)($config['setup_secret'] ?? ''));
            }
            $authorizationMode = 'github_actions_oidc';
        }

        $issued = $service->issue($slot, $providedSecret, $_SERVER);
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
            'test_player_state_reset' => null,
            'invite_residual_recovery' => null,
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
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
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
