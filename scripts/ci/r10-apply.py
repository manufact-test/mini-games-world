from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def write(path: str, content: str) -> None:
    (ROOT / path).write_text(content, encoding='utf-8')


def replace_once(content: str, old: str, new: str, label: str) -> str:
    count = content.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected exactly one match, found {count}')
    return content.replace(old, new, 1)


# Restore the accepted direct-player flow. Keep the fast native share draft and
# instant cancellation reuse, but remove the speculative page/room visibility
# prewarm that competes with the opponents request on mobile WebViews.
invite_path = 'app/assets/js/games/game-invites-v110.js'
invites = read(invite_path)
invites = replace_once(
    invites,
    "const SHARE_WARM_KEEPALIVE_MS = 180000;\nconst SHARE_PREFETCH_ROOT_MARGIN = '240px 0px';",
    "const SHARE_WARM_KEEPALIVE_MS = 180000;",
    'invite prefetch constant',
)
invites = replace_once(
    invites,
    "let shareWarmExpiryTimer = null;\nlet shareVisibleWarmTimer = null;\nlet shareVisibilityObserver = null;\nlet shareWarm = null;",
    "let shareWarmExpiryTimer = null;\nlet shareWarm = null;",
    'invite prefetch state',
)
invites = replace_once(
    invites,
    "  initialized = true;\n  initShareVisibilityPrewarm();\n\n  document.addEventListener('pointerdown', handleInvitePointerDown, true);",
    "  initialized = true;\n\n  document.addEventListener('pointerdown', handleInvitePointerDown, true);",
    'invite init prewarm',
)
invites = replace_once(
    invites,
    "      scheduleWatch(0);\n      scheduleVisibleShareWarm(80);",
    "      scheduleWatch(0);",
    'invite visibility prewarm',
)
invites = replace_once(
    invites,
    "    scheduleWatch(0);\n    scheduleVisibleShareWarm(0);\n  }, { once:true });",
    "    scheduleWatch(0);\n  }, { once:true });",
    'invite app-ready prewarm',
)
invites = replace_once(
    invites,
    "  document.addEventListener('mgw:sheet-closed', () => {\n    if (!shareAttempt?.nativePending) scheduleVisibleShareWarm(120);\n  });",
    "  document.addEventListener('mgw:sheet-closed', () => {\n    if (!shareAttempt?.nativePending) cancelWarmShareDraft();\n  });",
    'invite sheet close ownership',
)
start = invites.find('function initShareVisibilityPrewarm(){')
end = invites.find('function handleDocumentClick(event){', start)
if start < 0 or end < 0:
    raise RuntimeError('invite visibility prewarm block not found')
invites = invites[:start] + invites[end:]
invites = replace_once(
    invites,
    "\n  const roomButton = event.target.closest('[data-room]');\n  if (roomButton) window.setTimeout(() => scheduleVisibleShareWarm(0), 0);\n",
    "\n",
    'invite room prewarm',
)
if any(token in invites for token in ('initShareVisibilityPrewarm', 'scheduleVisibleShareWarm', 'nearestVisibleInviteTrigger', 'SHARE_PREFETCH_ROOT_MARGIN')):
    raise RuntimeError('speculative invite visibility prewarm survived R10 cleanup')
if 'restoreWarmShareDraft(attempt);' not in invites or 'SHARE_WARM_KEEPALIVE_MS' not in invites:
    raise RuntimeError('fast reusable Telegram share draft was lost')
write(invite_path, invites)


