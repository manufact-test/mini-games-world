<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/NotificationService.php';

function mgw_notification_invites_by_token(array $data): array
{
    $result = [];
    foreach ($data['invites'] ?? [] as $invite) {
        if (!is_array($invite)) continue;
        $token = (string)($invite['token'] ?? '');
        if ($token === '') continue;
        $status = (string)($invite['status'] ?? '');
        if ($status === 'awaiting_start') $invite['status'] = 'accepted';
        elseif ($status === 'started') $invite['status'] = 'active';
        $result[$token] = $invite;
    }
    return $result;
}

function mgw_notification_is_received_type(string $type): bool
{
    return in_array($type, ['invite_received', 'invite_rematch_received'], true);
}

function mgw_notification_is_visible(array $item, ?array $invite, string $userId = ''): bool
{
    $type = (string)($item['type'] ?? '');
    if (!str_starts_with($type, 'invite_')) return true;
    if (in_array($type, ['invite_expired', 'invite_timed_out'], true)) return false;
    if (!is_array($invite)) return true;

    $status = (string)($invite['status'] ?? '');
    if (mgw_notification_is_received_type($type)) {
        return in_array($status, ['pending', 'accepted', 'declined'], true);
    }
    if ($type === 'invite_accepted') {
        return $status === 'accepted';
    }
    return true;
}

function mgw_notification_actions(array $item, ?array $invite, string $userId): array
{
    if (!is_array($invite)) return [];
    $status = (string)($invite['status'] ?? '');
    $owner = (string)($invite['inviter_id'] ?? '') === $userId;
    $invitee = (string)($invite['invitee_id'] ?? '') === $userId;

    if ($status === 'pending' && $invitee) return ['accept', 'decline'];
    if ($status === 'accepted' && $owner) return ['start', 'cancel'];
    if ($status === 'accepted' && $invitee) return ['cancel'];
    return [];
}

function mgw_notification_decorate(array $item, ?array $invite, string $userId): array
{
    if (!is_array($invite)) return $item;
    $type = (string)($item['type'] ?? '');
    if (!mgw_notification_is_received_type($type)) return $item;

    $status = (string)($invite['status'] ?? '');
    if ($status === 'accepted') {
        $item['title'] = 'Приглашение принято';
        $item['message'] = 'Ждём запуска матча от пригласившего игрока.';
        $item['tone'] = 'success';
        $item['read'] = true;
        return $item;
    }

    $isInvitee = (string)($invite['invitee_id'] ?? '') === $userId;
    if ($status === 'declined' && $isInvitee) {
        $inviterName = trim((string)($invite['inviter_name'] ?? 'Игрок')) ?: 'Игрок';
        $gameTitle = trim((string)($invite['game_title'] ?? 'игру')) ?: 'игру';
        $item['title'] = 'Приглашение отклонено';
        $item['message'] = 'Вы отклонили приглашение от ' . $inviterName
            . ' сыграть в «' . $gameTitle . '».';
        $item['tone'] = 'warning';
        $item['read'] = true;
        $item['created_at'] = (string)($invite['declined_at'] ?? $invite['updated_at'] ?? $item['created_at'] ?? '');
    }
    return $item;
}

function mgw_visible_notifications(
    array $data,
    NotificationService $notifications,
    string $userId,
    int $limit
): array {
    $invites = mgw_notification_invites_by_token($data);
    $items = $notifications->userNotifications($data, $userId, max(60, $limit * 3));
    $visible = [];

    foreach ($items as $item) {
        $token = (string)($item['invite_token'] ?? '');
        $invite = $token !== '' ? ($invites[$token] ?? null) : null;
        if (!mgw_notification_is_visible($item, $invite, $userId)) continue;

        $item = mgw_notification_decorate($item, $invite, $userId);
        $item['actions'] = mgw_notification_actions($item, $invite, $userId);
        if (is_array($invite)) {
            $item['invite_status'] = (string)($invite['status'] ?? '');
            $item['game_title'] = (string)($invite['game_title'] ?? '');
        }
        $visible[] = $item;
        if (count($visible) >= $limit) break;
    }

    return $visible;
}

function mgw_visible_unread_count(array $data, string $userId): int
{
    $invites = mgw_notification_invites_by_token($data);
    $count = 0;
    foreach ($data['notifications'] ?? [] as $notification) {
        if (!is_array($notification)) continue;
        if ((string)($notification['user_id'] ?? '') !== $userId) continue;
        if (!empty($notification['hidden_at']) || !empty($notification['read_at'])) continue;

        $token = (string)($notification['invite_token'] ?? '');
        $invite = $token !== '' ? ($invites[$token] ?? null) : null;
        if (!mgw_notification_is_visible($notification, $invite, $userId)) continue;

        $type = (string)($notification['type'] ?? '');
        $status = is_array($invite) ? (string)($invite['status'] ?? '') : '';
        if (mgw_notification_is_received_type($type) && $status !== 'pending') continue;
        $count++;
    }
    return $count;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') api_error('Пользователь не найден.');

    $markRead = !empty($payload['markRead']);
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $notifications = new NotificationService();
    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter
        ? $runtimeStorageRouter
        : new RuntimeStorageRouter($config);
    $legacyBridgeAllowed = RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed();

    if ($legacyBridgeAllowed
        && $router->routeFor('notifications') === RuntimeStorageRouter::DRIVER_DATABASE) {
        $snapshot = $markRead
            ? $db->transaction(function (array &$data) use ($notifications, $userId): array {
                $notifications->markAllRead($data, $userId);
                return $data;
            })
            : $db->readOnly(static fn(array $data): array => $data);

        $runtimeNotifications = new RuntimeNotificationRepository($config, $router);
        $synchronized = $runtimeNotifications->synchronizeAndList(
            $snapshot,
            $userId,
            (string)($tgUser['mgw_id'] ?? '')
        );
        $snapshot['notifications'] = $synchronized['items'];
        $result = [
            'items' => mgw_visible_notifications($snapshot, $notifications, $userId, 30),
            'unread_count' => $markRead ? 0 : mgw_visible_unread_count($snapshot, $userId),
        ];
    } elseif ($markRead) {
        $result = $db->transaction(function (array &$data) use ($notifications, $userId): array {
            $items = mgw_visible_notifications($data, $notifications, $userId, 30);
            $notifications->markAllRead($data, $userId);
            foreach ($items as &$item) $item['read'] = true;
            unset($item);
            return ['items' => $items, 'unread_count' => 0];
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
