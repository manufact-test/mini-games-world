from pathlib import Path
import re


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f'Missing {label}: {old[:120]!r}')
    return text.replace(old, new, 1)


# Readiness may warm read-only profile/history/notification/order snapshots only.
path = Path('app/assets/js/first-interaction-readiness.js')
text = path.read_text()
for old, new, label in [
    ("import { APP_CONFIG } from './config.js?v=38';\n", "", 'APP_CONFIG import'),
    ("import { openSheet, closeSheet } from './components/sheet.js?v=68';", "import { openSheet } from './components/sheet.js?v=68';", 'sheet import'),
    ("import { toast } from './components/toast.js?v=41';\n", "", 'toast import'),
    ("import { getTelegram, getInitData, haptic } from './telegram/telegram-app.js?v=27';\n", "", 'telegram import'),
    ("import { getSessionId } from './session.js?v=27';\n", "", 'session import'),
    ("const INVITES_URL = `${window.location.origin}/bot/invites.php`;\n", "", 'invites URL'),
    ("const DRAFT_WARM_DELAY_MS = 60;\n", "", 'draft warm delay'),
    ("let draftWarmTimer = null;\n", "", 'draft timer'),
    ("let draftSerial = Promise.resolve();\n", "", 'draft serial'),
    ("let draftGeneration = 0;\n", "", 'draft generation'),
    ("let preparedDraft = null;\n", "", 'prepared draft'),
    ("let shareBusy = false;\n", "", 'share busy'),
]:
    text = replace_once(text, old, new, label)

for block, label in [
    ("\n  if (target.matches('[data-invite-friend]')) {\n    queueMicrotask(() => scheduleCurrentDraftWarm(0));\n    return;\n  }\n", 'invite warm click'),
    ("\n  if (target.matches('[data-invite-size], [data-invite-bet]')) {\n    queueMicrotask(() => scheduleCurrentDraftWarm(DRAFT_WARM_DELAY_MS));\n    return;\n  }\n", 'invite option warm click'),
    ("\n\n  if (!target.matches('[data-create-link-invite]')) return;\n\n  event.preventDefault();\n  event.stopImmediatePropagation();\n  sharePreparedLink(target);\n", 'share interception'),
]:
    text = replace_once(text, block, "\n", label)

