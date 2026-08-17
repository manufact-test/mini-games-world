<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/GameInviteService.php';
require_once __DIR__ . '/notifications/RuntimeNotificationBridgeCoordinator.php';
require_once __DIR__ . '/notifications/NotificationCenterV2Policy.php';

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

function mgw_notification_canonical_tone(array $item): array
{
    $type = (string)($item['type'] ?? '');
    if (in_array($type, ['invite_accepted', 'invite_started'], true)) {
        $item['tone'] = 'success';
    } elseif ($type === 'invite_declined') {
        $item['tone'] = 'danger';
    } elseif (in_array($type, ['invite_received', 'invite_rematch_received', 'invite_cancelled', 'invite_expired', 'invite_timed_out'], true)) {
        $item['tone'] = 'info';
    } else {
        $tone = (string)($item['tone'] ?? 'info');
        $item['tone'] = in_array($tone, ['success', 'danger', 'info'], true) ? $tone : 'info';
    }
    return $item;
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
    $item = mgw_notification_canonical_tone($item);
    if (!is_array($invite)) return $item;
    $type = (string)($item['type'] ?? '');
    $status = (string)($invite['status'] ?? '');

    if ($type === 'invite_cancelled' && in_array($status, ['cancelled', 'canceled'], true)) {
        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        $cancelledBy = (string)($invite['cancelled_by'] ?? '');
        $inviterName = trim((string)($invite['inviter_name'] ?? 'Игрок')) ?: 'Игрок';
        $inviteeName = trim((string)($invite['invitee_name'] ?? 'Игрок')) ?: 'Игрок';
        $gameTitle = trim((string)($invite['game_title'] ?? 'Игра')) ?: 'Игра';
        $inviterCancelled = $cancelledBy !== ''
            ? $cancelledBy === $inviterId
            : $userId === $inviteeId;

        $item['title'] = $inviterCancelled ? 'Приглашение отменено' : 'Соперник отменил участие';
        $item['message'] = $inviterCancelled
            ? $inviterName . ' отменил приглашение сыграть в «' . $gameTitle . '».'
            : $inviteeName . ' отменил участие в матче «' . $gameTitle . '».';
        $item['tone'] = 'info';
        $item['created_at'] = (string)($invite['cancelled_at'] ?? $invite['updated_at'] ?? $item['created_at'] ?? '');
        return $item;
    }

    if (!mgw_notification_is_received_type($type)) return $item;

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
        $item['tone'] = 'danger';
        $item['read'] = true;
        $item['created_at'] = (string)($invite['declined_at'] ?? $invite['updated_at'] ?? $item['created_at'] ?? '');
    }
    return $item;
}

function mgw_notification_raw_by_id(array $data, string $userId): array
{
    $result = [];
    foreach ($data['notifications'] ?? [] as $notification) {
        if (!is_array($notification)) continue;
        if ((string)($notification['user_id'] ?? '') !== $userId) continue;
        $id = trim((string)($notification['id'] ?? ''));
        if ($id !== '') $result[$id] = $notification;
    }
    return $result;
}

function mgw_notification_apply_v2_contract(array $item, array $raw, ?array $invite): array
{
    $source = array_replace($raw, $item);
    $item['event_id'] = NotificationCenterV2Policy::eventId($source);
    $item['expires_at'] = NotificationCenterV2Policy::expiresAt($source, $invite);
    $item['deep_link'] = NotificationCenterV2Policy::deepLink($source);
    $item['active'] = !NotificationCenterV2Policy::isExpired($source, $invite);
    return $item;
}