# Mobile notifications: hydrate only after the authenticated app is ready,
# preserve the exact first-frame items during a server race, and block the
# close-touch from re-opening the sheet through a newly re-announced toast.
notification_path = 'app/assets/js/screens/notifications-screen-v110r5.js'
notifications = read(notification_path)
notifications = replace_once(
    notifications,
    "const EMPTY_RETRY_MS = 160;\nconst LIVE_STORAGE_KEY_PREFIX = 'mgw_notification_live_items_v1';",
    "const EMPTY_RETRY_MS = 160;\nconst MAX_EMPTY_SHEET_RETRIES = 4;\nconst MOBILE_CLOSE_GUARD_MS = 700;\nconst LOCAL_AUTHORITY_GRACE_MS = 2500;\nconst LIVE_STORAGE_KEY_PREFIX = 'mgw_notification_live_items_v1';",
    'notification constants',
)
notifications = replace_once(
    notifications,
    "let seededSheetGeneration = 0;\nlet seededSheetItems = [];\nlet notificationAuthorityRevision = 0;",
    "let seededSheetGeneration = 0;\nlet seededSheetItems = [];\nlet notificationAuthorityRevision = 0;\nlet notificationSheetActive = false;\nlet cacheHydrated = false;\nlet localAuthorityUntil = 0;\nlet suppressAnnouncementsUntil = 0;\nlet emptySheetRetryCount = 0;",
    'notification state',
)
notifications = replace_once(
    notifications,
    "  initialized = true;\n  liveItems = loadLiveItems();\n  ensureToast();",
    "  initialized = true;\n  ensureToast();",
    'early notification cache hydration',
)
notifications = replace_once(
    notifications,
    "  document.addEventListener('mgw:app-ready', () => {\n    appReady = true;\n    void refreshBadge(false);\n  }, { once:true });",
    "  document.addEventListener('mgw:app-ready', () => {\n    appReady = true;\n    hydrateLiveItems();\n    void refreshBadge(false);\n  }, { once:true });",
    'notification app-ready hydration',
)
notifications = replace_once(
    notifications,
    "  document.addEventListener('visibilitychange', () => {\n    if (document.visibilityState === 'visible') {\n      void refreshBadge(true);\n      scheduleAnnouncement(40);\n    } else {\n      dismissToast();\n    }\n  });",
    "  document.addEventListener('visibilitychange', () => {\n    if (document.visibilityState === 'visible') {\n      hydrateLiveItems();\n      void refreshBadge(true);\n      if (Date.now() >= suppressAnnouncementsUntil) scheduleAnnouncement(40);\n    } else {\n      dismissToast();\n    }\n  });",
    'notification visibility hydration',
)
notifications = replace_once(
    notifications,
    "  document.addEventListener('mgw:sheet-closed', () => {\n    sheetGeneration += 1;\n    seededSheetGeneration = 0;\n    seededSheetItems = [];\n    if (openingSheetGeneration !== sheetGeneration) openingSheetPromise = null;\n    scheduleAnnouncement(40);\n  });",
    "  document.addEventListener('mgw:sheet-closed', () => {\n    const closedNotificationsSheet = notificationSheetActive;\n    notificationSheetActive = false;\n    sheetGeneration += 1;\n    seededSheetGeneration = 0;\n    seededSheetItems = [];\n    emptySheetRetryCount = 0;\n    if (openingSheetGeneration !== sheetGeneration) openingSheetPromise = null;\n\n    if (closedNotificationsSheet) {\n      const guardUntil = Date.now() + MOBILE_CLOSE_GUARD_MS;\n      suppressToastClickUntil = Math.max(suppressToastClickUntil, guardUntil);\n      suppressAnnouncementsUntil = Math.max(suppressAnnouncementsUntil, guardUntil);\n      markCurrentItemsReadLocally();\n      dismissToast();\n      return;\n    }\n\n    scheduleAnnouncement(40);\n  });",
    'notification close race guard',
)
notifications = replace_once(
    notifications,
    "    notificationAuthorityRevision += 1;\n    upsert(item);\n    if (isNotificationsSheetOpen()) {\n      renderNotifications(currentItems());\n      rememberAnnouncedId(String(item.id || ''));\n      return;\n    }",
    "    notificationAuthorityRevision += 1;\n    localAuthorityUntil = Date.now() + LOCAL_AUTHORITY_GRACE_MS;\n    upsert(item);\n    if (isNotificationsSheetOpen()) {\n      renderNotifications(currentItems());\n      rememberAnnouncedId(String(item.id || ''));\n      markCurrentItemsReadLocally();\n      setUnreadCount(0);\n      void rawNotifications(true).catch(() => null);\n      return;\n    }",
    'notification live event ownership',
)
notifications = replace_once(
    notifications,
    "  void refreshBadge(false);\n  notificationPoll = window.setInterval(() => void refreshBadge(true), NOTIFICATION_POLL_MS);",
    "  notificationPoll = window.setInterval(() => void refreshBadge(true), NOTIFICATION_POLL_MS);",
    'notification pre-auth refresh',
)
notifications = replace_once(
    notifications,
    "    if (authorityRevision !== notificationAuthorityRevision) {\n      mergeItems(items);\n      setUnreadCount(Math.max(unreadHint, Number(result?.unread_count || 0)));\n      return;\n    }\n    reconcileItems(items);\n    setUnreadCount(Number(result?.unread_count || 0));",
    "    if (authorityRevision !== notificationAuthorityRevision || Date.now() < localAuthorityUntil) {\n      mergeItems(items);\n      setUnreadCount(Math.max(unreadHint, Number(result?.unread_count || 0)));\n      return;\n    }\n    reconcileItems(items);\n    setUnreadCount(Number(result?.unread_count || 0));",
    'notification stale response guard',
)
notifications = replace_once(
    notifications,
    "async function openNotificationsSheet(seedItems = [], hapticFeedback = true, preserveSeed = false){\n  const generation = ++sheetGeneration;\n  setSheetSeed(generation, seedItems, preserveSeed);\n  mergeItems(seedItems);\n  const immediate = mergeNotificationItems(seedItems, currentItems());\n  if (immediate.length) renderNotifications(immediate);\n  else renderLoading();\n\n  if (hapticFeedback) haptic('light');\n  dismissToast();\n  return refreshOpenSheet(generation);\n}",
    "async function openNotificationsSheet(seedItems = [], hapticFeedback = true, preserveSeed = false){\n  hydrateLiveItems();\n  const generation = ++sheetGeneration;\n  notificationSheetActive = true;\n  emptySheetRetryCount = 0;\n  mergeItems(seedItems);\n  const immediate = mergeNotificationItems(seedItems, currentItems());\n  setSheetSeed(generation, immediate, preserveSeed || immediate.length > 0);\n\n  if (immediate.length) {\n    renderNotifications(immediate);\n    rememberAnnouncedItems(immediate);\n    markCurrentItemsReadLocally();\n    setUnreadCount(0);\n  } else {\n    renderLoading();\n  }\n\n  if (hapticFeedback) haptic('light');\n  dismissToast();\n  return refreshOpenSheet(generation);\n}",
    'notification first frame',
)
notifications = replace_once(
    notifications,
    "      let visible = currentItems();\n      if (!visible.length && (Number(result?.unread_count || 0) > 0 || unreadHint > 0)) {\n        renderLoading();\n        await delay(EMPTY_RETRY_MS);\n        result = await rawNotifications(false);\n        serverItems = Array.isArray(result?.items) ? result.items : [];\n        reconcileItems(mergeNotificationItems(sheetSeedItems(generation), serverItems));\n        rememberAnnouncedItems(serverItems);\n        visible = currentItems();\n      }\n\n      if (!isCurrentNotificationsSheet(generation)) {\n        setUnreadCount(Number(result?.unread_count || 0));\n        return;\n      }\n\n      renderNotifications(visible);\n      setUnreadCount(0);\n      void rawNotifications(true).catch(() => null);",
    "      let visible = currentItems();\n      if (!visible.length && (Number(result?.unread_count || 0) > 0 || unreadHint > 0)) {\n        renderLoading();\n        await delay(EMPTY_RETRY_MS);\n        result = await rawNotifications(false);\n        serverItems = Array.isArray(result?.items) ? result.items : [];\n        reconcileItems(mergeNotificationItems(sheetSeedItems(generation), serverItems));\n        rememberAnnouncedItems(serverItems);\n        visible = currentItems();\n      }\n\n      if (!isCurrentNotificationsSheet(generation)) {\n        setUnreadCount(Number(result?.unread_count || 0));\n        return;\n      }\n\n      if (!visible.length\n          && (Number(result?.unread_count || 0) > 0 || unreadHint > 0)\n          && emptySheetRetryCount < MAX_EMPTY_SHEET_RETRIES) {\n        emptySheetRetryCount += 1;\n        renderLoading();\n        window.setTimeout(() => {\n          if (isCurrentNotificationsSheet(generation)) void refreshOpenSheet(generation);\n        }, EMPTY_RETRY_MS * (emptySheetRetryCount + 1));\n        return;\n      }\n\n      emptySheetRetryCount = 0;\n      renderNotifications(visible);\n      rememberAnnouncedItems(visible);\n      markCurrentItemsReadLocally();\n      setUnreadCount(0);\n      localAuthorityUntil = 0;\n      void rawNotifications(true).catch(() => null);",
    'notification bounded empty retry',
)
notifications = replace_once(
    notifications,
    "function canShowToast(){\n  if (!appReady || document.visibilityState !== 'visible') return false;",
    "function canShowToast(){\n  if (!appReady || document.visibilityState !== 'visible') return false;\n  if (Date.now() < suppressAnnouncementsUntil) return false;",
    'notification announcement suppression',
)
marker = "function notificationCacheKey(){\n"
insert = """function hydrateLiveItems(){
  if (cacheHydrated) return;
  cacheHydrated = true;
  const cached = loadLiveItems();
  if (!(cached instanceof Map) || !cached.size) return;

  for (const [id, item] of cached.entries()) {
    if (!id) continue;
    liveItems.set(String(id), cloneItem(item));
  }
  liveItems = new Map(currentItems(MAX_LIVE_ITEMS).map(item => [String(item.id), item]));
  if (currentItems().some(item => !item?.read)) {
    localAuthorityUntil = Date.now() + LOCAL_AUTHORITY_GRACE_MS;
  }
}

function markCurrentItemsReadLocally(){
  const items = currentItems(MAX_LIVE_ITEMS).map(item => ({ ...item, read:true }));
  liveItems = new Map(items.map(item => [String(item.id), item]));
  persistLiveItems();
}

"""
if notifications.count(marker) != 1:
    raise RuntimeError('notification cache helper insertion point not found')