text, count = re.subn(
    r"\nfunction scheduleCurrentDraftWarm\(.*?\nfunction refreshHistorySnapshot",
    "\nfunction refreshHistorySnapshot",
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to remove readiness draft/share function block')

text, count = re.subn(
    r"\nasync function inviteRequest\(.*?\nfunction mergeUserState",
    "\nfunction mergeUserState",
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to remove readiness invite transport block')

for forbidden in [
    'data-create-link-invite', 'create_link_draft', 'sharePreparedLink',
    'openTelegramShare', 'OPPONENTS_URL', 'refreshOpponentsNetwork',
    'opponentsCache', 'window.fetch =', 'INVITES_URL', 'getInitData',
]:
    if forbidden in text:
        raise SystemExit(f'Readiness still owns forbidden responsibility: {forbidden}')
path.write_text(text)


# Retire tests whose sole purpose was to require the deleted patch graph.
retired_tests = [
    'bot/tests/ProductionMvp14D1FeedbackDesktopBellFirstClickTest.php',
    'bot/tests/ProductionMvp14D1ImmutableCoreSingleOwnerTest.php',
    'bot/tests/ProductionMvp14D1MobileNotificationFirstFrameV117Test.php',
    'bot/tests/ProductionMvp14D1DeepLinkToastPolicyV130Test.php',
    'bot/tests/ProductionMvp14D1DesktopBellFirstClickV117Test.php',
    'bot/tests/ProductionMvp14D1OpponentAuthoritativeSourceV122Test.php',
    'bot/tests/ProductionMvp14D1ShortInputOwnerV121Test.php',
    'bot/tests/ProductionMvp14D1OpponentAuthoritativeConfirmV117Test.php',
    'bot/tests/ProductionMvp14R13NotificationSingleOwnerTest.php',
    'bot/tests/ProductionMvp14D1FeedbackIntegrationTest.php',
    'bot/tests/ProductionMvp14D1FeedbackMobileNotificationEmptyFrameTest.php',
    'bot/tests/ProductionMvp14D1FeedbackOpponentZeroFlickerTest.php',
    'bot/tests/ProductionMvp14D1RealUserRegressionsV127Test.php',
    'bot/tests/ProductionMvp14D1CanonicalNotificationOwnerV119Test.php',
    'bot/tests/ProductionMvp14D1FollowupIntegrationV117Test.php',
]
for filename in retired_tests:
    candidate = Path(filename)
    if candidate.exists():
        candidate.unlink()


Path('bot/tests/ProductionMvp14D1CanonicalOwnersArchitectureTest.php').write_text(r'''<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing architecture source: ' . $path);
    return $content;
};
$entry = $read('app/v114.php');
$main = $read('app/assets/js/main.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v99.js');
$readiness = $read('app/assets/js/first-interaction-readiness.js');
$invites = $read('app/assets/js/games/game-invites.js');
$inviteLink = $read('app/assets/js/games/invite-link-entry-v115.js');
$endpoint = $read('bot/invite-opponents.php');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$retired = [
    'app/assets/js/notification-deeplink-toast-policy-v131.js',
    'app/assets/js/notification-compat-click-guard-v127.js',
    'app/assets/js/screens/notification-window-owner-v121.js',
    'app/assets/js/screens/notifications-passive-v130.js',
    'app/assets/js/opponents-native-fetch-v115.js',
    'app/assets/js/opponents-empty-cache-guard-v115.js',
    'app/assets/js/opponents-authoritative-confirm-v122.js',
    'app/assets/js/opponents-fresh-user-action-v128.js',
    'app/assets/js/first-interaction-readiness-v103.js',
];
foreach ($retired as $file) $assert(!is_file($root . '/' . $file), 'Retired patch file still exists: ' . $file);
$assert(str_contains($entry, './assets/js/main.js?v=d1')
    && str_contains($entry, 'X-MGW-Frontend-Build: d1-canonical-owners')
    && str_contains($entry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'Staging entry must publish the canonical no-cache graph.');
foreach (['notification-compat-click-guard', 'notification-window-owner', 'notifications-passive',
          'notification-deeplink-toast-policy', 'opponents-native-fetch', 'opponents-empty-cache-guard',
          'opponents-authoritative-confirm', 'opponents-fresh-user-action'] as $name) {
    $assert(!str_contains($entry, $name) && !str_contains($main, $name), 'Active graph still names retired layer: ' . $name);
}
$assert(substr_count($main, "./screens/notifications-screen-v99.js?v=d1") === 1
    && substr_count($main, 'initNotificationsScreen();') === 1,
    'Main must initialize one canonical notification owner.');
$assert(substr_count($notifications, "document.addEventListener('click', handleNotificationActivation)") === 1
    && !str_contains($notifications, "window.addEventListener('pointerdown'")
    && !str_contains($notifications, "window.addEventListener('pointerup'")
    && !str_contains($notifications, 'MutationObserver')
    && str_contains($notifications, "let sheetState = 'closed'")
    && str_contains($notifications, 'let sheetGeneration = 0')
    && str_contains($notifications, 'openNotificationsShell()')
    && str_contains($notifications, 'data-notifications-body'),
    'Notifications must own one click path and one explicit sheet state machine.');
$assert(str_contains($notifications, "document.addEventListener('mgw:invite-link-opening'")
    && str_contains($notifications, "document.addEventListener('mgw:invite-link-resolved'")
    && str_contains($notifications, 'event.detail?.announce !== false'),
    'Deep-link silence must be an explicit canonical transition.');
$assert(!str_contains($readiness, 'window.fetch =')
    && !str_contains($readiness, 'invite-opponents.php')
    && !str_contains($readiness, 'data-create-link-invite')
    && !str_contains($readiness, 'create_link_draft')
    && !str_contains($readiness, 'openTelegramShare'),
    'Readiness may not own opponents transport or Share.');
$assert(substr_count($invites, 'postJson(OPPONENTS_URL') === 1
    && str_contains($invites, "cache:'no-store'")
    && str_contains($invites, "result?.authoritative !== true")
    && str_contains($invites, 'new AbortController()')
    && str_contains($invites, 'data-player-picker-body')
    && str_contains($invites, 'data-player-picker-state="loading"')
    && str_contains($invites, 'data-player-picker-state="loaded"')
    && str_contains($invites, 'data-player-picker-state="empty"')
    && str_contains($invites, 'data-player-picker-state="error"')
    && !str_contains($invites, 'window.fetch ='),
    'Player picker must own one fresh request and one explicit UI state machine.');
$assert(str_contains($endpoint, 'new DatabasePrimaryStateStorageAdapter(')
    && str_contains($endpoint, '$storage->readOnly(')
    && str_contains($endpoint, "'authoritative' => true")
    && str_contains($endpoint, "'storage_driver' => \$storage->driver()"),
    'Opponent endpoint must remain the authoritative DB-primary reader.');
$assert(str_contains($inviteLink, "publishInviteLinkLifecycle('mgw:invite-link-opening'")
    && str_contains($inviteLink, "publishInviteLinkLifecycle('mgw:invite-link-resolved'")
    && str_contains($inviteLink, 'announce:false'),
    'Invite-link entry must publish explicit silent lifecycle intent.');
fwrite(STDOUT, "ProductionMvp14D1CanonicalOwnersArchitectureTest: {$assertions} assertions passed\n");
''')

Path('bot/tests/ProductionMvp14D1DeepLinkCanonicalTransitionTest.php').write_text(r'''<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
$entry = file_get_contents($root . '/app/assets/js/games/invite-link-entry-v115.js');
if (!is_string($notifications) || !is_string($entry)) throw new RuntimeException('Missing deep-link canonical sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(str_contains($entry, "publishInviteLinkLifecycle('mgw:invite-link-opening', token, false)")
    && str_contains($entry, "publishInviteLinkLifecycle('mgw:invite-link-resolved', token, opened)")
    && str_contains($entry, 'detail:{ item, unreadCount, announce:false }'),
    'Invite entry must publish one explicit silent lifecycle.');
$assert(str_contains($notifications, 'let silentInviteToken = incomingInviteToken()')
    && str_contains($notifications, 'isSilentInviteNotification(item)')
    && str_contains($notifications, 'rememberNotificationId(id)')
    && str_contains($notifications, 'dismissNotificationToast()'),
    'Canonical notification owner must consume the matching notification silently.');
$assert(!str_contains($notifications, '__MGW_INVITE_LINK_OPENING__')
    && !str_contains($notifications, 'MutationObserver')
    && !str_contains($notifications, 'setInterval(() => {\n    hideToastNow')
    && !str_contains($notifications, 'visibility:hidden!important'),
    'Deep-link behavior must not use a global flag, DOM observer, polling hider or CSS patch.');
fwrite(STDOUT, "ProductionMvp14D1DeepLinkCanonicalTransitionTest: {$assertions} assertions passed\n");
''')

Path('bot/tests/ProductionMvp14R13MainEntryCacheBustTest.php').write_text(r'''<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$index = file_get_contents($root . '/app/index.html');
$entry = file_get_contents($root . '/app/v114.php');
$main = file_get_contents($root . '/app/assets/js/main.js');
if (!is_string($index) || !is_string($entry) || !is_string($main)) throw new RuntimeException('Missing canonical main entry source.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(substr_count($index, './assets/js/main.js?v=98.3') === 1
    && str_contains($entry, "'./assets/js/main.js?v=98.3'")
    && str_contains($entry, "'./assets/js/main.js?v=d1'"),
    'Source shell must retain one replacement anchor and staging must publish canonical main.');
$assert(str_contains($entry, 'data-hotfix-build="d1-canonical-owners"')
    && str_contains($entry, 'X-MGW-Frontend-Build: d1-canonical-owners')
    && str_contains($main, "window.__MGW_BUILD__ = 'd1-canonical-owners'"),
    'Served shell, response header and main marker must identify the canonical graph.');
$assert(!str_contains($main, '__MGW_HOTFIX_BUILD__')
    && !str_contains($entry, 'notification-window-owner')
    && !str_contains($entry, 'notification-compat-click-guard')
    && !str_contains($entry, 'opponents-authoritative-confirm'),
    'No hotfix marker or injected owner may remain.');
$assert(str_contains($index, './assets/js/production-regression-fix-entry.js?v=102'),
    'Unrelated regression entry must retain its reviewed identity.');
$assert(!str_contains($entry, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($entry, 'mini-games-world.com')
    && !str_contains($main, 'mini-games-world.com'),
    'Staging package must not introduce a production target.');
fwrite(STDOUT, "ProductionMvp14R13MainEntryCacheBustTest: {$assertions} assertions passed\n");
''')

Path('bot/tests/ProductionMvp14R13ReadinessShareSingleOwnerTest.php').write_text(r'''<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$readiness = file_get_contents($root . '/app/assets/js/first-interaction-readiness.js');
$invites = file_get_contents($root . '/app/assets/js/games/game-invites.js');
if (!is_string($main) || !is_string($readiness) || !is_string($invites)) throw new RuntimeException('Missing current Share ownership source.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(str_contains($main, "./first-interaction-readiness.js?v=d1")
    && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1
    && substr_count($main, 'initGameInvites();') === 1,
    'Main must initialize one readiness service and one invite coordinator.');
$assert(!str_contains($readiness, 'data-create-link-invite')
    && !str_contains($readiness, 'create_link_draft')
    && !str_contains($readiness, 'sharePreparedLink')
    && !str_contains($readiness, 'openTelegramShare')
    && !str_contains($readiness, 'invite-opponents.php')
    && !str_contains($readiness, 'window.fetch ='),
    'Readiness must not intercept Share or opponents transport.');
$assert(str_contains($readiness, 'warmProfileSnapshot()')
    && str_contains($readiness, 'warmHistorySnapshot()')
    && str_contains($readiness, 'warmNotificationsSnapshot()')
    && str_contains($readiness, 'warmShopOrders()'),
    'Readiness must remain a read-only warmup service.');
$assert(str_contains($invites, 'data-create-link-invite')
    && str_contains($invites, 'async function createLinkDraft(context, button)')
    && str_contains($invites, 'showPreparedLink(draftInvite, context);')
    && str_contains($invites, 'data-copy-invite-link')
    && str_contains($invites, 'data-discard-draft'),
    'Invite coordinator must exclusively own complete Share UI.');
fwrite(STDOUT, "ProductionMvp14R13ReadinessShareSingleOwnerTest: {$assertions} assertions passed\n");
''')


# Keep broad latency contract, but remove assertions that required stale opponent caches.
hot_path = Path('bot/tests/ProductionHotPathLatencyFixContractTest.php')
hot = hot_path.read_text()
hot = replace_once(hot, "$readiness = $read('app/assets/js/first-interaction-readiness-v103.js');", "$readiness = $read('app/assets/js/first-interaction-readiness.js');", 'hot-path readiness source')
hot = replace_once(
    hot,
    """$assertTrue(
    str_contains($readiness, 'export async function warmFirstInteractionData()')
        && str_contains($readiness, 'warmProfileSnapshot()')
        && str_contains($readiness, 'api.history()')
        && str_contains($readiness, 'api.notifications(false)')
        && str_contains($readiness, 'warmShopOrders()')
        && str_contains($readiness, 'refreshOpponentsNetwork(true)')
        && str_contains($readiness, 'Promise.allSettled(tasks)'),
    'The common preloader must keep warming every first-click data source.'
);

$assertTrue(
    str_contains($readiness, "[data-invite-friend], [data-open-player-picker]")
        && str_contains($readiness, 'refreshOpponentsNetwork(false)')
        && !str_contains($readiness, 'data-create-link-invite')
        && !str_contains($readiness, 'create_link_draft')
        && !str_contains($readiness, 'openTelegramShare')
        && str_contains($invites, 'data-create-link-invite')
        && str_contains($invites, 'async function createLinkDraft(context, button)')
        && str_contains($invites, 'showPreparedLink(draftInvite, context);'),
    'Readiness must stay read-only while the invite coordinator exclusively owns Share creation and fallback UI.'
);

$assertTrue(
    str_contains($readiness, 'opponentsCache?.data')
        && str_contains($readiness, 'return jsonResponse(opponentsCache.data)')
        && str_contains($readiness, "url.pathname.endsWith('/bot/invite-opponents.php')"),
    'The player picker must receive a same-frame cached opponent response.'
);""",
    """$assertTrue(
    str_contains($readiness, 'export async function warmFirstInteractionData()')
        && str_contains($readiness, 'warmProfileSnapshot()')
        && str_contains($readiness, 'api.history()')
        && str_contains($readiness, 'api.notifications(false)')
        && str_contains($readiness, 'warmShopOrders()')
        && str_contains($readiness, 'Promise.allSettled(tasks)')
        && !str_contains($readiness, 'invite-opponents.php')
        && !str_contains($readiness, 'window.fetch ='),
    'The common preloader must warm read-only snapshots without owning player-picker transport.'
);

$assertTrue(
    !str_contains($readiness, 'data-create-link-invite')
        && !str_contains($readiness, 'create_link_draft')
        && !str_contains($readiness, 'openTelegramShare')
        && str_contains($invites, 'data-create-link-invite')
        && str_contains($invites, 'async function createLinkDraft(context, button)')
        && str_contains($invites, 'showPreparedLink(draftInvite, context);'),
    'Readiness must stay read-only while the invite coordinator exclusively owns Share.'
);

$assertTrue(
    substr_count($invites, 'postJson(OPPONENTS_URL') === 1
        && str_contains($invites, "cache:'no-store'")
        && str_contains($invites, 'data-player-picker-state="loading"')
        && str_contains($invites, 'data-player-picker-state="empty"'),
    'The player picker must own one current authoritative request and explicit states.'
);""",
    'hot-path readiness/opponent contracts',
)
hot = replace_once(hot, "str_contains($main, 'v96-mvp14-root-cause-stabilization')\n        && str_contains($main, 'first-interaction-readiness-v103.js?v=103')", "str_contains($main, 'd1-canonical-owners')\n        && str_contains($main, 'first-interaction-readiness.js?v=d1')", 'hot-path main identity')
hot_path.write_text(hot)


Path('e2e/staging/frontend-immutable-core.spec.mjs').write_text(r'''import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/?mgw_e2e_frontend=d1-canonical`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const TEST_COOKIE = 'mgw_staging_test_session';
const EXPECTED_BUILD = 'd1-canonical-owners';

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, { headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' } });
  if (!response.ok) throw new Error(`OIDC token request failed: ${response.status}`);
  const payload = await response.json();
  if (typeof payload?.value !== 'string') throw new Error('OIDC token is unavailable.');
  return payload.value;
}

async function authorizeContext(context) {
  const token = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers:{ Authorization:`Bearer ${token}`, Accept:'application/json', 'Content-Type':'application/json' },
    data:{ action:'issue', slot:'A' }, timeout:35_000,
  });
  expect(response.status()).toBe(200);
  expect(await response.json()).toMatchObject({ ok:true, action:'issued', player_slot:'A' });
  expect((await context.cookies(STAGING_ORIGIN)).some(item => item.name === TEST_COOKIE)).toBe(true);
}

function requestAction(response) {
  try { return String(response.request().postDataJSON()?.action || ''); }
  catch { return ''; }
}

test('staging app serves one canonical notification and player-picker graph', async ({ browser }) => {
  const context = await browser.newContext({
    locale:'ru-RU', timezoneId:'Europe/Vilnius', viewport:{ width:390, height:844 },
    deviceScaleFactor:1, isMobile:true, hasTouch:true,
  });
  try {
    await authorizeContext(context);
    const page = await context.newPage();
    const pageErrors = [];
    const failedRequests = [];
    page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
    page.on('requestfailed', request => {
      if (request.url().startsWith(STAGING_ORIGIN)) failedRequests.push(`${request.method()} ${request.url()} ${request.failure()?.errorText || ''}`);
    });
    const bootstrap = page.waitForResponse(response => response.url() === API_ROUTE
      && response.request().method() === 'POST' && requestAction(response) === 'bootstrap', { timeout:35_000 });
    const response = await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded' });
    expect(response?.ok()).toBe(true);
    expect(response.headers()['x-mgw-frontend-build']).toBe(EXPECTED_BUILD);
    await expect(page.locator('#app')).toHaveAttribute('data-hotfix-build', EXPECTED_BUILD);
    expect((await bootstrap).status()).toBe(200);
    await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });

    const resources = await page.evaluate(() => performance.getEntriesByType('resource').map(entry => entry.name));
    const has = suffix => resources.some(url => new URL(url).pathname.concat(new URL(url).search).endsWith(suffix));
    for (const required of [
      '/assets/js/main.js?v=d1',
      '/assets/js/api/client.js?v=114',
      '/assets/js/session.js?v=114',
      '/assets/js/first-interaction-readiness.js?v=d1',
      '/assets/js/screens/notifications-screen-v99.js?v=d1',
      '/assets/js/games/game-invites.js?v=d1',
      '/assets/js/games/invite-link-entry-v115.js?v=d1',
      '/assets/js/presence-v115.js?v=115',
      '/assets/js/games/invite-terminal-actions-v115.js?v=115',
    ]) expect(has(required), `Canonical graph must include ${required}`).toBe(true);

    for (const retired of [
      '/assets/js/first-interaction-readiness-v103.js',
      '/assets/js/screens/notifications-passive-v130.js',
      '/assets/js/notification-deeplink-toast-policy-v131.js',
      '/assets/js/screens/notification-window-owner-v121.js',
      '/assets/js/notification-compat-click-guard-v127.js',
      '/assets/js/opponents-native-fetch-v115.js',
      '/assets/js/opponents-empty-cache-guard-v115.js',
      '/assets/js/opponents-authoritative-confirm-v122.js',
      '/assets/js/opponents-fresh-user-action-v128.js',
    ]) expect(resources.some(url => new URL(url).pathname.endsWith(retired)), `Canonical graph must exclude ${retired}`).toBe(false);

    expect(pageErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  } finally {
    try { await context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
    await context.close();
  }
});
''')


Path('e2e/staging/d1-bug-b-player-picker-v122.spec.mjs').write_text(r'''import { test, expect } from '@playwright/test';

const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
const APP_ROUTE = `${STAGING_ORIGIN}/app/`;
const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
const OPPONENTS_ROUTE = `${STAGING_ORIGIN}/bot/invite-opponents.php`;
const TEST_COOKIE = 'mgw_staging_test_session';

async function requestOidcToken() {
  const requestUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const requestToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!requestUrl || !requestToken) throw new Error('GitHub Actions OIDC environment is unavailable.');
  const url = new URL(requestUrl);
  url.searchParams.set('audience', OIDC_AUDIENCE);
  const response = await fetch(url, { headers:{ Authorization:`bearer ${requestToken}`, Accept:'application/json' } });
  if (!response.ok) throw new Error(`OIDC request failed: ${response.status}`);
  return (await response.json()).value;
}

async function authorizeContext(context, slot) {
  const token = await requestOidcToken();
  const response = await context.request.post(AUTH_ROUTE, {
    headers:{ Authorization:`Bearer ${token}`, Accept:'application/json', 'Content-Type':'application/json' },
    data:{ action:'issue', slot }, timeout:35_000,
  });
  expect(response.status()).toBe(200);
  expect((await context.cookies(STAGING_ORIGIN)).some(item => item.name === TEST_COOKIE)).toBe(true);
}

function requestAction(response) {
  try { return String(response.request().postDataJSON()?.action || ''); }
  catch { return ''; }
}

async function openPlayer(browser, slot, isMobile) {
  const context = await browser.newContext({
    locale:'ru-RU', timezoneId:'Europe/Vilnius',
    viewport:isMobile ? { width:390, height:844 } : { width:1280, height:900 },
    deviceScaleFactor:1, isMobile, hasTouch:isMobile,
  });
  await authorizeContext(context, slot);
  const page = await context.newPage();
  const bootstrap = page.waitForResponse(response => response.url() === API_ROUTE
    && response.request().method() === 'POST' && requestAction(response) === 'bootstrap', { timeout:35_000 });
  const response = await page.goto(APP_ROUTE, { waitUntil:'domcontentloaded' });
  expect(response?.ok()).toBe(true);
  expect((await bootstrap).status()).toBe(200);
  await expect(page.locator('#preloader')).toBeHidden({ timeout:20_000 });
  return { context, page };
}

async function runCanonicalPicker(browser, isMobile) {
  const player = await openPlayer(browser, 'A', isMobile);
  let requests = 0;
  let seenHeaders = null;
  try {
    await player.page.route(OPPONENTS_ROUTE, async route => {
      requests += 1;
      seenHeaders = route.request().headers();
      await new Promise(resolve => setTimeout(resolve, 350));
      await route.fulfill({
        status:200,
        contentType:'application/json; charset=utf-8',
        body:JSON.stringify({
          ok:true, authoritative:true, storage_driver:'database',
          items:[{ id:'stg_test_player_b', name:'TEST PLAYER B', activity:'онлайн', online:true, busy:false }],
        }),
      });
    });

    await player.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(player.page.locator('[data-open-player-picker]')).toBeVisible();
    await player.page.locator('[data-open-player-picker]').click();

    await expect(player.page.locator('[data-player-picker-state="loading"]')).toBeVisible();
    await expect(player.page.locator('[data-player-picker-state="empty"]')).toHaveCount(0);
    await expect(player.page.locator('[data-direct-opponent="stg_test_player_b"]')).toBeVisible({ timeout:5_000 });
    await expect(player.page.locator('[data-player-picker-state="loaded"]')).toBeVisible();
    expect(requests).toBe(1);
    expect(seenHeaders?.['cache-control']).toContain('no-store');
    expect(seenHeaders?.['x-mgw-opponents-source']).toBe('manual-player-picker');
  } finally {
    try { await player.context.request.post(AUTH_ROUTE, { data:{ action:'revoke' }, timeout:15_000 }); } catch {}
    await player.context.close();
  }
}

test('canonical desktop picker uses one fresh request and never paints empty while loading', async ({ browser }) => {
  await runCanonicalPicker(browser, false);
});

test('canonical mobile Chromium picker uses one fresh request and never paints empty while loading', async ({ browser }) => {
  await runCanonicalPicker(browser, true);
});
''')


# Use a semantic cache-bust, not another numbered hotfix revision.
main_path = Path('app/assets/js/main.js')
main = main_path.read_text().replace('?v=132', '?v=d1')
main_path.write_text(main)
entry_path = Path('app/v114.php')
entry = entry_path.read_text().replace('?v=132', '?v=d1')
entry_path.write_text(entry)
