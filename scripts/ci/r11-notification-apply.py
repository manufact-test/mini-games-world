from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def replace_exact(path: str, old: str, new: str) -> None:
    file = ROOT / path
    text = file.read_text(encoding='utf-8')
    if old not in text:
        raise RuntimeError(f'Expected block not found in {path}')
    file.write_text(text.replace(old, new, 1), encoding='utf-8')


notifications = 'app/assets/js/screens/notifications-screen-v110r5.js'
replace_exact(
    notifications,
    """    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void openNotificationsSheet(currentItems());""",
    """    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (Date.now() < suppressToastClickUntil) return;
    void openNotificationsSheet(currentItems());""",
)
replace_exact(
    notifications,
    """    if (authorityRevision !== notificationAuthorityRevision || Date.now() < localAuthorityUntil) {
      mergeItems(items);""",
    """    if (notificationSheetActive
        || authorityRevision !== notificationAuthorityRevision
        || Date.now() < localAuthorityUntil) {
      mergeItems(items);""",
)
replace_exact(
    notifications,
    """  upsert(item);
  dismissToast();
  void openNotificationsSheet([item], true, true);""",
    """  notificationAuthorityRevision += 1;
  const guardUntil = Date.now() + MOBILE_CLOSE_GUARD_MS;
  localAuthorityUntil = Date.now() + LOCAL_AUTHORITY_GRACE_MS;
  suppressToastClickUntil = Math.max(suppressToastClickUntil, guardUntil);
  suppressAnnouncementsUntil = Math.max(suppressAnnouncementsUntil, guardUntil);
  upsert(item);
  rememberAnnouncedId(String(item.id || ''));
  dismissToast();
  void openNotificationsSheet([item], true, true);""",
)

replacements = {
    'app/assets/js/main-v110-handoff-shell.js': [
        ("v110-mvp14r10-mobile-notification-invite-restore", "v110-mvp14r11-mobile-toast-authority"),
        ("notifications-screen-v110r5.js?v=1114", "notifications-screen-v110r5.js?v=1115"),
    ],
    'app/assets/js/main-v110.js': [
        ("v110-mvp14r10-mobile-notification-invite-restore", "v110-mvp14r11-mobile-toast-authority"),
        ("main-v110-handoff-shell.js?v=1114", "main-v110-handoff-shell.js?v=1115"),
    ],
    'app/assets/js/production-clean-entry-v110.js': [
        ("v110-mvp14r10-mobile-notification-invite-restore", "v110-mvp14r11-mobile-toast-authority"),
    ],
    'app/v110.php': [
        ("production-clean-entry-v110.js?v=1114", "production-clean-entry-v110.js?v=1115"),
        ("main-v110.js?v=1114", "main-v110.js?v=1115"),
        ("v110-mvp14r10-mobile-notification-invite-restore", "v110-mvp14r11-mobile-toast-authority"),
    ],
}
for path, pairs in replacements.items():
    for old, new in pairs:
        replace_exact(path, old, new)
