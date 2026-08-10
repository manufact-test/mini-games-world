from pathlib import Path


def replace_once(path, old, new):
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected exactly one anchor, found {count}: {old[:140]!r}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')


def replace_exact_count(path, old, new, expected):
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != expected:
        raise SystemExit(f'{path}: expected {expected} anchors, found {count}: {old[:140]!r}')
    p.write_text(text.replace(old, new), encoding='utf-8')


# One public invite projection owner. Notification surfaces call the exact same
# GameInviteService projection used by the invite action/sync path.
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

# Capture the single projection owner in each closure that renders visible items.
replace_once(
    'bot/notifications.php',
    "            function (array $snapshot) use (\n                $runtimeNotifications,\n                $notifications,\n                $userId,\n                $tgUser,\n                $markRead\n            ): array {\n",
    "            function (array $snapshot) use (\n                $runtimeNotifications,\n                $notifications,\n                $inviteViews,\n                $userId,\n                $tgUser,\n                $markRead\n            ): array {\n",
)
replace_once(
    'bot/notifications.php',
    "    } elseif ($markRead) {\n        $result = $db->transaction(function (array &$data) use ($notifications, $userId): array {\n",
    "    } elseif ($markRead) {\n        $result = $db->transaction(function (array &$data) use ($notifications, $inviteViews, $userId): array {\n",
)
replace_once(
    'bot/notifications.php',
    "    } elseif ($consumeInviteToken !== '') {\n        $result = $db->transaction(function (array &$data) use (\n            $notifications,\n            $userId,\n            $consumeInviteToken\n        ): array {\n",
    "    } elseif ($consumeInviteToken !== '') {\n        $result = $db->transaction(function (array &$data) use (\n            $notifications,\n            $inviteViews,\n            $userId,\n            $consumeInviteToken\n        ): array {\n",
)
replace_once(
    'bot/notifications.php',
    "    } else {\n        $result = $db->readOnly(function (array $data) use ($notifications, $userId): array {\n",
    "    } else {\n        $result = $db->readOnly(function (array $data) use ($notifications, $inviteViews, $userId): array {\n",
)

replace_once(
    'bot/notifications.php',
    "mgw_visible_notifications($snapshot, $notifications, $userId, 30)",
    "mgw_visible_notifications($snapshot, $notifications, $inviteViews, $userId, 30)",
)
replace_exact_count(
    'bot/notifications.php',
    "mgw_visible_notifications($data, $notifications, $userId, 30)",
    "mgw_visible_notifications($data, $notifications, $inviteViews, $userId, 30)",
    3,
)

# Current immutable v110 graph is v1137; these two old picker cases were still
# pinned to the previous v1136 owner even though product behavior was correct.
replace_once(
    'e2e/staging/d1-bug-b-player-picker-v122.spec.mjs',
    "url.searchParams.get('v') === '1136'",
    "url.searchParams.get('v') === '1137'",
)

# Test-only isolation: suppress passive sync with a static valid response instead
# of fetch+fulfill, which can collide with the deliberately held action route.
replace_once(
    'e2e/staging/invite-transition-ux-v1137.spec.mjs',
    "    if (action === 'sync') {\n      const response = await route.fetch();\n      const payload = await response.json().catch(() => null);\n      await route.fulfill({\n        response,\n        json: payload && typeof payload === 'object'\n          ? { ...payload, invite: null, tracked_invite: null }\n          : payload,\n      });\n      return;\n    }\n",
    "    if (action === 'sync') {\n      await route.fulfill({\n        status: 200,\n        contentType: 'application/json',\n        body: JSON.stringify({\n          ok: true,\n          invite: null,\n          tracked_invite: null,\n          events: [],\n          unread_count: 1,\n        }),\n      });\n      return;\n    }\n",
)

# Strengthen source contract so a future notification refactor cannot silently
# drop the authoritative snapshot again.
replace_once(
    'bot/tests/ProductionMvp14InviteTransitionUxV1137Test.php',
    "$service = $read('bot/services/GameInviteService.php');\n$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');\n",
    "$service = $read('bot/services/GameInviteService.php');\n$notificationEndpoint = $read('bot/notifications.php');\n$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');\n",
)
replace_once(
    'bot/tests/ProductionMvp14InviteTransitionUxV1137Test.php',
    "$assert(\n    str_contains($notifications, 'data-invite-snapshot=')\n",
    "$assert(\n    str_contains($service, 'public function notificationSnapshot(array $invite, string $viewerId): array')\n        && str_contains($service, 'return $this->publicInvite($invite, $viewerId);')\n        && str_contains($notificationEndpoint, \"\\$item['invite_snapshot'] = \\$inviteViews->notificationSnapshot(\\$invite, \\$userId);\"),\n    'Bell and toast payloads must reuse the same GameInviteService public invite projection.'\n);\n$assert(\n    str_contains($notifications, 'data-invite-snapshot=')\n",
)

print('Notification snapshot owner follow-up patch applied.')
