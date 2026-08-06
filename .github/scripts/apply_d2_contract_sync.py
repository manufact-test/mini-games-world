from pathlib import Path


def replace_once(path: str | Path, old: str, new: str) -> None:
    path = Path(path)
    text = path.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one occurrence, found {count}: {old[:180]!r}')
    path.write_text(text.replace(old, new, 1), encoding='utf-8')


endpoint = Path('bot/notifications.php')
replace_once(
    endpoint,
    """        if ($markRead || $consumeInviteToken !== '') {
            $db->transaction(function (array &$data) use (
                $notifications,
                $userId,
                $markRead,
                $consumeInviteToken
            ): void {
                if ($markRead) {
                    $notifications->markAllRead($data, $userId);
                } else {
                    mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
                }
            });
        }
""",
    """        if ($markRead) {
            $db->transaction(function (array &$data) use ($notifications, $userId): void {
                $notifications->markAllRead($data, $userId);
            });
        } elseif ($consumeInviteToken !== '') {
            $db->transaction(function (array &$data) use ($userId, $consumeInviteToken): void {
                mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
            });
        }
""",
)
replace_once(
    endpoint,
    """    } elseif ($markRead || $consumeInviteToken !== '') {
        $result = $db->transaction(function (array &$data) use (
            $notifications,
            $userId,
            $markRead,
            $consumeInviteToken
        ): array {
            if ($markRead) {
                $notifications->markAllRead($data, $userId);
            } else {
                mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
            }
            return [
                'items' => mgw_visible_notifications($data, $notifications, $userId, 30),
                'unread_count' => mgw_visible_unread_count($data, $userId),
            ];
        });
    } else {""",
    """    } elseif ($markRead) {
        $result = $db->transaction(function (array &$data) use ($notifications, $userId): array {
            $items = mgw_visible_notifications($data, $notifications, $userId, 30);
            $notifications->markAllRead($data, $userId);
            foreach ($items as &$item) $item['read'] = true;
            unset($item);
            return ['items' => $items, 'unread_count' => 0];
        });
    } elseif ($consumeInviteToken !== '') {
        $result = $db->transaction(function (array &$data) use (
            $notifications,
            $userId,
            $consumeInviteToken
        ): array {
            mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
            return [
                'items' => mgw_visible_notifications($data, $notifications, $userId, 30),
                'unread_count' => mgw_visible_unread_count($data, $userId),
            ];
        });
    } else {""",
)

files = [
    'bot/tests/ProductionMvp14D1ActualStartPlayerPickerV1127Test.php',
    'bot/tests/ProductionMvp14D1PlayerPickerNoVisibleLoaderV1129Test.php',
    'bot/tests/ProductionMvp14D2D3D5IntegrationContractTest.php',
    'bot/tests/ProductionMvp14D2TerminalCardInPlaceV1130Test.php',
    'bot/tests/ProductionMvp14D3SharedInviteAcceptanceTest.php',
    'bot/tests/ProductionV110AcceptanceRootFixContractTest.php',
    'bot/tests/ProductionV110CanonicalInviteLaunchContractTest.php',
    'bot/tests/ProductionV110CanonicalShareNotificationRootContractTest.php',
    'bot/tests/ProductionV110InviteActionsRootContractTest.php',
    'bot/tests/ProductionV110InvitePresenceNotificationProfileRootContractTest.php',
    'bot/tests/ProductionV110InviteTerminalActionsR12ContractTest.php',
    'bot/tests/ProductionV110MobileNotificationInviteRestoreContractTest.php',
    'bot/tests/ProductionV110MobileShareNotificationCacheRootContractTest.php',
    'bot/tests/ProductionV110MobileToastAuthorityContractTest.php',
    'bot/tests/ProductionV110NotificationCenterR12ContractTest.php',
    'bot/tests/ProductionV110PresenceInviteResumeRootContractTest.php',
    'bot/tests/ProductionV110R12AcceptedRegressionPublicationContractTest.php',
    'bot/tests/ProductionV110R12FinalReleaseContractTest.php',
    'bot/tests/ProductionV110R12V1123PublicationContractTest.php',
    'bot/tests/ProductionV110SearchInviteLifecycleR12ContractTest.php',
    'bot/tests/ProductionV120EmergencyRollbackRouteContractTest.php',
    'bot/tests/ProductionV120InviteControllerArchitectureContractTest.php',
]
replacements = {
    'notifications-screen-v110r12.js?v=1132': 'notifications-screen-v110r12.js?v=1133',
    'game-invites-v110.js?v=1130': 'game-invites-v110.js?v=1133',
    'main-v110-handoff-shell.js?v=1132': 'main-v110-handoff-shell.js?v=1133',
    'main-v110-handoff-shell.js?v=1130': 'main-v110-handoff-shell.js?v=1133',
    'main-v110.js?v=1132': 'main-v110.js?v=1133',
    'main-v110.js?v=1130': 'main-v110.js?v=1133',
    'v110-mvp14r12-notification-publication-v1132': 'v110-mvp14r12-terminal-dedup-v1133',
}
for raw_path in files:
    path = Path(raw_path)
    text = path.read_text(encoding='utf-8')
    updated = text
    for old, new in replacements.items():
        updated = updated.replace(old, new)
    if updated == text:
        raise SystemExit(f'{path}: no active graph expectation changed')
    path.write_text(updated, encoding='utf-8')
