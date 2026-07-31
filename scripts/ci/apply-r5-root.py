from pathlib import Path
import re


def read(path):
    return Path(path).read_text(encoding="utf-8")


def write(path, text):
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    Path(path).write_text(text, encoding="utf-8")


def replace_once(path, old, new):
    text = read(path)
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected one occurrence, found {count}: {old!r}")
    write(path, text.replace(old, new, 1))


BUILD_OLD = "v110-mvp14r4-invite-notification-presence-root"
BUILD_NEW = "v110-mvp14r5-presence-invite-resume-root"

write("app/assets/js/stats-owner-v110.js", """import { state } from './state.js?v=27';
import { renderStats } from './screens/home-screen.js?v=74';

const runtime = window.__MGW_V110_STATS_OWNER__ ||= {
  issued:0,
  applied:0,
};

export function beginStatsRequest(){
  runtime.issued += 1;
  return runtime.issued;
}

export function applyStatsSnapshot(ticket, stats){
  const sequence = Number(ticket || 0);
  if (!stats || typeof stats !== 'object' || sequence <= 0) return false;
  if (sequence < runtime.applied) return false;

  runtime.applied = sequence;
  state.stats = { ...stats };
  renderStats(state.stats);
  return true;
}
""")

write("app/assets/js/production-v110-presence.js", """import { getInitData, getTelegram } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';
import { beginStatsRequest, applyStatsSnapshot } from './stats-owner-v110.js?v=1110';

const PRESENCE_URL = `${window.location.origin}/bot/presence.php`;
const HEARTBEAT_MS = 4000;
const STATUS_MS = 1200;
const RETRY_MS = 500;
const REQUEST_TIMEOUT_MS = 4500;

const runtime = window.__MGW_V110_PRESENCE__ ||= {
  initialized:false,
  started:false,
  appReady:false,
  heartbeatTimer:null,
  statusTimer:null,
  retryTimer:null,
  pingBusy:false,
  statusBusy:false,
  pingRequestId:0,
  statusRequestId:0,
  pingController:null,
  statusController:null,
  left:false,
};

export function initV110Presence(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('mgw:app-ready', () => {
    runtime.appReady = true;
    startPresence();
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') resumePresence(true);
    else cancelInFlightRequests();
  });

  window.addEventListener('pageshow', () => {
    if (document.visibilityState === 'visible') resumePresence(true);
  }, { capture:true });

  window.addEventListener('pagehide', event => {
    if (!event.persisted) sendLeaveBeacon();
  }, { capture:true });

  const telegram = getTelegram();
  if (typeof telegram?.onEvent === 'function') {
    try { telegram.onEvent('activated', () => resumePresence(true)); } catch (error) {}
  }
}

function startPresence(){
  if (!runtime.appReady) return;
  if (!runtime.started) {
    runtime.started = true;
    runtime.heartbeatTimer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void pingPresence();
    }, HEARTBEAT_MS);
    runtime.statusTimer = window.setInterval(() => {
      if (canReadHomeStatus()) void refreshStatus();
    }, STATUS_MS);
  }
  resumePresence(true);
}

function resumePresence(force = false){
  if (!runtime.appReady || document.visibilityState !== 'visible') return;
  runtime.left = false;
  window.clearTimeout(runtime.retryTimer);
  runtime.retryTimer = null;
  if (force) cancelInFlightRequests();
  void pingPresence();
}

function cancelInFlightRequests(){
  runtime.pingRequestId += 1;
  runtime.statusRequestId += 1;
  runtime.pingController?.abort();
  runtime.statusController?.abort();
  runtime.pingController = null;
  runtime.statusController = null;
  runtime.pingBusy = false;
  runtime.statusBusy = false;
}

async function pingPresence(){
  if (runtime.pingBusy || !runtime.appReady || document.visibilityState !== 'visible') return false;

  const requestId = ++runtime.pingRequestId;
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  const statsTicket = beginStatsRequest();
  runtime.pingController = controller;
  runtime.pingBusy = true;
  runtime.left = false;

  try {
    const data = await requestPresence('ping', controller.signal);
    if (requestId !== runtime.pingRequestId) return false;
    applyStatsSnapshot(statsTicket, data?.stats);
    return true;
  } catch (error) {
    if (requestId === runtime.pingRequestId) scheduleRetry();
    return false;
  } finally {
    window.clearTimeout(timeout);
    if (requestId === runtime.pingRequestId) {
      runtime.pingBusy = false;
      runtime.pingController = null;
    }
  }
}

async function refreshStatus(){
  if (runtime.statusBusy || !runtime.appReady || !canReadHomeStatus()) return false;

  const requestId = ++runtime.statusRequestId;
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  const statsTicket = beginStatsRequest();
  runtime.statusController = controller;
  runtime.statusBusy = true;

  try {
    const data = await requestPresence('status', controller.signal);
    if (requestId !== runtime.statusRequestId) return false;
    applyStatsSnapshot(statsTicket, data?.stats);
    return true;
  } catch (error) {
    return false;
  } finally {
    window.clearTimeout(timeout);
    if (requestId === runtime.statusRequestId) {
      runtime.statusBusy = false;
      runtime.statusController = null;
    }
  }
}

function scheduleRetry(){
  if (runtime.retryTimer || !runtime.appReady || document.visibilityState !== 'visible') return;
  runtime.retryTimer = window.setTimeout(() => {
    runtime.retryTimer = null;
    void pingPresence();
  }, RETRY_MS);
}

function canReadHomeStatus(){
  if (document.visibilityState !== 'visible') return false;
  const active = document.querySelector('.screen.active');
  return String(active?.dataset.screen || '') === 'home';
}

async function requestPresence(action, signal){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function'
    ? speed.rawFetch
    : window.fetch.bind(window);
  const response = await fetcher(PRESENCE_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify(payload(action)),
    keepalive:action === 'leave',
    priority:'high',
    cache:'no-store',
    signal,
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || 'Presence request failed');
  return data;
}

function sendLeaveBeacon(){
  if (runtime.left) return;
  runtime.left = true;
  cancelInFlightRequests();
  const body = JSON.stringify(payload('leave'));
  try {
    const blob = new Blob([body], { type:'application/json' });
    if (navigator.sendBeacon?.(PRESENCE_URL, blob)) return;
  } catch (error) {}

  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function'
    ? speed.rawFetch
    : window.fetch.bind(window);
  fetcher(PRESENCE_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body,
    keepalive:true,
    priority:'low',
    cache:'no-store',
  }).catch(() => null);
}

function payload(action){
  return {
    initData:getInitData(),
    sessionId:getSessionId(),
    action,
  };
}
""")

