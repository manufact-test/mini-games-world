from pathlib import Path
import re


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f'Missing {label}: {old[:120]!r}')
    return text.replace(old, new, 1)


# Preserve the currently announced item as an explicit seed for the canonical
# notification sheet. This is state transfer inside one owner, not a second cache owner.
notifications_path = Path('app/assets/js/screens/notifications-screen-v99.js')
notifications = notifications_path.read_text()
notifications = replace_once(
    notifications,
    "let pendingNotification = null;\nlet announcedIds = loadAnnouncedIds();",
    "let pendingNotification = null;\nlet activeToastNotification = null;\nlet announcedIds = loadAnnouncedIds();",
    'active toast state',
)
notifications = replace_once(
    notifications,
    """  event.preventDefault();
  event.stopPropagation();
  dismissNotificationToast();
  loadNotificationsSheet({ hapticFeedback:true, keepShell:false });
}""",
    """  const seedItems = trigger.id === 'notificationToast' && activeToastNotification
    ? [activeToastNotification]
    : [];
  event.preventDefault();
  event.stopPropagation();
  dismissNotificationToast();
  loadNotificationsSheet({ hapticFeedback:true, keepShell:false, seedItems });
}""",
    'notification activation seed transfer',
)
notifications = replace_once(
    notifications,
    "async function loadNotificationsSheet({ hapticFeedback = true, keepShell = false } = {}){",
    "async function loadNotificationsSheet({ hapticFeedback = true, keepShell = false, seedItems = [] } = {}){",
    'notification loader signature',
)
notifications = replace_once(
    notifications,
    """  if (!keepShell || !alreadyOpen) {
    sheetState = 'opening';
    openNotificationsShell();
  }
  sheetState = 'loading';
  renderNotificationsLoading();

  try {
    const result = await api.notifications(true);
    if (!canApplySheetResult(generation)) return;
    sheetItems = normalizeItems(result?.items);
    rememberNotifications(sheetItems);""",
    """  if (!keepShell || !alreadyOpen) {
    sheetState = 'opening';
    openNotificationsShell();
  }

  const immediateItems = normalizeItems(seedItems);
  if (immediateItems.length) {
    sheetItems = mergeNotificationCollections([], immediateItems);
    sheetState = 'ready';
    renderNotificationsBody(sheetItems);
  } else {
    sheetState = 'loading';
    renderNotificationsLoading();
  }

  try {
    const result = await api.notifications(true);
    if (!canApplySheetResult(generation)) return;
    sheetItems = mergeNotificationCollections(normalizeItems(result?.items), immediateItems);
    rememberNotifications(sheetItems);""",
    'notification immediate sheet state',
)
notifications = replace_once(
    notifications,
    """function mergeSheetItems(items){
  const byId = new Map(sheetItems.map(item => [String(item?.id || ''), item]));
  for (const item of normalizeItems(items)) {
    const id = String(item?.id || '');
    if (!id) continue;
    byId.set(id, { ...byId.get(id), ...item });
  }
  sheetItems = Array.from(byId.values())
    .sort((left, right) => dateValue(right?.created_at) - dateValue(left?.created_at))
    .slice(0, 30);
}""",
    """function mergeSheetItems(items){
  sheetItems = mergeNotificationCollections(sheetItems, items);
}

function mergeNotificationCollections(primaryItems, overlayItems){
  const byId = new Map();
  for (const item of normalizeItems(primaryItems)) {
    const id = String(item?.id || '');
    if (id) byId.set(id, item);
  }
  for (const item of normalizeItems(overlayItems)) {
    const id = String(item?.id || '');
    if (!id) continue;
    byId.set(id, { ...byId.get(id), ...item });
  }
  return Array.from(byId.values())
    .sort((left, right) => dateValue(right?.created_at) - dateValue(left?.created_at))
    .slice(0, 30);
}""",
    'notification collection merger',
)
notifications = replace_once(
    notifications,
    """  window.clearTimeout(notificationToastTimer);
  notificationToastPointer = null;
  el.className = `notification-toast ${tone}`;""",
    """  window.clearTimeout(notificationToastTimer);
  notificationToastPointer = null;
  activeToastNotification = item;
  el.className = `notification-toast ${tone}`;""",
    'active toast assignment',
)
notifications = replace_once(
    notifications,
    """  notificationToastTimer = null;
  notificationToastPointer = null;
  const el = document.getElementById('notificationToast');""",
    """  notificationToastTimer = null;
  notificationToastPointer = null;
  activeToastNotification = null;
  const el = document.getElementById('notificationToast');""",
    'active toast cleanup',
)
for required in [
    'activeToastNotification = item',
    'seedItems = []',
    'mergeNotificationCollections(normalizeItems(result?.items), immediateItems)',
]:
    if required not in notifications:
        raise SystemExit(f'Missing canonical notification seed behavior: {required}')
notifications_path.write_text(notifications)


