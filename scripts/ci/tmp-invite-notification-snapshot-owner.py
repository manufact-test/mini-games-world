from pathlib import Path


def replace_once(path, old, new):
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected exactly one anchor, found {count}: {old[:120]!r}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')

# Keep one public invite projection owner. Notification surfaces call the same
# GameInviteService projection instead of rebuilding their own partial object.
replace_once(
    'bot/services/GameInviteService.php',
    "    ) {}\n\n    /**\n",
    "    ) {}\n\n    public function notificationSnapshot(array $invite, string $viewerId): array\n    {\n        return $this->publicInvite($invite, $viewerId);\n    }\n\n    /**\n",
)

replace_once(
    'bot/notifications.php',
    "require_once __DIR__ . '/services/NotificationService.php';\n",
    "require_once __DIR__ . '/services/NotificationService.php';\nrequire_once __DIR__ . '/services/GameInviteService.php';\n",
)
replace_once(
    'bot/notifications.php',
    "function mgw_visible_notifications(\n    array $data,\n    NotificationService $notifications,\n    string $userId,\n    int $limit\n): array {\n",
    "function mgw_visible_notifications(\n    array $data,\n    NotificationService $notifications,\n    GameInviteService $inviteViews,\n    string $userId,\n    int $limit\n): array {\n",
)
replace_once(
    'bot/notifications.php',
    "            $item['invite_is_owner'] = (string)($invite['inviter_id'] ?? '') === $userId;\n",
    "            $item['invite_is_owner'] = (string)($invite['inviter_id'] ?? '') === $userId;\n            $item['invite_snapshot'] = $inviteViews->notificationSnapshot($invite, $userId);\n",
)
replace_once(
    'bot/notifications.php',
    "    $notifications = new NotificationService();\n    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter\n",
    "    $notifications = new NotificationService();\n    $inviteCatalog = new GameCatalogService($config);\n    $inviteViews = new GameInviteService(\n        $config,\n        $inviteCatalog,\n        new ChessRuntimeService($config, $inviteCatalog, new GameService($config))\n    );\n    $router = $runtimeStorageRouter instanceof RuntimeStorageRouter\n",
)
replace_once(
    'bot/notifications.php',
    "                $runtimeNotifications,\n                $notifications,\n                $userId,\n",
    "                $runtimeNotifications,\n                $notifications,\n                $inviteViews,\n                $userId,\n",
)
replace_once(
    'bot/notifications.php',
    "                    'items' => mgw_visible_notifications($snapshot, $notifications, $userId, 30),\n",
    "                    'items' => mgw_visible_notifications($snapshot, $notifications, $inviteViews, $userId, 30),\n",
)
replace_once(
    'bot/notifications.php',
    "        $result = $db->transaction(function (array &$data) use ($notifications, $userId): array {\n            $items = mgw_visible_notifications($data, $notifications, $userId, 30);\n",
    "        $result = $db->transaction(function (array &$data) use ($notifications, $inviteViews, $userId): array {\n            $items = mgw_visible_notifications($data, $notifications, $inviteViews, $userId, 30);\n",
)
replace_once(
    'bot/notifications.php',
    "                $notifications,\n                $userId,\n                $consumeInviteToken\n",
    "                $notifications,\n                $inviteViews,\n                $userId,\n                $consumeInviteToken\n",
)
replace_once(
    'bot/notifications.php',
    "                'items' => mgw_visible_notifications($data, $notifications, $userId, 30),\n",
    "                'items' => mgw_visible_notifications($data, $notifications, $inviteViews, $userId, 30),\n",
)
replace_once(
    'bot/notifications.php',
    "        $result = $db->readOnly(function (array $data) use ($notifications, $userId): array {\n            return [\n                'items' => mgw_visible_notifications($data, $notifications, $userId, 30),\n",
    "        $result = $db->readOnly(function (array $data) use ($notifications, $inviteViews, $userId): array {\n            return [\n                'items' => mgw_visible_notifications($data, $notifications, $inviteViews, $userId, 30),\n",
)

# Current v1137 is the immutable successor; old picker E2E contract still pinned v1136.
replace_once(
    'e2e/staging/d1-bug-b-player-picker-v122.spec.mjs',
    "url.searchParams.get('v') === '1136'",
    "url.searchParams.get('v') === '1137'",
)

# In the new regression, suppress passive sync with a static valid response.
# Fetch+fulfill was unnecessary and could collide with a simultaneously-held action route.
replace_once(
    'e2e/staging/invite-transition-ux-v1137.spec.mjs',
    "    if (action === 'sync') {\n      const response = await route.fetch();\n      const payload = await response.json().catch(() => null);\n      await route.fulfill({\n        response,\n        json: payload && typeof payload === 'object'\n          ? { ...payload, invite: null, tracked_invite: null }\n          : payload,\n      });\n      return;\n    }\n",
    "    if (action === 'sync') {\n      await route.fulfill({\n        status: 200,\n        contentType: 'application/json',\n        body: JSON.stringify({\n          ok: true,\n          invite: null,\n          tracked_invite: null,\n          events: [],\n          unread_count: 1,\n        }),\n      });\n      return;\n    }\n",
)

# Strengthen source contract: both invite sync and bell/notification endpoint must
# use the exact same GameInviteService public projection.
replace_once(
    'bot/tests/ProductionMvp14InviteTransitionUxV1137Test.php',
    "$service = $read('bot/services/GameInviteService.php');\n$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');\n",
    "$service = $read('bot/services/GameInviteService.php');\n$notificationEndpoint = $read('bot/notifications.php');\n$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');\n",
)
replace_once(
    'bot/tests/ProductionMvp14InviteTransitionUxV1137Test.php',
    "$assert(\n    str_contains($notifications, 'data-invite-snapshot=')\n",
    "$assert(\n    str_contains($service, 'public function notificationSnapshot(array $invite, string $viewerId): array')\n        && str_contains($service, 'return $this->publicInvite($invite, $viewerId);')\n        && str_contains($notificationEndpoint, \"$item['invite_snapshot'] = $inviteViews->notificationSnapshot($invite, $userId);\"),\n    'Bell and toast payloads must reuse the same GameInviteService public invite projection.'\n);\n$assert(\n    str_contains($notifications, 'data-invite-snapshot=')\n",
)

print('Notification snapshot owner follow-up patch applied.')