function mgw_visible_notifications(
    array $data,
    NotificationService $notifications,
    GameInviteService $inviteViews,
    string $userId,
    int $limit
): array {
    $invites = mgw_notification_invites_by_token($data);
    $rawById = mgw_notification_raw_by_id($data, $userId);
    $items = $notifications->userNotifications($data, $userId, max(60, $limit * 3));
    $visible = [];

    foreach ($items as $item) {
        $raw = $rawById[(string)($item['id'] ?? '')] ?? $item;
        $token = (string)($item['invite_token'] ?? '');
        $invite = $token !== '' ? ($invites[$token] ?? null) : null;
        if (NotificationCenterV2Policy::isExpired($raw, $invite)) continue;
        if (!mgw_notification_is_visible($item, $invite, $userId)) continue;

        $item = mgw_notification_decorate($item, $invite, $userId);
        $item = mgw_notification_apply_v2_contract($item, $raw, $invite);
        $item['actions'] = mgw_notification_actions($item, $invite, $userId);
        if (is_array($invite)) {
            $item['invite_status'] = (string)($invite['status'] ?? '');
            $item['game_title'] = (string)($invite['game_title'] ?? '');
            $item['inviter_name'] = (string)($invite['inviter_name'] ?? '');
            $item['invitee_name'] = (string)($invite['invitee_name'] ?? '');
            $item['invite_is_owner'] = (string)($invite['inviter_id'] ?? '') === $userId;
            $item['invite_snapshot'] = $inviteViews->notificationSnapshot($invite, $userId);
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
        if (NotificationCenterV2Policy::isExpired($notification, $invite)) continue;
        if (!mgw_notification_is_visible($notification, $invite, $userId)) continue;

        $type = (string)($notification['type'] ?? '');
        $status = is_array($invite) ? (string)($invite['status'] ?? '') : '';
        if (mgw_notification_is_received_type($type) && $status !== 'pending') continue;
        $count++;
    }
    return $count;
}

function mgw_consume_invite_notifications(array &$data, string $userId, string $token): void
{
    $token = trim($token);
    if (!preg_match('/^[A-Za-z0-9_-]{12,80}$/', $token)) return;
    if (!isset($data['notifications']) || !is_array($data['notifications'])) return;
    $now = now_iso();
    foreach ($data['notifications'] as &$notification) {
        if (!is_array($notification)) continue;
        if ((string)($notification['user_id'] ?? '') !== $userId) continue;
        if ((string)($notification['invite_token'] ?? '') !== $token) continue;
        if (empty($notification['read_at'])) $notification['read_at'] = $now;
        if (empty($notification['hidden_at'])) $notification['hidden_at'] = $now;
    }
    unset($notification);
}

function mgw_mutate_notification_center_v2(
    array &$data,
    NotificationService $notifications,
    string $userId,
    bool $markAll,
    string $readNotificationId,
    string $deleteNotificationId,
    string $consumeInviteToken
): void {
    if ($markAll) {
        $notifications->markAllRead($data, $userId);
        return;
    }
    if ($readNotificationId !== '') {
        if (!isset($data['notifications']) || !is_array($data['notifications'])) return;
        NotificationCenterV2Policy::markOneRead($data['notifications'], $userId, $readNotificationId, now_iso());
        return;
    }
    if ($deleteNotificationId !== '') {
        if (!isset($data['notifications']) || !is_array($data['notifications'])) return;
        NotificationCenterV2Policy::hideOne($data['notifications'], $userId, $deleteNotificationId, now_iso());
        return;
    }
    if ($consumeInviteToken !== '') {
        mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
    }
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') api_error('Пользователь не найден.');

    $markRead = !empty($payload['markRead']);
    $readNotificationId = trim((string)($payload['readNotificationId'] ?? ''));
    $deleteNotificationId = trim((string)($payload['deleteNotificationId'] ?? ''));
    $consumeInviteToken = trim((string)($payload['consumeInviteToken'] ?? ''));
    $commands = ($markRead ? 1 : 0)
        + ($readNotificationId !== '' ? 1 : 0)
        + ($deleteNotificationId !== '' ? 1 : 0)
        + ($consumeInviteToken !== '' ? 1 : 0);
    if ($commands > 1) api_error('Одновременно можно изменить только одно состояние уведомлений.');

    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $notifications = new NotificationService();
    $inviteCatalog = new GameCatalogService($config);
    $inviteViews = new GameInviteService(
        $config,
        $inviteCatalog,
        new ChessRuntimeService($config, $inviteCatalog, new GameService($config))
    );
    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter
        ? $runtimeStorageRouter
        : new RuntimeStorageRouter($config);
    $legacyBridgeAllowed = RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed();

    if ($legacyBridgeAllowed
        && $router->routeFor('notifications') === RuntimeStorageRouter::DRIVER_DATABASE) {
        if (!$db instanceof ExclusiveSnapshotStorageInterface) {
            throw new RuntimeException('Notification bridge requires exclusive JSON snapshots.');
        }

        if ($commands === 1) {
            $db->transaction(function (array &$data) use (
                $notifications,
                $userId,
                $markRead,
                $readNotificationId,
                $deleteNotificationId,
                $consumeInviteToken
            ): void {
                mgw_mutate_notification_center_v2(
                    $data,
                    $notifications,
                    $userId,
                    $markRead,
                    $readNotificationId,
                    $deleteNotificationId,
                    $consumeInviteToken
                );
            });
        }

        $runtimeNotifications = new RuntimeNotificationBridgeCoordinator($config, $router);
        $result = $db->exclusiveReadOnlySections(
            ['invites', 'notifications'],
            function (array $snapshot) use (
                $runtimeNotifications,
                $notifications,
                $inviteViews,
                $userId,
                $tgUser
            ): array {
                $synchronized = $runtimeNotifications->synchronizeAndList(
                    $snapshot,
                    $userId,
                    (string)($tgUser['mgw_id'] ?? '')
                );
                $snapshot['notifications'] = $synchronized['items'];
                return [
                    'items' => mgw_visible_notifications($snapshot, $notifications, $inviteViews, $userId, 30),
                    'unread_count' => mgw_visible_unread_count($snapshot, $userId),
                ];
            }
        );
    } elseif ($commands === 1) {
        $result = $db->transaction(function (array &$data) use (
            $notifications,
            $inviteViews,
            $userId,
            $markRead,
            $readNotificationId,
            $deleteNotificationId,
            $consumeInviteToken
        ): array {
            mgw_mutate_notification_center_v2(
                $data,
                $notifications,
                $userId,
                $markRead,
                $readNotificationId,
                $deleteNotificationId,
                $consumeInviteToken
            );
            return [
                'items' => mgw_visible_notifications($data, $notifications, $inviteViews, $userId, 30),
                'unread_count' => mgw_visible_unread_count($data, $userId),
            ];
        });
    } else {
        $result = $db->readOnly(function (array $data) use ($notifications, $inviteViews, $userId): array {
            return [
                'items' => mgw_visible_notifications($data, $notifications, $inviteViews, $userId, 30),
                'unread_count' => mgw_visible_unread_count($data, $userId),
            ];
        });
    }

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