notifications = notifications.replace(marker, insert + marker, 1)
for required in (
    'hydrateLiveItems();',
    'setSheetSeed(generation, immediate, preserveSeed || immediate.length > 0);',
    'markCurrentItemsReadLocally();',
    'suppressAnnouncementsUntil',
    'localAuthorityUntil',
):
    if required not in notifications:
        raise RuntimeError(f'missing notification R10 guard: {required}')
write(notification_path, notifications)


# Cache-bust the active Telegram entrypoint and canonical owner graph.
version_paths = [
    'app/v110.php',
    'app/assets/js/main-v110.js',
    'app/assets/js/main-v110-handoff-shell.js',
    'app/assets/js/production-clean-entry-v110.js',
    'bot/helpers/WebAppLaunchUrl.php',
    'bot/helpers/UserWelcomeGuard.php',
]
version_paths.extend(str(path.relative_to(ROOT)) for path in (ROOT / 'bot/tests').glob('*.php') if '1113' in path.read_text(encoding='utf-8'))
for path in version_paths:
    content = read(path)
    content = content.replace('1113', '1114')
    content = content.replace('v110-mvp14r9-mobile-share-notification-cache-root', 'v110-mvp14r10-mobile-notification-invite-restore')
    content = content.replace('v110-mvp14r9-mobile-share-notification-cache-root', 'v110-mvp14r10-mobile-notification-invite-restore')
    write(path, content)

