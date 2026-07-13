<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/NotificationService.php';

function mgw_visible_notification_ids(array $data, string $userId): array
{
    $invitesByToken = [];
    foreach ($data['invites'] ?? [] as $invite) {
        if (!is_array($invite)) continue;
        $token = (string)($invite['token'] ?? '');
        if ($token !== '') $invitesByToken[$token] = $invite;
    }

    $visible = [];
    foreach ($data['notifications'] ?? [] as $notification) {
        if (!is_array($notification) || (string)($notification['user_id'] ?? '') !== $userId) continue;

        $id = (string)($notification['id'] ?? '');
        $type = (string)($notification['type'] ?? '');
        if ($id === '') continue;

        if (!str_starts_with($type, 'invite_')) {
            $visible[$id] = true;
            continue;
        }

        $token = (string)($notification['invite_token'] ?? '');
        $invite = $token !== '' ? ($invitesByToken[$token] ?? null) : null;
        if (!is_array($invite)) {
            $visible[$id] = true;
            continue;
        }

        $status = (string)($invite['status'] ?? '');
        if ($type === 'invite_received') {
            if ($status === 'pending') $visible[$id] = true;
            continue;
        }

        if ($type === 'invite_accepted') {
            if ($status === 'awaiting_start') $visible[$id] = true;
            continue;
        }

        if ($type === 'invite_started') {
            $gameId = (string)($invite['game_id'] ?? '');
            $game = $gameId !== '' ? ($data['games'][$gameId] ?? null) : null;
            if (is_array($game) && (string)($game['status'] ?? '') === 'active') $visible[$id] = true;
            continue;
        }

        // Terminal invite notifications such as cancellation or timeout remain in history.
        $visible[$id] = true;
    }

    return $visible;
}

function mgw_visible_notifications(array $data, NotificationService $notifications, string $userId, int $limit): array
{
    $visibleIds = mgw_visible_notification_ids($data, $userId);
    $items = $notifications->userNotifications($data, $userId, max($limit * 3, 60));
    $items = array_values(array_filter($items, static function (array $item) use ($visibleIds): bool {
        return isset($visibleIds[(string)($item['id'] ?? '')]);
    }));
    return array_slice($items, 0, $limit);
}

function mgw_visible_unread_count(array $data, string $userId): int
{
    $visibleIds = mgw_visible_notification_ids($data, $userId);
    $count = 0;
    foreach ($data['notifications'] ?? [] as $notification) {
        if (!is_array($notification)) continue;
        if ((string)($notification['user_id'] ?? '') !== $userId || !empty($notification['read_at'])) continue;
        if (isset($visibleIds[(string)($notification['id'] ?? '')])) $count++;
    }
    return $count;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        api_error('Некорректный запрос.');
    }

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') {
        api_error('Пользователь не найден.');
    }

    $markRead = !empty($payload['markRead']);
    $db = new JsonDatabase((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $notifications = new NotificationService();

    if ($markRead) {
        $result = $db->transaction(function (array &$data) use ($notifications, $userId): array {
            $items = mgw_visible_notifications($data, $notifications, $userId, 30);
            $notifications->markAllRead($data, $userId);
            return [
                'items' => $items,
                'unread_count' => 0,
            ];
        });
    } else {
        $result = $db->readOnly(function (array $data) use ($notifications, $userId): array {
            return [
                'items' => mgw_visible_notifications($data, $notifications, $userId, 30),
                'unread_count' => mgw_visible_unread_count($data, $userId),
            ];
        });
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
