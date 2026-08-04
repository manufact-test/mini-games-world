from pathlib import Path
import re


def require_replace(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f'Missing {label}: {old[:100]!r}')
    return text.replace(old, new, 1)


readiness_path = Path('app/assets/js/first-interaction-readiness.js')
readiness = readiness_path.read_text()
for old, new, label in [
    ("const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;\n", "", 'opponents URL'),
    ("const OPPONENT_REFRESH_GAP_MS = 3000;\n", "", 'opponent refresh gap'),
    ("let networkFetch = null;\n", "", 'network fetch owner'),
    ("let opponentsCache = null;\n", "", 'opponents cache'),
    ("let opponentsRefreshPromise = null;\n", "", 'opponents refresh promise'),
    ("let lastOpponentsRefreshAt = 0;\n", "", 'last opponents refresh timestamp'),
    ("  installOpponentResponseCache();\n", "", 'opponent response cache install'),
    ("    refreshOpponentsNetwork(true);\n", "", 'game-dismissed opponent refresh'),
    ("    refreshOpponentsNetwork(false);\n", "", 'visibility opponent refresh'),
    ("    refreshOpponentsNetwork(true),\n", "", 'readiness opponent task'),
    ("    opponentsReady:results[4].status === 'fulfilled',", "    opponentsReady:false,", 'opponents readiness result'),
    (
        "  if (target.matches('[data-invite-friend]')) {\n    refreshOpponentsNetwork(false);\n    queueMicrotask(() => scheduleCurrentDraftWarm(0));\n    return;\n  }",
        "  if (target.matches('[data-invite-friend]')) {\n    queueMicrotask(() => scheduleCurrentDraftWarm(0));\n    return;\n  }",
        'invite warm click',
    ),
    ("\n  if (target.matches('[data-open-player-picker]')) {\n    refreshOpponentsNetwork(false);\n    return;\n  }\n", "\n", 'player-picker prefetch click'),
    ("  const response = await networkFetch(url, {", "  const response = await fetch(url, {", 'requestUrl native fetch'),
]:
    readiness = require_replace(readiness, old, new, label)