invite = "app/assets/js/games/game-invites-v110.js"
replace_once(invite,
"""  document.addEventListener('mgw:game-dismissed', () => {
    window.setTimeout(() => syncNow({ announce:true }), 80);
    scheduleWatch(80);
  });
""",
"""  document.addEventListener('mgw:game-dismissed', () => {
    window.setTimeout(() => syncNow({ announce:true }), 80);
    scheduleWatch(80);
  });
  document.addEventListener('mgw:before-game-launch', event => {
    if (!hasActionableInvite()) return;
    event.preventDefault();
    openCurrentInvite();
  }, true);
""")
replace_once(invite,
"""  if (launchTarget && currentInvite?.status === 'accepted' && isGameLaunchControl(launchTarget)) {
    event.preventDefault();
    event.stopImmediatePropagation();
    toast('Сначала запустите или отмените подтверждённое приглашение.');
    openCurrentInvite();
    return;
  }
""",
"""  if (launchTarget && hasActionableInvite() && isGameLaunchControl(launchTarget)) {
    event.preventDefault();
    event.stopImmediatePropagation();
    openCurrentInvite();
    return;
  }
""")
replace_once(invite,
"""function openInviteSetup(gameType, preserved = null){
  if (currentInvite?.status === 'accepted') return openCurrentInvite();
""",
"""function openInviteSetup(gameType, preserved = null){
  if (hasActionableInvite()) return openCurrentInvite();
""")
replace_once(invite,
"""function isGameLaunchControl(target){
  const id = String(target?.id || '');
  return id === 'startSearchBtn' || id.startsWith('play') || Boolean(target?.closest?.('[data-invite-friend]'));
}
""",
"""function hasActionableInvite(){
  return ['pending', 'accepted'].includes(String(currentInvite?.status || ''));
}

function isGameLaunchControl(target){
  const id = String(target?.id || '');
  return id === 'startSearchBtn' || id.startsWith('play') || Boolean(target?.closest?.('[data-invite-friend]'));
}
""")