# Update contracts that previously required the speculative R9 visibility owner.
mobile_test_path = 'bot/tests/ProductionV110MobileShareNotificationCacheRootContractTest.php'
mobile_test = read(mobile_test_path)
start = mobile_test.index('$assert(str_contains($invites, \'initShareVisibilityPrewarm();\')')
end = mobile_test.index('fwrite(STDOUT', start)
replacement = r'''$assert(!str_contains($invites, 'initShareVisibilityPrewarm();')
    && !str_contains($invites, 'nearestVisibleInviteTrigger()')
    && !str_contains($invites, 'SHARE_PREFETCH_ROOT_MARGIN')
    && str_contains($invites, "if (!shareAttempt?.nativePending) cancelWarmShareDraft();"),
    'Direct-player selection must not compete with a speculative page-level prepared-message request.');
$assert(str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && !str_contains($invites, "void discardDraft(attempt.invite).finally"),
    'Cancelling the native Telegram dialog must reuse the still-valid prepared draft instead of forcing another slow request.');
$assert(str_contains($invites, 'SHARE_WARM_KEEPALIVE_MS')
    && str_contains($invites, 'armWarmShareExpiry(entry)'),
    'Reusable prepared drafts must have a bounded canonical lifetime.');
$assert(str_contains($notifications, "document.addEventListener('mgw:app-ready'")
    && str_contains($notifications, 'hydrateLiveItems();')
    && !str_contains($notifications, 'initialized = true;\n  liveItems = loadLiveItems();'),
    'The mobile notification cache must hydrate only after the authenticated app identity is ready.');
$assert(str_contains($notifications, 'setSheetSeed(generation, immediate, preserveSeed || immediate.length > 0);')
    && str_contains($notifications, 'const immediate = mergeNotificationItems(seedItems, currentItems());'),
    'Bell and toast opens must pin their exact known items into the first rendered frame.');
$assert(str_contains($notifications, 'let notificationSheetActive = false;')
    && str_contains($notifications, 'suppressAnnouncementsUntil')
    && str_contains($notifications, 'markCurrentItemsReadLocally();')
    && str_contains($notifications, 'MAX_EMPTY_SHEET_RETRIES'),
    'Closing the mobile notification sheet must not re-announce or touch-through reopen it, and known unread data must not flash as empty.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1114')
    && str_contains($shell, 'notifications-screen-v110r5.js?v=1114')
    && str_contains($entry, 'main-v110.js?v=1114')
    && str_contains($launch, '/app/v110.php?v=1114'),
    'All active mobile entry owners must load the R10 cache-busted build.');

'''
mobile_test = mobile_test[:start] + replacement + mobile_test[end:]
write(mobile_test_path, mobile_test)