readiness, count = re.subn(
    r"\nfunction installOpponentResponseCache\(\)\{.*?\n\}\n\nfunction handleEarlyClick",
    "\nfunction handleEarlyClick",
    readiness,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to remove opponent fetch cache owner')

readiness, count = re.subn(
    r"\nfunction refreshOpponentsNetwork\(force = false\)\{.*?\n\}\n\nfunction renderBalanceHistorySheet",
    "\nfunction renderBalanceHistorySheet",
    readiness,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to remove opponent refresh/cache functions')

readiness, count = re.subn(
    r"\nfunction jsonResponse\(data\)\{.*?\n\}\n",
    "\n",
    readiness,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to remove cached JSON response factory')

for token in ['window.fetch =', 'opponentsCache', 'refreshOpponentsNetwork', 'OPPONENTS_URL', 'networkFetch']:
    if token in readiness:
        raise SystemExit(f'Readiness still owns opponents transport: {token}')
readiness_path.write_text(readiness)


invites_path = Path('app/assets/js/games/game-invites.js')
invites = invites_path.read_text()
invites = require_replace(
    invites,
    "let lastFinishedGame = null;",
    "let lastFinishedGame = null;\nlet playerPickerGeneration = 0;\nlet playerPickerController = null;",
    'player picker state anchor',
)

lifecycle_anchor = "  document.addEventListener('mgw:game-dismissed', () => {\n    window.setTimeout(() => syncNow({ announce:true }), 80);\n  });"
invites = require_replace(
    invites,
    lifecycle_anchor,
    lifecycle_anchor + "\n  document.addEventListener('mgw:sheet-closed', invalidatePlayerPickerRequest);",
    'player picker close lifecycle',
)

replacement = r'''async function openPlayerPicker(context){
  const generation = ++playerPickerGeneration;
  playerPickerController?.abort();
  playerPickerController = new AbortController();

  openSheet(`
    <span data-player-picker data-player-picker-generation="${generation}" hidden></span>
    <div class="sheet-head">
      <div><h2>Выберите игрока</h2><p>${escapeHtml(gameTitle(context.gameType))} · ${escapeHtml(roomLabel(context.room))}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div data-player-picker-body></div>
    <button class="btn ghost full" data-back-to-invite-setup type="button">Назад к условиям</button>
  `);

  document.querySelector('[data-back-to-invite-setup]')?.addEventListener('click', () => {
    invalidatePlayerPickerRequest();
    openInviteSetup(context.gameType, context);
  });
  renderPlayerPickerState('loading', [], context);

  try {
    const result = await postJson(OPPONENTS_URL, {}, {
      cache:'no-store',
      signal:playerPickerController.signal,
      headers:{
        'Cache-Control':'no-cache, no-store, max-age=0',
        'Pragma':'no-cache',
        'X-MGW-Opponents-Source':'manual-player-picker',
      },
    });
    if (!isCurrentPlayerPicker(generation)) return;
    if (result?.authoritative !== true) throw new Error('Список игроков не подтверждён сервером.');

    const items = Array.isArray(result.items) ? result.items.slice(0, MAX_OPPONENTS) : [];
    items.sort((a, b) => Number(Boolean(b.online)) - Number(Boolean(a.online)));
    renderPlayerPickerState(items.length ? 'loaded' : 'empty', items, context);
  } catch (error) {
    if (error?.name === 'AbortError' || !isCurrentPlayerPicker(generation)) return;
    renderPlayerPickerState('error', [], context, error);
  } finally {
    if (generation === playerPickerGeneration) playerPickerController = null;
  }
}

function renderPlayerPickerState(status, items, context, error = null){
  const body = document.querySelector('#sheet [data-player-picker-body]');
  if (!(body instanceof HTMLElement)) return;

  if (status === 'loading') {
    body.innerHTML = `<div class="notifications-loading" data-player-picker-state="loading"><div>👥</div><strong>Загружаем соперников…</strong></div>`;
    return;
  }

  if (status === 'error') {
    body.innerHTML = `<div class="notifications-empty error" data-player-picker-state="error"><div>⚠️</div><strong>Не удалось загрузить игроков</strong><span>${escapeHtml(error?.message || 'Попробуйте ещё раз.')}</span></div>`;
    return;
  }

  if (status === 'empty') {
    body.innerHTML = `<div class="notifications-empty invite-empty-state" data-player-picker-state="empty"><div>👥</div><strong>Недавних соперников пока нет</strong><span>Вернитесь назад и отправьте ссылку.</span></div>`;
    return;
  }

  body.innerHTML = `<div class="invite-player-list" data-player-picker-state="loaded">${items.map(playerCard).join('')}</div>`;
  body.querySelectorAll('[data-direct-opponent]').forEach(button => button.addEventListener('click', () => {
    createDirectInvite(context, String(button.dataset.directOpponent || ''), button);
  }));
}

function invalidatePlayerPickerRequest(){
  playerPickerGeneration += 1;
  playerPickerController?.abort();
  playerPickerController = null;
}

function isCurrentPlayerPicker(generation){
  const marker = document.querySelector('#sheet [data-player-picker]');
  return document.getElementById('sheetOverlay')?.classList.contains('active')
    && Number(marker?.dataset.playerPickerGeneration || 0) === generation
    && generation === playerPickerGeneration;
}

function playerCard'''

invites, count = re.subn(
    r"async function openPlayerPicker\(context\)\{.*?\n\}\n\nfunction renderPlayerPicker\(items, context\)\{.*?\n\}\n\nfunction playerCard",
    replacement,
    invites,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace player picker owner')

old_post = """async function postJson(url, payload){
  const response = await fetch(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      ...payload,
    }),
  });"""
new_post = """async function postJson(url, payload, options = {}){
  const headers = {
    'Content-Type':'application/json',
    ...(options.headers || {}),
  };
  const response = await fetch(url, {
    method:'POST',
    headers,
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      ...payload,
    }),
    cache:options.cache || 'default',
    signal:options.signal || undefined,
  });"""
invites = require_replace(invites, old_post, new_post, 'canonical postJson options')

for token in ['data-player-picker-state="loading"', "cache:'no-store'", 'invalidatePlayerPickerRequest']:
    if token not in invites:
        raise SystemExit(f'Missing canonical player picker token: {token}')
if 'window.fetch =' in invites:
    raise SystemExit('Game invites unexpectedly replaces fetch')
invites_path.write_text(invites)