replace_once("app/assets/js/screens/home-screen.js",
"""function openGameSetup(){
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
""",
"""function openGameSetup(){
  const inviteGuard = new CustomEvent('mgw:before-game-launch', { cancelable:true });
  if (!document.dispatchEvent(inviteGuard)) return;
  if (isSessionLocked(state.session)) return toast(sessionMessage(state.session));
""")

notification_old = read("app/assets/js/screens/notifications-screen-v110r4.js")
notification = notification_old.replace(
    "const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v4';",
    "const ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v5';", 1)
notification = notification.replace(
"""let announcementTimer = null;
let screenObserver = null;
""",
"""let announcementTimer = null;
let screenObserver = null;
let sheetGeneration = 0;
let openingSheetGeneration = 0;
""", 1)
notification = notification.replace(
"""  document.addEventListener('mgw:sheet-closed', () => scheduleAnnouncement(40));
""",
"""  document.addEventListener('mgw:sheet-closed', () => {
    sheetGeneration += 1;
    if (openingSheetGeneration !== sheetGeneration) openingSheetPromise = null;
    scheduleAnnouncement(40);
  });
""", 1)
notification = notification.replace("    mergeItems(items);\n    setUnreadCount", "    reconcileItems(items);\n    setUnreadCount", 1)
notification = notification.replace(
"""async function openNotificationsSheet(seedItems = [], hapticFeedback = true){
  mergeItems(seedItems);
  const immediate = currentItems();
""",
"""async function openNotificationsSheet(seedItems = [], hapticFeedback = true){
  const generation = ++sheetGeneration;
  mergeItems(seedItems);
  const immediate = currentItems();
""", 1)
notification = notification.replace(
"""  return refreshOpenSheet();
}

async function openToastNotification(){
""",
"""  return refreshOpenSheet(generation);
}

async function openToastNotification(){
""", 1)
notification = notification.replace(
"""  upsert(item);
  renderNotifications(mergeNotificationItems([item], currentItems()));
  haptic('light');
  dismissToast();
  void refreshOpenSheet();
}
""",
"""  upsert(item);
  dismissToast();
  void openNotificationsSheet(mergeNotificationItems([item], currentItems()), true);
}
""", 1)
start = notification.find("async function refreshOpenSheet(){")
end = notification.find("function renderLoading(){", start)
if start < 0 or end < 0:
    raise SystemExit("notifications: refreshOpenSheet block not found")
notification = notification[:start] + """async function refreshOpenSheet(generation = sheetGeneration){
  if (openingSheetPromise && openingSheetGeneration === generation) return openingSheetPromise;

  openingSheetGeneration = generation;
  const promise = (async () => {
    try {
      let result = await rawNotifications(false);
      let serverItems = Array.isArray(result?.items) ? result.items : [];
      reconcileItems(serverItems);
      rememberAnnouncedItems(serverItems);
      baselineLoaded = true;

      if (!isCurrentNotificationsSheet(generation)) {
        setUnreadCount(Number(result?.unread_count || 0));
        return;
      }

      let visible = currentItems();
      if (!visible.length && (Number(result?.unread_count || 0) > 0 || unreadHint > 0)) {
        renderLoading();
        await delay(EMPTY_RETRY_MS);
        result = await rawNotifications(false);
        serverItems = Array.isArray(result?.items) ? result.items : [];
        reconcileItems(serverItems);
        rememberAnnouncedItems(serverItems);
        visible = currentItems();
      }

      if (!isCurrentNotificationsSheet(generation)) {
        setUnreadCount(Number(result?.unread_count || 0));
        return;
      }

      renderNotifications(visible);
      setUnreadCount(0);
      void rawNotifications(true).catch(() => null);
    } catch (error) {
      if (isCurrentNotificationsSheet(generation) && !currentItems().length) renderError();
    }
  })();

  openingSheetPromise = promise;
  try {
    return await promise;
  } finally {
    if (openingSheetGeneration === generation) {
      openingSheetPromise = null;
      openingSheetGeneration = 0;
    }
  }
}

""" + notification[end:]
notification = notification.replace(
"""function renderLoading(){
  openSheet(`
    <div class="sheet-head">
""",
"""function renderLoading(){
  openSheet(`
    <span data-notifications-sheet hidden></span>
    <div class="sheet-head">
""", 1)
notification = notification.replace(
"""  openSheet(`
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
""",
"""  openSheet(`
    <span data-notifications-sheet hidden></span>
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
""", 1)
notification = notification.replace(
"""function renderError(){
  openSheet(`
    <div class="sheet-head">
""",
"""function renderError(){
  openSheet(`
    <span data-notifications-sheet hidden></span>
    <div class="sheet-head">
""", 1)
notification, count = re.subn(
    r"function isNotificationsSheetOpen\(\)\{\n.*?\n\}",
    """function isNotificationsSheetOpen(){
  return Boolean(
    document.getElementById('sheetOverlay')?.classList.contains('active')
      && document.querySelector('#sheet [data-notifications-sheet]')
  );
}

function isCurrentNotificationsSheet(generation){
  return generation === sheetGeneration && isNotificationsSheetOpen();
}""",
    notification, count=1, flags=re.S)
