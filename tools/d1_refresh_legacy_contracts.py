from pathlib import Path


def replace(path: str, old: str, new: str, label: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        raise SystemExit(f'Missing {label} in {path}: {old!r}')
    file.write_text(text.replace(old, new, 1))


replace(
    'bot/tests/ProductionAvatarInviteRegressionHotfixTest.php',
    "$assertContains('v96-mvp14-root-cause-stabilization', $main, 'Main module must publish v96 without losing prior avatar fixes');",
    "$assertContains('d1-canonical-owners', $main, 'Main module must publish the canonical D1 owner graph without losing prior avatar fixes');",
    'avatar main build assertion',
)

replace(
    'bot/tests/ProductionMvp14D1FeedbackLinkEntrySheetTest.php',
    "import { initGameInvites } from './games/game-invites.js?v=85';",
    "import { initGameInvites } from './games/game-invites.js?v=d1';",
    'canonical game invites import',
)
replace(
    'bot/tests/ProductionMvp14D1FeedbackLinkEntrySheetTest.php',
    "import { openIncomingInviteFromTelegram } from './games/invite-link-entry-v115.js?v=115';",
    "import { openIncomingInviteFromTelegram } from './games/invite-link-entry-v115.js?v=d1';",
    'canonical invite link import',
)

replace(
    'bot/tests/ProductionMvp14DeferredNotificationFirstFrameE2ETest.php',
    '<div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>',
    '<div class="notifications-loading" data-notifications-state="loading">',
    'notification loading state contract',
)
replace(
    'bot/tests/ProductionMvp14DeferredNotificationFirstFrameE2ETest.php',
    'renderNotifications(items);',
    'renderNotificationsBody(sheetItems);',
    'notification ready renderer contract',
)
replace(
    'bot/tests/ProductionMvp14DeferredNotificationFirstFrameE2ETest.php',
    'openNotificationsSheet();',
    'loadNotificationsSheet({ hapticFeedback:true, keepShell:false });',
    'notification activation contract',
)

replace(
    'bot/tests/ProductionMvp14R13R11LiveAcceptanceTest.php',
    "el.addEventListener('click'",
    "document.addEventListener('click', handleNotificationActivation)",
    'delegated notification click owner',
)
replace(
    'bot/tests/ProductionMvp14R13R11LiveAcceptanceTest.php',
    'openNotificationsSheet();',
    'loadNotificationsSheet({ hapticFeedback:true, keepShell:false });',
    'canonical notification sheet loader',
)

replace(
    'bot/tests/ProductionV96RootCauseStabilizationTest.php',
    "window.__MGW_BUILD__ = 'v96-mvp14-root-cause-stabilization'",
    "window.__MGW_BUILD__ = 'd1-canonical-owners'",
    'optional warmers canonical build identity',
)
