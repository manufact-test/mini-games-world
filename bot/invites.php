<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GameInviteService.php';

function mgw_invite_share_url(array $config, string $token): string
{
    $username = ltrim(trim((string)($config['bot_username'] ?? '')), '@');

    if ($username === '') {
        try {
            $result = (new TelegramService($config))->api('getMe');
            if (!empty($result['ok']) && is_array($result['result'] ?? null)) {
                $username = ltrim(trim((string)($result['result']['username'] ?? '')), '@');
            }
        } catch (Throwable $e) {
            error_log('Mini Games World invite getMe failed: ' . $e->getMessage());
        }
    }

    if ($username !== '') {
        return 'https://t.me/' . rawurlencode($username) . '?start=invite_' . rawurlencode($token);
    }

    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    return $baseUrl . '/app/?v=76&invite=' . rawurlencode($token);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        api_error('Некорректный запрос.');
    }

    $action = clean_string($payload['action'] ?? '', 40);
    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $sessions = new SessionService($config);
    $catalog = new GameCatalogService($config);
    $invites = new GameInviteService($config, $catalog);
    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->transaction(function (array &$data) use (
        $action,
        $payload,
        $tgUser,
        $users,
        $sessions,
        $invites,
        $sessionId
    ): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $sessions->ensureSessionShape($user);

        return match ($action) {
            'create' => (function () use (&$data, &$user, $payload, $sessions, $invites, $sessionId): array {
                $sessions->assertCanPlay($user, $sessionId);
                $sessions->touch($user, $sessionId);
                return [
                    'invite' => $invites->create(
                        $data,
                        $user,
                        clean_string($payload['gameType'] ?? 'tictactoe', 60),
                        clean_string($payload['room'] ?? 'match', 20),
                        (int)($payload['bet'] ?? 10),
                        (int)($payload['boardSize'] ?? 3)
                    ),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'resolve' => (function () use (&$data, &$user, $payload, $sessions, $invites, $sessionId): array {
                if (in_array((string)($user['status'] ?? 'idle'), ['searching', 'playing'], true)) {
                    throw new RuntimeException('Сначала завершите текущий поиск или игру.');
                }
                return [
                    'invite' => $invites->resolve($data, $user, clean_string($payload['token'] ?? '', 80)),
                    'session' => $sessions->publicState($user, $sessionId),
                ];
            })(),
            'decline' => [
                'invite' => $invites->decline($data, $user, clean_string($payload['token'] ?? '', 80)),
                'session' => $sessions->publicState($user, $sessionId),
            ],
            default => throw new RuntimeException('Неизвестное действие приглашения.'),
        };
    });

    if ($action === 'create' && is_array($result['invite'] ?? null)) {
        $token = (string)($result['invite']['token'] ?? '');
        $result['invite']['share_url'] = mgw_invite_share_url($config, $token);
        $result['invite']['share_text'] = 'Сыграем в «' . (string)($result['invite']['game_title'] ?? 'Mini Games World') . '»?';
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