if count != 1:
    raise SystemExit(f"notifications: isNotificationsSheetOpen replacement count {count}")
notification = notification.replace(
"""function mergeItems(items){
  for (const item of Array.isArray(items) ? items : []) upsert(item);
}
""",
"""function mergeItems(items){
  for (const item of Array.isArray(items) ? items : []) upsert(item);
}

function reconcileItems(items){
  const authoritative = Array.isArray(items) ? items : [];
  liveItems = new Map(
    authoritative
      .slice(0, MAX_LIVE_ITEMS)
      .map(item => cloneItem(item))
      .filter(item => String(item?.id || '') !== '')
      .map(item => [String(item.id), item])
  );
}
""", 1)
write("app/assets/js/screens/notifications-screen-v110r5.js", notification)

shell = "app/assets/js/main-v110-handoff-shell.js"
text = read(shell).replace(BUILD_OLD, BUILD_NEW)
text = text.replace(
"import { renderRoomCard, initHomeScreen, setRoom, renderStats } from './screens/home-screen.js?v=74';",
"import { renderRoomCard, initHomeScreen, setRoom } from './screens/home-screen.js?v=74';")
text = text.replace(
"import { initNotificationsScreen } from './screens/notifications-screen-v110r4.js?v=1109';",
"import { initNotificationsScreen } from './screens/notifications-screen-v110r5.js?v=1110';")
text = text.replace(
"import { initGameInvites, openIncomingInviteIfPresent } from './games/game-invites-v110.js?v=1109';",
"import { initGameInvites, openIncomingInviteIfPresent } from './games/game-invites-v110.js?v=1110';")
text = text.replace(
"import { initV110Presence } from './production-v110-presence.js?v=1107';",
"import { initV110Presence } from './production-v110-presence.js?v=1110';\nimport { beginStatsRequest, applyStatsSnapshot } from './stats-owner-v110.js?v=1110';")
text = text.replace(
"""  try {
    setRoom(APP_CONFIG.defaultRoom);
    const result = await api.bootstrap();
    state.user = result.user;
    state.stats = result.stats;
""",
"""  try {
    setRoom(APP_CONFIG.defaultRoom);
    const statsTicket = beginStatsRequest();
    const result = await api.bootstrap();
    state.user = result.user;
""")
text = text.replace(
"""    renderUser(state.user);
    renderBalances(state.user);
    renderStats(state.stats);
""",
"""    renderUser(state.user);
    renderBalances(state.user);
    applyStatsSnapshot(statsTicket, result.stats);
""")
text = text.replace(
"""  statsRefreshing = true;
  try {
    const result = await api.stats();
    if (result?.stats) {
      state.stats = result.stats;
      renderStats(state.stats);
    }
""",
"""  statsRefreshing = true;
  const statsTicket = beginStatsRequest();
  try {
    const result = await api.stats();
    if (result?.stats) applyStatsSnapshot(statsTicket, result.stats);
""")
write(shell, text)

for path in ["app/assets/js/main-v110.js", "app/assets/js/production-clean-entry-v110.js"]:
    text = read(path).replace(BUILD_OLD, BUILD_NEW)
    text = text.replace("main-v110-handoff-shell.js?v=1109", "main-v110-handoff-shell.js?v=1110")
    write(path, text)

