from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        if new in text:
            return
        raise RuntimeError(f'Expected block not found in {path}: {old[:120]!r}')
    file.write_text(text.replace(old, new, 1))


def replace_all(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        return
    file.write_text(text.replace(old, new))


invites_path = 'app/assets/js/games/game-invites-v110.js'
replace_once(
    invites_path,
    "const SHARE_WARM_DELAY_MS = 40;\nconst MAX_OPPONENTS = 10;",
    "const SHARE_WARM_DELAY_MS = 40;\nconst SHARE_WARM_KEEPALIVE_MS = 180000;\nconst SHARE_PREFETCH_ROOT_MARGIN = '240px 0px';\nconst MAX_OPPONENTS = 10;",
)
replace_once(
    invites_path,
    "let shareWarmTimer = null;\nlet shareWarm = null;\nlet shareWarmSerial = Promise.resolve();\nlet shareAttempt = null;",
    "let shareWarmTimer = null;\nlet shareWarmExpiryTimer = null;\nlet shareVisibleWarmTimer = null;\nlet shareVisibilityObserver = null;\nlet shareWarm = null;\nlet shareWarmSerial = Promise.resolve();\nlet shareAttempt = null;",
)
replace_once(
    invites_path,
    "  initialized = true;\n\n  document.addEventListener('pointerdown', handleInvitePointerDown, true);",
    "  initialized = true;\n  initShareVisibilityPrewarm();\n\n  document.addEventListener('pointerdown', handleInvitePointerDown, true);",
)
replace_once(
    invites_path,
    "      scheduleWatch(0);\n    }\n  });",
    "      scheduleWatch(0);\n      scheduleVisibleShareWarm(80);\n    }\n  });",
)
replace_once(
    invites_path,
    "    appReady = true;\n    scheduleSync(0);\n    scheduleWatch(0);",
    "    appReady = true;\n    scheduleSync(0);\n    scheduleWatch(0);\n    scheduleVisibleShareWarm(0);",
)
replace_once(
    invites_path,
    "  document.addEventListener('mgw:sheet-closed', () => {\n    if (!shareAttempt?.nativePending) cancelWarmShareDraft();\n  });",
    "  document.addEventListener('mgw:sheet-closed', () => {\n    if (!shareAttempt?.nativePending) scheduleVisibleShareWarm(120);\n  });",
)
replace_once(
    invites_path,
    "  document.addEventListener('mgw:before-game-launch', event => {\n    if (!hasActionableInvite()) return;\n    event.preventDefault();\n    openCurrentInvite();\n  }, true);",
    "  document.addEventListener('mgw:before-game-launch', event => {\n    if (hasActionableInvite()) {\n      event.preventDefault();\n      openCurrentInvite();\n      return;\n    }\n    cancelWarmShareDraft();\n  }, true);",
)
replace_once(
    invites_path,
    "function handleInvitePointerDown(event){\n  const trigger = event.target instanceof Element ? event.target.closest('[data-invite-friend]') : null;\n  if (!trigger || hasActionableInvite()) return;\n  const gameType = String(trigger.dataset.inviteFriend || 'tictactoe');\n  scheduleWarmShareDraft(defaultInviteContext(gameType), 0);\n}\n",
    "function handleInvitePointerDown(event){\n  const trigger = event.target instanceof Element ? event.target.closest('[data-invite-friend]') : null;\n  if (!trigger || hasActionableInvite()) return;\n  const gameType = String(trigger.dataset.inviteFriend || 'tictactoe');\n  scheduleWarmShareDraft(defaultInviteContext(gameType), 0);\n}\n\nfunction initShareVisibilityPrewarm(){\n  if (shareVisibilityObserver || typeof IntersectionObserver !== 'function') return;\n  shareVisibilityObserver = new IntersectionObserver(entries => {\n    if (!entries.some(entry => entry.isIntersecting)) return;\n    scheduleVisibleShareWarm(40);\n  }, { root:null, rootMargin:SHARE_PREFETCH_ROOT_MARGIN, threshold:[0.01, 0.35] });\n  document.querySelectorAll('[data-invite-friend]').forEach(trigger => shareVisibilityObserver.observe(trigger));\n}\n\nfunction scheduleVisibleShareWarm(delay = 0){\n  window.clearTimeout(shareVisibleWarmTimer);\n  shareVisibleWarmTimer = window.setTimeout(() => {\n    if (!appReady || shareAttempt?.nativePending || hasActionableInvite()) return;\n    const trigger = nearestVisibleInviteTrigger();\n    if (!trigger) return;\n    scheduleWarmShareDraft(defaultInviteContext(String(trigger.dataset.inviteFriend || 'tictactoe')), 0);\n  }, Math.max(0, Number(delay || 0)));\n}\n\nfunction nearestVisibleInviteTrigger(){\n  const viewportCenter = window.innerHeight / 2;\n  return [...document.querySelectorAll('[data-invite-friend]')]\n    .filter(trigger => {\n      const rect = trigger.getBoundingClientRect();\n      return rect.bottom >= -240 && rect.top <= window.innerHeight + 240;\n    })\n    .sort((a, b) => {\n      const aRect = a.getBoundingClientRect();\n      const bRect = b.getBoundingClientRect();\n      const aDistance = Math.abs((aRect.top + aRect.bottom) / 2 - viewportCenter);\n      const bDistance = Math.abs((bRect.top + bRect.bottom) / 2 - viewportCenter);\n      return aDistance - bDistance;\n    })[0] || null;\n}\n",
)
replace_once(
    invites_path,
    "  const inviteButton = event.target.closest('[data-invite-friend]');\n  if (!inviteButton) return;",
    "  const roomButton = event.target.closest('[data-room]');\n  if (roomButton) window.setTimeout(() => scheduleVisibleShareWarm(0), 0);\n\n  const inviteButton = event.target.closest('[data-invite-friend]');\n  if (!inviteButton) return;",
)
replace_once(
    invites_path,
    "  if (shareWarm?.key === key && ['queued','loading','ready'].includes(String(shareWarm.status || ''))) {\n    return shareWarm.promise;\n  }",
    "  if (shareWarm?.key === key && ['queued','loading','ready'].includes(String(shareWarm.status || ''))) {\n    if (shareWarm.status === 'ready') armWarmShareExpiry(shareWarm);\n    return shareWarm.promise;\n  }",
)
replace_once(
    invites_path,
    "  if (previous?.status === 'ready' && previous.result?.invite?.token) {\n    void discardDraft(previous.result.invite);\n  }",
    "  if (previous?.status === 'ready' && previous.result?.invite?.token) {\n    window.clearTimeout(shareWarmExpiryTimer);\n    void discardDraft(previous.result.invite);\n  }",
)
replace_once(
    invites_path,
    "      entry.result = result;\n      entry.status = 'ready';\n      return result;",
    "      entry.result = result;\n      entry.status = 'ready';\n      armWarmShareExpiry(entry);\n      return result;",
)
replace_once(
    invites_path,
    "function cancelWarmShareDraft(){\n  window.clearTimeout(shareWarmTimer);\n  shareWarmTimer = null;\n  const warm = shareWarm;",
    "function cancelWarmShareDraft(){\n  window.clearTimeout(shareWarmTimer);\n  window.clearTimeout(shareWarmExpiryTimer);\n  shareWarmTimer = null;\n  shareWarmExpiryTimer = null;\n  const warm = shareWarm;",
)
replace_once(
    invites_path,
    "}\n\nasync function obtainPreparedShareResult(context){",
    "}\n\nfunction armWarmShareExpiry(entry){\n  window.clearTimeout(shareWarmExpiryTimer);\n  shareWarmExpiryTimer = window.setTimeout(() => {\n    if (shareWarm?.id !== entry?.id || shareAttempt?.nativePending) return;\n    const stale = shareWarm;\n    shareWarm = null;\n    shareWarmExpiryTimer = null;\n    if (stale?.status === 'ready' && stale.result?.invite?.token) void discardDraft(stale.result.invite);\n  }, SHARE_WARM_KEEPALIVE_MS);\n}\n\nfunction restoreWarmShareDraft(attempt){\n  const invite = cloneInvite(attempt?.invite);\n  const token = String(invite?.token || '');\n  const preparedId = String(invite?.prepared_message_id || '');\n  if (!token || !preparedId) {\n    scheduleWarmShareDraft(attempt?.context || defaultInviteContext('tictactoe'), 0);\n    return;\n  }\n\n  const context = normalizeInviteContext(attempt.context);\n  const result = { invite };\n  const entry = {\n    id:++shareWarmSequence,\n    key:inviteContextKey(context),\n    context,\n    status:'ready',\n    result,\n    promise:Promise.resolve(result),\n  };\n  shareWarm = entry;\n  armWarmShareExpiry(entry);\n}\n\nasync function obtainPreparedShareResult(context){",
)
replace_once(
    invites_path,
    "  const result = await warm.promise;\n  if (!result?.invite?.token) throw new Error('Не удалось подготовить ссылку.');\n  if (shareWarm?.id === warm.id) shareWarm = null;",
    "  const result = await warm.promise;\n  if (!result?.invite?.token) throw new Error('Не удалось подготовить ссылку.');\n  if (shareWarm?.id === warm.id) {\n    shareWarm = null;\n    window.clearTimeout(shareWarmExpiryTimer);\n    shareWarmExpiryTimer = null;\n  }",
)
replace_once(
    invites_path,
    "  if (String(errorCode || '') === 'USER_DECLINED' || String(errorCode || '') === '') {\n    void discardDraft(attempt.invite).finally(() => {\n      if (isInviteSetupOpen()) scheduleWarmShareDraft(attempt.context, 0);\n    });\n    return;\n  }",
    "  if (String(errorCode || '') === 'USER_DECLINED' || String(errorCode || '') === '') {\n    restoreWarmShareDraft(attempt);\n    return;\n  }",
)

notifications_path = 'app/assets/js/screens/notifications-screen-v110r5.js'
replace_once(
    notifications_path,
    "const EMPTY_RETRY_MS = 160;",
    "const EMPTY_RETRY_MS = 160;\nconst LIVE_STORAGE_KEY_PREFIX = 'mgw_notification_live_items_v1';\nconst LIVE_STORAGE_TTL_MS = 900000;",
)
replace_once(
    notifications_path,
    "let seededSheetItems = [];\nlet seededSheetUntil = 0;\nlet notificationAuthorityRevision = 0;",
    "let seededSheetItems = [];\nlet notificationAuthorityRevision = 0;",
)
replace_once(
    notifications_path,
    "  initialized = true;\n  ensureToast();",
    "  initialized = true;\n  liveItems = loadLiveItems();\n  ensureToast();",
)
replace_once(
    notifications_path,
    "    seededSheetGeneration = 0;\n    seededSheetItems = [];\n    seededSheetUntil = 0;",
    "    seededSheetGeneration = 0;\n    seededSheetItems = [];",
)
replace_once(
    notifications_path,
    "  mergeItems(seedItems);\n  const immediate = currentItems();",
    "  mergeItems(seedItems);\n  const immediate = mergeNotificationItems(seedItems, currentItems());",
)
replace_once(
    notifications_path,
    "    seededSheetGeneration = 0;\n    seededSheetItems = [];\n    seededSheetUntil = 0;\n    return;",
    "    seededSheetGeneration = 0;\n    seededSheetItems = [];\n    return;",
)
replace_once(
    notifications_path,
    "  seededSheetGeneration = generation;\n  seededSheetItems = mergeNotificationItems(items, []);\n  seededSheetUntil = Date.now() + 2000;",
    "  seededSheetGeneration = generation;\n  seededSheetItems = mergeNotificationItems(items, []);",
)
replace_once(
    notifications_path,
    "function sheetSeedItems(generation){\n  if (generation !== seededSheetGeneration || Date.now() > seededSheetUntil) return [];\n  return seededSheetItems.map(cloneItem);\n}",
    "function sheetSeedItems(generation){\n  if (generation !== seededSheetGeneration) return [];\n  return seededSheetItems.map(cloneItem);\n}",
)
replace_once(
    notifications_path,
    "function reconcileItems(items){\n  const authoritative = Array.isArray(items) ? items : [];\n  liveItems = new Map(\n    authoritative\n      .slice(0, MAX_LIVE_ITEMS)\n      .map(item => cloneItem(item))\n      .filter(item => String(item?.id || '') !== '')\n      .map(item => [String(item.id), item])\n  );\n}",
    "function reconcileItems(items){\n  const authoritative = Array.isArray(items) ? items : [];\n  liveItems = new Map(\n    authoritative\n      .slice(0, MAX_LIVE_ITEMS)\n      .map(item => cloneItem(item))\n      .filter(item => String(item?.id || '') !== '')\n      .map(item => [String(item.id), item])\n  );\n  persistLiveItems();\n}",
)
replace_once(
    notifications_path,
    "function upsert(item){\n  const id = String(item?.id || '');\n  if (!id) return;\n  liveItems.set(id, { ...(liveItems.get(id) || {}), ...cloneItem(item) });\n  liveItems = new Map(currentItems(MAX_LIVE_ITEMS).map(value => [String(value.id), value]));\n}",
    "function upsert(item){\n  const id = String(item?.id || '');\n  if (!id) return;\n  liveItems.set(id, { ...(liveItems.get(id) || {}), ...cloneItem(item) });\n  liveItems = new Map(currentItems(MAX_LIVE_ITEMS).map(value => [String(value.id), value]));\n  persistLiveItems();\n}",
)
replace_once(
    notifications_path,
    "function formatDate(value){",
    "function notificationCacheKey(){\n  let scope = String(getSessionId() || 'anonymous');\n  try {\n    const rawUser = new URLSearchParams(getInitData()).get('user');\n    const user = rawUser ? JSON.parse(rawUser) : null;\n    if (user?.id) scope = String(user.id);\n  } catch (error) {}\n  return `${LIVE_STORAGE_KEY_PREFIX}:${scope}`;\n}\n\nfunction loadLiveItems(){\n  try {\n    const parsed = JSON.parse(localStorage.getItem(notificationCacheKey()) || 'null');\n    if (!parsed || Date.now() - Number(parsed.saved_at || 0) > LIVE_STORAGE_TTL_MS) return new Map();\n    const items = Array.isArray(parsed.items) ? parsed.items : [];\n    return new Map(\n      items\n        .slice(0, MAX_LIVE_ITEMS)\n        .map(cloneItem)\n        .filter(item => String(item?.id || '') !== '')\n        .map(item => [String(item.id), item])\n    );\n  } catch (error) {\n    return new Map();\n  }\n}\n\nfunction persistLiveItems(){\n  try {\n    localStorage.setItem(notificationCacheKey(), JSON.stringify({\n      saved_at:Date.now(),\n      items:currentItems(MAX_LIVE_ITEMS),\n    }));\n  } catch (error) {}\n}\n\nfunction formatDate(value){",
)

for path in [
    'app/assets/js/main-v110-handoff-shell.js',
    'app/assets/js/main-v110.js',
    'app/assets/js/production-clean-entry-v110.js',
    'app/v110.php',
]:
    replace_all(path, 'v110-mvp14r8-canonical-share-notifications-root', 'v110-mvp14r9-mobile-share-notification-cache-root')
    replace_all(path, '?v=1112', '?v=1113')

replace_all('bot/helpers/WebAppLaunchUrl.php', "/app/v110.php?v=1111", "/app/v110.php?v=1113")

for test in Path('bot/tests').glob('*.php'):
    text = test.read_text()
    updated = text.replace('?v=1112', '?v=1113')
    updated = updated.replace('/app/v110.php?v=1111', '/app/v110.php?v=1113')
    updated = updated.replace('v110-mvp14r8-canonical-share-notifications-root', 'v110-mvp14r9-mobile-share-notification-cache-root')
    if updated != text:
        test.write_text(updated)

contract = Path('bot/tests/ProductionV110MobileShareNotificationCacheRootContractTest.php')
contract.write_text(r'''<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R9 source: ' . $path);
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
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$assert(str_contains($invites, 'initShareVisibilityPrewarm();')
    && str_contains($invites, 'nearestVisibleInviteTrigger()')
    && str_contains($invites, 'SHARE_PREFETCH_ROOT_MARGIN'),
    'Visible mobile invite controls must prewarm the canonical prepared message before the share tap.');
$assert(str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && !str_contains($invites, "void discardDraft(attempt.invite).finally"),
    'Cancelling the native Telegram dialog must reuse the still-valid prepared draft instead of forcing another slow request.');
$assert(str_contains($invites, 'SHARE_WARM_KEEPALIVE_MS')
    && str_contains($invites, 'armWarmShareExpiry(entry)'),
    'Reusable prepared drafts must have a bounded canonical lifetime.');
$assert(str_contains($notifications, 'liveItems = loadLiveItems();')
    && str_contains($notifications, 'persistLiveItems();')
    && str_contains($notifications, 'LIVE_STORAGE_TTL_MS'),
    'Notification cards must survive mobile WebView reloads long enough for an immediate first bell paint.');
$assert(str_contains($notifications, 'if (generation !== seededSheetGeneration) return [];')
    && !str_contains($notifications, 'Date.now() > seededSheetUntil')
    && !str_contains($notifications, 'seededSheetUntil = Date.now() + 2000'),
    'The clicked toast item must remain pinned for the entire open sheet generation, regardless of mobile latency.');
$assert(str_contains($notifications, 'const immediate = mergeNotificationItems(seedItems, currentItems());'),
    'The exact tapped toast item must be part of the first rendered notification frame.');
$assert(str_contains($shell, 'game-invites-v110.js?v=1113')
    && str_contains($shell, 'notifications-screen-v110r5.js?v=1113')
    && str_contains($entry, 'main-v110.js?v=1113')
    && str_contains($launch, '/app/v110.php?v=1113'),
    'All active mobile entry owners must load the R9 cache-busted build.');

fwrite(STDOUT, "ProductionV110MobileShareNotificationCacheRootContractTest: {$assertions} assertions passed\n");
''')
