<?php
declare(strict_types=1);

final class JsonAccountPassiveBaselineScenario
{
    public const CONTRACT_VERSION = 'mvp14r2-account-passive-v1';

    public function run(JsonBehaviorBaselineFixture $fixture): array
    {
        $scenario = $fixture->scenario();
        $state = $fixture->state();
        $input = $scenario['input'] ?? null;
        if (!is_array($input) || array_is_list($input)) {
            throw new RuntimeException('Account/passive baseline input must be an object.');
        }

        $action = strtolower(trim((string)($input['action'] ?? '')));
        if (!in_array($action, ['bootstrap', 'profile', 'session_state', 'notifications'], true)) {
            throw new RuntimeException('Account/passive baseline action is unsupported.');
        }
        $userId = trim((string)($input['user_id'] ?? ''));
        $sessionId = trim((string)($input['session_id'] ?? ''));
        if ($userId === '' || !isset($state['users'][$userId]) || !is_array($state['users'][$userId])) {
            throw new RuntimeException('Account/passive baseline user is unavailable.');
        }

        $config = array_replace([
            'admin_ids' => [],
            'shop_min_order' => 1000,
            'active_session_timeout_sec' => 180,
        ], is_array($input['config'] ?? null) ? $input['config'] : []);

        $before = $this->domainSnapshot($state, $userId);
        [$payload, $afterState] = $this->project($state, $userId, $sessionId, $action, $config);
        $after = $this->domainSnapshot($afterState, $userId);
        if ($before !== $after) {
            throw new RuntimeException('Account/passive baseline projection mutated domain state.');
        }

        [$retryPayload, $retryState] = $this->project($state, $userId, $sessionId, $action, $config);
        $retryStable = $payload === $retryPayload
            && $before === $this->domainSnapshot($retryState, $userId);
        if (!$retryStable) {
            throw new RuntimeException('Account/passive baseline retry is not deterministic.');
        }

        $result = new JsonBehaviorBaselineResult($fixture->normalizer());
        return $result->build([
            'scenario_id' => (string)($scenario['id'] ?? ''),
            'input' => [
                'action' => $action,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'config' => $config,
            ],
            'public_result' => [
                'status' => 200,
                'payload' => $payload,
            ],
            'domains' => [
                'before' => $before,
                'after' => $after,
            ],
            'side_effects' => [
                'notifications' => [],
                'events' => [],
                'ledger' => [],
            ],
            'retry' => [
                'attempted' => true,
                'result' => ['stable' => true],
            ],
            'conflict' => [
                'attempted' => $action === 'session_state',
                'result' => $action === 'session_state'
                    ? [
                        'locked' => (bool)($payload['session']['locked'] ?? false),
                        'message' => $payload['session']['message'] ?? null,
                    ]
                    : ['applicable' => false],
            ],
            'latency' => [
                'measured' => false,
                'samples' => 0,
                'cold_ms' => null,
                'warm_ms' => null,
            ],
        ]);
    }

    private function project(
        array $state,
        string $userId,
        string $sessionId,
        string $action,
        array $config
    ): array {
        $user = $state['users'][$userId];
        $users = new UserService($config);
        $sessions = new SessionService($config);
        $notifications = new NotificationService();
        $sessions->ensureSessionShape($user);

        $payload = match ($action) {
            'bootstrap' => [
                'user' => $users->publicUser($user),
                'session' => $sessions->publicState($user, $sessionId),
                'notifications' => [
                    'unread_count' => $notifications->unreadCount($state, $userId),
                ],
            ],
            'profile' => [
                'user' => $users->publicUser($user),
                'stats' => $users->profileStats($user, $state),
                'session' => $sessions->publicState($user, $sessionId),
            ],
            'session_state' => [
                'session' => $sessions->publicState($user, $sessionId),
            ],
            'notifications' => [
                'items' => $notifications->userNotifications(
                    $state,
                    $userId,
                    max(1, min(100, (int)($config['notification_limit'] ?? 30)))
                ),
                'unread_count' => $notifications->unreadCount($state, $userId),
                'session' => $sessions->publicState($user, $sessionId),
            ],
        };

        $state['users'][$userId] = $user;
        return [$payload, $state];
    }

    private function domainSnapshot(array $state, string $userId): array
    {
        $games = [];
        foreach ($state['games'] ?? [] as $game) {
            if (!is_array($game)) continue;
            $players = array_map('strval', $game['player_ids'] ?? []);
            if (in_array($userId, $players, true)) $games[] = $game;
        }
        usort($games, static fn(array $left, array $right): int => strcmp(
            (string)($left['id'] ?? ''),
            (string)($right['id'] ?? '')
        ));

        $notifications = [];
        foreach ($state['notifications'] ?? [] as $notification) {
            if (is_array($notification)
                && (string)($notification['user_id'] ?? '') === $userId) {
                $notifications[] = $notification;
            }
        }
        usort($notifications, static fn(array $left, array $right): int => strcmp(
            (string)($left['id'] ?? ''),
            (string)($right['id'] ?? '')
        ));

        return [
            'account' => $state['users'][$userId],
            'games' => $games,
            'notifications' => $notifications,
        ];
    }
}