for path in ["app/v110.php", "bot/helpers/UserWelcomeGuard.php", "bot/helpers/WebAppLaunchUrl.php"]:
    text = read(path).replace("v=1109", "v=1110").replace(BUILD_OLD, BUILD_NEW)
    write(path, text)

auth = "bot/services/AuthService.php"
text = read(auth)
text = text.replace("require_once __DIR__ . '/PresenceService.php';\n\n", "")
text = text.replace(
"""    private function attachMgwIdentity(array $user, string $sessionId): array
    {
        $resolved = (new RuntimeAccountIdentityResolver($this->config))->attach($user, $sessionId);
        $this->touchAuthenticatedPresence($resolved, $sessionId);
        return $resolved;
    }

    private function touchAuthenticatedPresence(array $user, string $sessionId): void
    {
        $accountId = trim((string)($user['id'] ?? $user['telegram_id'] ?? ''));
        $sessionId = trim($sessionId);
        if ($accountId === '' || $sessionId === '') return;

        // Authentication is the first authoritative point shared by normal and
        // invitation launches. Presence must therefore not depend on an earlier
        // fire-and-forget request from Telegram WebView.
        try {
            (new PresenceService())->touch($accountId, $sessionId);
        } catch (Throwable $error) {
            // Presence is observable state, not permission to enter the app.
            error_log('Mini Games World authenticated presence failed: ' . $error->getMessage());
        }
    }
""",
"""    private function attachMgwIdentity(array $user, string $sessionId): array
    {
        return (new RuntimeAccountIdentityResolver($this->config))->attach($user, $sessionId);
    }
""")
write(auth, text)

presence_service = "bot/services/PresenceService.php"
text = read(presence_service)
text = text.replace(
"""    private const ONLINE_WINDOW_SEC = 75;
    private const MARKER_FILE = '.enabled';
""",
"""    private const ONLINE_WINDOW_SEC = 75;
    private const LEAVE_GRACE_SEC = 4;
    private const MARKER_FILE = '.enabled';
""")
text = text.replace(
"""        if (@file_put_contents($temporary, (string)time(), LOCK_EX) === false) {
""",
"""        $payload = json_encode([
            'touched_at' => time(),
            'leave_after' => 0,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || @file_put_contents($temporary, $payload, LOCK_EX) === false) {
""")
text = text.replace(
"""        @unlink($this->sessionPath($accountId, $sessionId));
        $this->pruneAccountDirectory($this->accountDirectory($accountId));
""",
"""        $path = $this->sessionPath($accountId, $sessionId);
        if (!is_file($path)) return;

        $state = $this->readSessionState($path);
        $payload = json_encode([
            'touched_at' => max(1, (int)($state['touched_at'] ?? time())),
            'leave_after' => time() + self::LEAVE_GRACE_SEC,
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($payload)) @file_put_contents($path, $payload, LOCK_EX);
        $this->pruneAccountDirectory($this->accountDirectory($accountId));
""")
text = text.replace(
"""        $cutoff = time() - self::ONLINE_WINDOW_SEC;
        foreach (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [] as $path) {
            $timestamp = (int)trim((string)@file_get_contents($path));
            if ($timestamp <= 0 || $timestamp < $cutoff) @unlink($path);
        }
""",
"""        $now = time();
        $cutoff = $now - self::ONLINE_WINDOW_SEC;
        foreach (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [] as $path) {
            $state = $this->readSessionState($path);
            $touchedAt = (int)($state['touched_at'] ?? 0);
            $leaveAfter = (int)($state['leave_after'] ?? 0);
            if ($touchedAt <= 0
                || $touchedAt < $cutoff
                || ($leaveAfter > 0 && $leaveAfter <= $now)) {
                @unlink($path);
            }
        }
""")
text = text.replace(
"""    private function directoryHasLiveSession(string $accountDirectory): bool
    {
        return (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: []) !== [];
    }
}
""",
"""    private function directoryHasLiveSession(string $accountDirectory): bool
    {
        $now = time();
        $cutoff = $now - self::ONLINE_WINDOW_SEC;
        foreach (glob($accountDirectory . DIRECTORY_SEPARATOR . 'session-*.presence') ?: [] as $path) {
            $state = $this->readSessionState($path);
            $touchedAt = (int)($state['touched_at'] ?? 0);
            $leaveAfter = (int)($state['leave_after'] ?? 0);
            if ($touchedAt >= $cutoff && ($leaveAfter <= 0 || $leaveAfter > $now)) return true;
        }
        return false;
    }

    private function readSessionState(string $path): array
    {
        $raw = trim((string)@file_get_contents($path));
        if ($raw === '') return ['touched_at' => 0, 'leave_after' => 0];

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return [
                'touched_at' => (int)($decoded['touched_at'] ?? 0),
                'leave_after' => (int)($decoded['leave_after'] ?? 0),
            ];
        }

        return ['touched_at' => (int)$raw, 'leave_after' => 0];
    }
}
""")
write(presence_service, text)