# The real cached-toast behavior remains relevant, but the canonical owner performs
# one mark-read request. It must retain the seed instead of adding retry owners.
acceptance_path = Path('e2e/staging/d1-followup-acceptance-v120.spec.mjs')
acceptance = acceptance_path.read_text()
acceptance = replace_once(
    acceptance,
    'expect(markReadCalls).toBeGreaterThanOrEqual(2);',
    'expect(markReadCalls).toBeGreaterThanOrEqual(1);',
    'single canonical mark-read request expectation',
)
acceptance_path.write_text(acceptance)


# Replace the two tests that asserted deleted patch mechanics with canonical contracts.
real_user_path = Path('e2e/staging/d1-real-user-regressions-v127.spec.mjs')
real_user = real_user_path.read_text()
real_user, count = re.subn(
    r"test\('D1 v127: ordinary Start bell survives a compatibility click retargeted to the new overlay'.*?\n\}\);\n\n",
    """test('canonical ordinary Start bell opens through one browser click', async ({ browser }) => {
  const player = await openOrdinaryStart(browser);
  try {
    await player.page.locator('#notificationsOpen').click();
    await expect(player.page.locator('#sheetOverlay')).toHaveClass(/active/);
    await expect(player.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления');
    await expect(player.page.locator('#sheet [data-notifications-sheet]')).toHaveCount(1);
  } finally {
    await revokeAndClose(player.context);
  }
});

""",
    real_user,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace obsolete pointerup compatibility test')

real_user, count = re.subn(
    r"test\('D1 v127: manual player picker replaces a stale non-empty boot list with a fresh player'.*?\n\}\);\s*$",
    """test('canonical manual player picker performs no boot fetch and one fresh request', async ({ browser }) => {
  const context = await browser.newContext({
    locale:'ru-RU',
    timezoneId:'Europe/Vilnius',
    viewport:{ width:1280, height:900 },
    deviceScaleFactor:1,
  });
  let opponentCalls = 0;

  await context.route(OPPONENTS_ROUTE, async route => {
    opponentCalls += 1;
    await route.fulfill({
      status:200,
      contentType:'application/json; charset=utf-8',
      body:JSON.stringify({
        ok:true,
        authoritative:true,
        storage_driver:'database',
        items:[{
          id:'stg_test_player_b',
          name:'@mgw_test_player_b',
          activity:'онлайн',
          online:true,
          busy:false,
          last_game_at:'',
          last_seen_at:new Date().toISOString(),
        }],
      }),
    });
  });

  await authorizeContext(context, 'A');
  const page = await context.newPage();
  try {
    const bootstrap = page.waitForResponse(response => response.url() === API_ROUTE
      && response.request().method() === 'POST'
      && requestAction(response) === 'bootstrap', { timeout:35_000 });
    const response = await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded' });
    expect(response?.ok()).toBe(true);
    expect((await bootstrap).status()).toBe(200);
    await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });
    expect(opponentCalls).toBe(0);

    await page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(page.locator('[data-open-player-picker]')).toBeVisible();
    await page.locator('[data-open-player-picker]').click();

    await expect(page.locator('[data-direct-opponent="stg_test_player_b"]')).toBeVisible({ timeout:5_000 });
    await expect(page.locator('#sheet')).toContainText('@mgw_test_player_b');
    expect(opponentCalls).toBe(1);
  } finally {
    await context.unroute(OPPONENTS_ROUTE);
    await revokeAndClose(context);
  }
});
""",
    real_user,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace obsolete boot-cache test')
real_user_path.write_text(real_user)


# Replace retry-based empty confirmation with the single-authority empty state contract.
stress_path = Path('e2e/staging/d1-followup-stress.spec.mjs')
stress = stress_path.read_text()
stress, count = re.subn(
    r"test\('D1 follow-up: desktop player picker confirms transient empty snapshots before rendering'.*?\n\}\);\s*$",
    """test('canonical desktop picker renders empty only after one authoritative response', async ({ browser }) => {
  let playerA;
  let opponentCalls = 0;
  try {
    playerA = await openPlayer(browser, 'A', {
      isMobile:false,
      beforeGoto:async page => {
        await page.route(OPPONENTS_ROUTE, async route => {
          opponentCalls += 1;
          await delay(350);
          return route.fulfill({
            status:200,
            contentType:'application/json; charset=utf-8',
            body:JSON.stringify({
              ok:true,
              authoritative:true,
              storage_driver:'database',
              items:[],
            }),
          });
        });
      },
    });
    await cleanupPlayer(playerA);
    expect(opponentCalls).toBe(0);

    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('[data-open-player-picker]')).toBeVisible({ timeout:15_000 });
    await playerA.page.locator('[data-open-player-picker]').click();

    await expect(playerA.page.locator('[data-player-picker-state="loading"]')).toBeVisible();
    await expect(playerA.page.locator('[data-player-picker-state="empty"]')).toHaveCount(0);
    await expect(playerA.page.locator('[data-player-picker-state="empty"]')).toBeVisible({ timeout:5_000 });
    expect(opponentCalls).toBe(1);

    await playerA.page.unroute(OPPONENTS_ROUTE);
    expectClean(playerA, 'Player A canonical confirmed empty');
  } finally {
    if (playerA) await cleanupPlayer(playerA);
    await closePlayer(playerA);
  }
});
""",
    stress,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace obsolete player-picker retry test')
stress_path.write_text(stress)