canonical_test_path = 'bot/tests/ProductionV110CanonicalShareNotificationRootContractTest.php'
canonical_test = read(canonical_test_path)
canonical_test = canonical_test.replace(
    "str_contains($notifications, 'setSheetSeed(generation, seedItems, preserveSeed)')",
    "str_contains($notifications, 'setSheetSeed(generation, immediate, preserveSeed || immediate.length > 0)')",
)
write(canonical_test_path, canonical_test)

# Add one focused regression contract so the mobile-only root fix cannot be
# replaced later by another global prewarm or close-time announcement.
new_test = ROOT / 'bot/tests/ProductionV110MobileNotificationInviteRestoreContractTest.php'
new_test.write_text(r'''<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R10 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r5.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/v110.php');

$assert(!str_contains($invites, 'scheduleVisibleShareWarm')
    && !str_contains($invites, 'initShareVisibilityPrewarm')
    && str_contains($invites, 'scheduleWarmShareDraft(currentContext(), 0);')
    && str_contains($invites, 'cancelWarmShareDraft();\n    openPlayerPicker(currentContext());'),
    'Player selection must retain the accepted setup prewarm only and cancel it before loading opponents.');
$assert(str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && str_contains($invites, 'armWarmShareExpiry(entry)')
    && str_contains($invites, 'tg.shareMessage(preparedId'),
    'Fast editable Telegram sharing and instant cancellation reuse must remain intact.');
$assert(str_contains($notifications, 'hydrateLiveItems();')
    && str_contains($notifications, 'Date.now() < localAuthorityUntil')
    && str_contains($notifications, 'setSheetSeed(generation, immediate, preserveSeed || immediate.length > 0);'),
    'Authenticated cache hydration and the exact first-frame seed must survive mobile response races.');
$assert(str_contains($notifications, 'const closedNotificationsSheet = notificationSheetActive;')
    && str_contains($notifications, 'suppressToastClickUntil = Math.max')
    && str_contains($notifications, 'suppressAnnouncementsUntil = Math.max')
    && str_contains($notifications, 'markCurrentItemsReadLocally();'),
    'Closing notifications must suppress the mobile ghost click and prevent the same item from reopening the sheet.');
$assert(str_contains($notifications, 'MAX_EMPTY_SHEET_RETRIES = 4')
    && str_contains($notifications, 'renderLoading();')
    && str_contains($notifications, 'void refreshOpenSheet(generation);'),
    'Known unread data must stay in a bounded loading state instead of flashing an incorrect empty screen.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1114')
    && str_contains($shell, 'notifications-screen-v110r5.js?v=1114')
    && str_contains($entry, 'main-v110.js?v=1114'),
    'The canonical production graph must load only the R10 owners.');

fwrite(STDOUT, "ProductionV110MobileNotificationInviteRestoreContractTest: {$assertions} assertions passed\n");
''', encoding='utf-8')