api = "bot/api.php"
text = read(api)
text = text.replace(
"""    $sessions = new SessionService($config);
    $statsService = new StatsService();
""",
"""    $sessions = new SessionService($config);
    $presenceService = new PresenceService();
    $statsService = new StatsService($presenceService);
""")
text = text.replace(
"""    $tgUser = $auth->getUserFromRequest($payload);

    $result = $db->transaction(function""",
"""    $tgUser = $auth->getUserFromRequest($payload);
    if ($action === 'bootstrap' && $sessionId !== '') {
        try {
            $presenceService->touch((string)($tgUser['id'] ?? ''), $sessionId);
        } catch (Throwable $presenceError) {
            error_log('Mini Games World bootstrap presence failed: ' . $presenceError->getMessage());
        }
    }

    $result = $db->transaction(function""")
write(api, text)

storage = "bot/services/invites/GameInviteStorageTrait.php"
text = read(storage)
start = text.find("    private function expireIfDue(")
end = text.find("    private function normalizeLegacy(", start)
if start < 0 or end < 0:
    raise SystemExit("GameInviteStorageTrait: expireIfDue block not found")
replacement = """    private function expireIfDue(array &$db, array &$invite, int $now): void
    {
        $status = (string)($invite['status'] ?? '');
        if (in_array($status, ['draft', 'pending'], true)) {
            $expiresAt = strtotime((string)($invite['expires_at'] ?? '')) ?: 0;
            if ($expiresAt > 0 && $expiresAt <= $now) {
                $invite['status'] = 'expired';
                $invite['updated_at'] = now_iso();
                if ($status === 'pending') {
                    $this->hideReceivedNotification(
                        $db,
                        (string)($invite['invitee_id'] ?? ''),
                        (string)($invite['token'] ?? '')
                    );
                }
            }
            return;
        }

        if ($status !== 'awaiting_start') return;
        $deadline = strtotime((string)($invite['ready_deadline_at'] ?? '')) ?: 0;
        if ($deadline <= 0 || $deadline > $now) return;

        $invite['status'] = 'timed_out';
        $invite['updated_at'] = now_iso();
        $this->hideReceivedNotification(
            $db,
            (string)($invite['invitee_id'] ?? ''),
            (string)($invite['token'] ?? '')
        );
    }

"""
write(storage, text[:start] + replacement + text[end:])

service = "bot/services/GameInviteService.php"
replace_once(service,
"""        $type = (string)($notification['type'] ?? '');
        if (!str_starts_with($type, 'invite_')) return true;
""",
"""        $type = (string)($notification['type'] ?? '');
        if (!str_starts_with($type, 'invite_')) return true;
        if (in_array($type, ['invite_expired', 'invite_timed_out'], true)) return false;
""")

notifications_php = "bot/notifications.php"
replace_once(notifications_php,
"""    $type = (string)($item['type'] ?? '');
    if (!str_starts_with($type, 'invite_')) return true;
    if (!is_array($invite)) return true;
""",
"""    $type = (string)($item['type'] ?? '');
    if (!str_starts_with($type, 'invite_')) return true;
    if (in_array($type, ['invite_expired', 'invite_timed_out'], true)) return false;
    if (!is_array($invite)) return true;
""")

