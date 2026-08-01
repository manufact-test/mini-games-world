from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FILES = [
    'bot/tests/ProductionV110AcceptanceRootFixContractTest.php',
    'bot/tests/ProductionV110CanonicalInviteLaunchContractTest.php',
    'bot/tests/ProductionV110CanonicalShareNotificationRootContractTest.php',
    'bot/tests/ProductionV110InviteActionsRootContractTest.php',
    'bot/tests/ProductionV110InvitePresenceNotificationProfileRootContractTest.php',
    'bot/tests/ProductionV110MobileNotificationInviteRestoreContractTest.php',
    'bot/tests/ProductionV110MobileShareNotificationCacheRootContractTest.php',
    'bot/tests/ProductionV110PresenceInviteResumeRootContractTest.php',
    'bot/tests/ProductionV110SurrenderHomeQueueContractTest.php',
]

for relative in FILES:
    path = ROOT / relative
    text = path.read_text(encoding='utf-8')
    text = text.replace('v110-mvp14r10-mobile-notification-invite-restore', 'v110-mvp14r11-mobile-toast-authority')
    text = text.replace('?v=1114', '?v=1115')
    path.write_text(text, encoding='utf-8')

path = ROOT / 'bot/tests/ProductionV110CanonicalShareNotificationRootContractTest.php'
text = path.read_text(encoding='utf-8')
old = """        && str_contains($notifications, 'if (authorityRevision !== notificationAuthorityRevision || Date.now() < localAuthorityUntil)')
        && str_contains($notifications, 'setUnreadCount(Math.max(unreadHint, Number(result?.unread_count || 0)))'),"""
new = """        && str_contains($notifications, 'if (notificationSheetActive')
        && str_contains($notifications, 'authorityRevision !== notificationAuthorityRevision')
        && str_contains($notifications, 'Date.now() < localAuthorityUntil')
        && str_contains($notifications, 'setUnreadCount(Math.max(unreadHint, Number(result?.unread_count || 0)))'),"""
if old not in text:
    raise RuntimeError('Canonical notification authority assertion was not found')
path.write_text(text.replace(old, new, 1), encoding='utf-8')