for path in Path("bot/tests").glob("*.php"):
    text = path.read_text(encoding="utf-8")
    text = text.replace(BUILD_OLD, BUILD_NEW)
    text = text.replace("/app/v110.php?v=1109", "/app/v110.php?v=1110")
    text = text.replace("production-clean-entry-v110.js?v=1109", "production-clean-entry-v110.js?v=1110")
    text = text.replace("main-v110.js?v=1109", "main-v110.js?v=1110")
    text = text.replace("main-v110-handoff-shell.js?v=1109", "main-v110-handoff-shell.js?v=1110")
    text = text.replace("game-invites-v110.js?v=1109", "game-invites-v110.js?v=1110")
    text = text.replace("notifications-screen-v110r4.js?v=1109", "notifications-screen-v110r5.js?v=1110")
    text = text.replace("notifications-screen-v110r4.js", "notifications-screen-v110r5.js")
    text = text.replace("production-v110-presence.js?v=1107", "production-v110-presence.js?v=1110")
    path.write_text(text, encoding="utf-8")

write("bot/tests/ProductionV110PresenceInviteResumeRootContractTest.php", r"""<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R5 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$main = $read('app/assets/js/main-v110-handoff-shell.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$stats = $read('app/assets/js/stats-owner-v110.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$home = $read('app/assets/js/screens/home-screen.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r5.js');
$auth = $read('bot/services/AuthService.php');
$api = $read('bot/api.php');
$presenceService = $read('bot/services/PresenceService.php');
$inviteStorage = $read('bot/services/invites/GameInviteStorageTrait.php');
$notificationEndpoint = $read('bot/notifications.php');
$php = $read('app/v110.php');

$assert(str_contains($main, "stats-owner-v110.js?v=1110")
    && str_contains($main, 'beginStatsRequest()')
    && str_contains($main, 'applyStatsSnapshot(statsTicket, result.stats)')
    && !str_contains($main, 'state.stats = result.stats'),
    'All home statistics responses must be ordered by one canonical stats owner.');

$assert(str_contains($stats, 'sequence < runtime.applied')
    && str_contains($stats, 'state.stats = { ...stats }')
    && str_contains($stats, 'renderStats(state.stats)'),
    'A stale request must never overwrite a newer visible statistics snapshot.');

$assert(str_contains($presence, "document.addEventListener('mgw:app-ready'")
    && str_contains($presence, "window.addEventListener('pageshow'")
    && str_contains($presence, 'cancelInFlightRequests()')
    && str_contains($presence, 'REQUEST_TIMEOUT_MS = 4500')
    && str_contains($presence, 'new AbortController()'),
    'Mobile resume must cancel suspended requests and start a fresh bounded presence request.');

$assert(!str_contains($auth, 'touchAuthenticatedPresence')
    && str_contains($api, "$action === 'bootstrap'")
    && str_contains($api, '$presenceService->touch('),
    'Generic authentication must not resurrect a leaving session; bootstrap owns launch presence.');

$assert(str_contains($presenceService, 'LEAVE_GRACE_SEC = 4')
    && str_contains($presenceService, "'leave_after'")
    && str_contains($presenceService, 'readSessionState('),
    'Explicit leave must use a short renewable lease instead of an immediate delete race.');

$assert(str_contains($invites, 'function hasActionableInvite()')
    && str_contains($invites, "['pending', 'accepted']")
    && str_contains($invites, 'mgw:before-game-launch')
    && str_contains($home, "new CustomEvent('mgw:before-game-launch'"),
    'Any game launch during an actionable invitation must reopen the canonical invitation actions.');

$assert(str_contains($notifications, 'let sheetGeneration = 0;')
    && str_contains($notifications, 'isCurrentNotificationsSheet(generation)')
    && str_contains($notifications, 'reconcileItems(serverItems)')
    && str_contains($notifications, 'data-notifications-sheet'),
    'Late notification responses must update cache only and never reopen a closed sheet.');

$assert(!str_contains($inviteStorage, "'invite_expired'")
    && !str_contains($inviteStorage, "'invite_timed_out'")
    && str_contains($notificationEndpoint, "['invite_expired', 'invite_timed_out']")
    && str_contains($notificationEndpoint, 'return false;'),
    'Passive expiration and timeout must be silent cleanup and hidden from existing notification history.');

$assert(str_contains($php, 'production-clean-entry-v110.js?v=1110')
    && str_contains($php, 'main-v110.js?v=1110')
    && str_contains($php, 'v110-mvp14r5-presence-invite-resume-root'),
    'Only the canonical no-store v1110 entrypoint may activate R5.');

fwrite(STDOUT, "ProductionV110PresenceInviteResumeRootContractTest: {$assertions} assertions passed\n");
""")

if notification == notification_old:
    raise SystemExit("notifications: no changes produced")
