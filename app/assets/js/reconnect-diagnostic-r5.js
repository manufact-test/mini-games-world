import { state } from './state.js?v=27';

const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
const enabled = window.location.hostname === STAGING_HOST;

if (enabled) {
  installReconnectDiagnostic();
}

function installReconnectDiagnostic(){
  const nativeFetch = window.fetch.bind(window);
  const diag = window.__MGW_R174_DIAG__ ||= {
    build:'r17.4-diag-r5',
    calls:0,
    successes:0,
    failures:0,
    last:null,
    lastError:'',
    panel:null,
    timer:null,
  };

  window.fetch = async function reconnectDiagnosticFetch(input, init = {}){
    const meta = gameStateMeta(input, init);
    if (meta) diag.calls++;

    try {
      const response = await nativeFetch(input, init);
      if (meta) {
        void inspectGameStateResponse(response.clone(), diag);
      }
      return response;
    } catch (error) {
      if (meta) {
        diag.failures++;
        diag.lastError = shortError(error);
        diag.last = {
          receivedAt:Date.now(),
          requestGameId:meta.gameId,
          httpStatus:0,
          ok:false,
        };
        renderDiagnostic(diag);
      }
      throw error;
    }
  };

  diag.timer = window.setInterval(() => renderDiagnostic(diag), 250);
  renderDiagnostic(diag);
}

function gameStateMeta(input, init){
  const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (method !== 'POST') return null;

  let url;
  try {
    url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
  } catch (error) {
    return null;
  }
  if (!url.pathname.endsWith('/bot/api.php')) return null;

  const payload = parsePayload(init?.body);
  if (String(payload.action || '') !== 'game_state') return null;
  return { gameId:String(payload.gameId || '') };
}

async function inspectGameStateResponse(response, diag){
  try {
    const data = await response.json().catch(() => null);
    const game = data?.game || null;
    const serverNowMs = finite(game?.server_now_ms);
    const startMs = finite(game?.turn_starts_at_ms);
    const deadlineMs = finite(game?.turn_deadline_ms);

    diag.last = {
      receivedAt:Date.now(),
      httpStatus:Number(response.status || 0),
      ok:Boolean(response.ok && data && data.ok !== false),
      error:String(data?.error || ''),
      gameId:String(game?.id || ''),
      phase:String(game?.launch_phase || ''),
      timeLeft:finite(game?.time_left),
      serverNowMs,
      startMs,
      deadlineMs,
      startDeltaMs:startMs !== null && serverNowMs !== null ? startMs - serverNowMs : null,
      deadlineDeltaMs:deadlineMs !== null && serverNowMs !== null ? deadlineMs - serverNowMs : null,
      revision:finite(game?.turn_revision ?? game?.clock_revision),
      turn:String(game?.turn || ''),
      me:String(data?.me?.id || ''),
      sessionLocked:Boolean(data?.session?.locked),
    };

    if (diag.last.ok) {
      diag.successes++;
      diag.lastError = '';
    } else {
      diag.failures++;
      diag.lastError = diag.last.error || `HTTP ${diag.last.httpStatus}`;
    }
  } catch (error) {
    diag.failures++;
    diag.lastError = shortError(error);
  }
  renderDiagnostic(diag);
}

function renderDiagnostic(diag){
  const current = state.activeGame;
  const screen = document.querySelector('.screen.active');
  const onGame = String(screen?.dataset.screen || '') === 'game';

  if (!onGame && !current?.id && diag.calls === 0) {
    removePanel(diag);
    return;
  }

  const panel = ensurePanel(diag);
  const last = diag.last || {};
  const board = document.getElementById('gameBoard');
  const cells = board ? [...board.querySelectorAll('[data-game-cell]')] : [];
  const disabled = cells.filter(cell => cell.disabled).length;
  const phaseClock = window.__MGW_PHASE_B_CURRENT__?.clock || null;
  const perfNow = performance.now();

  const apiStart = formatDelta(last.startDeltaMs);
  const apiDeadline = formatDelta(last.deadlineDeltaMs);

  const stateServerNow = finite(current?.server_now_ms);
  const stateStart = finite(current?.turn_starts_at_ms);
  const stateDeadline = finite(current?.turn_deadline_ms);
  const stateStartDelta = stateStart !== null && stateServerNow !== null ? stateStart - stateServerNow : null;
  const stateDeadlineDelta = stateDeadline !== null && stateServerNow !== null ? stateDeadline - stateServerNow : null;

  const clockStartDelta = phaseClock && Number.isFinite(Number(phaseClock.start))
    ? Number(phaseClock.start) - perfNow
    : null;
  const clockDeadlineDelta = phaseClock && Number.isFinite(Number(phaseClock.deadline))
    ? Number(phaseClock.deadline) - perfNow
    : null;

  const boardWait = Boolean(board?.classList.contains('mgw-phase-b-turn-wait'));
  const pointer = board ? getComputedStyle(board).pointerEvents : '-';
  const timer = String(document.getElementById('timerText')?.textContent || '-').trim();
  const currentTurn = String(current?.turn || '');
  const currentMe = String(last.me || '');
  const myTurn = currentTurn !== '' && currentMe !== '' ? currentTurn === currentMe : null;

  panel.innerHTML = `
    <div><b>R17.4 DIAG r5</b> · API ${diag.calls}/${diag.successes}/${diag.failures} · build ${escapeHtml(String(window.__MGW_BUILD__ || '?'))}</div>
    <div>API: ${last.ok === true ? 'OK' : (last.ok === false ? 'ERR' : '—')} ${last.httpStatus || ''} · phase=${escapeHtml(last.phase || '-')} · t=${fmt(last.timeLeft)} · start=${apiStart} · dl=${apiDeadline} · lock=${last.sessionLocked ? '1' : '0'}</div>
    <div>STATE: phase=${escapeHtml(String(current?.launch_phase || '-'))} · t=${fmt(current?.time_left)} · start=${formatDelta(stateStartDelta)} · dl=${formatDelta(stateDeadlineDelta)} · myTurn=${myTurn === null ? '?' : (myTurn ? '1' : '0')}</div>
    <div>DOM: wait=${boardWait ? '1' : '0'} · disabled=${disabled}/${cells.length} · pointer=${escapeHtml(pointer)} · timer=${escapeHtml(timer)} · clockStart=${formatDelta(clockStartDelta)} · clockDl=${formatDelta(clockDeadlineDelta)}</div>
    ${diag.lastError ? `<div>ERR: ${escapeHtml(diag.lastError)}</div>` : ''}
  `;
}

function ensurePanel(diag){
  if (diag.panel?.isConnected) return diag.panel;
  let panel = document.getElementById('mgwReconnectDiagnosticR5');
  if (!panel) {
    panel = document.createElement('div');
    panel.id = 'mgwReconnectDiagnosticR5';
    Object.assign(panel.style, {
      position:'fixed',
      left:'8px',
      right:'8px',
      bottom:'34px',
      zIndex:'2147483647',
      maxWidth:'430px',
      margin:'0 auto',
      padding:'8px 10px',
      border:'1px solid rgba(255,255,255,.28)',
      borderRadius:'10px',
      background:'rgba(0,0,0,.88)',
      color:'#fff',
      font:'600 10px/1.35 monospace',
      whiteSpace:'normal',
      pointerEvents:'none',
      boxSizing:'border-box',
      boxShadow:'0 6px 24px rgba(0,0,0,.35)',
    });
    document.body.appendChild(panel);
  }
  diag.panel = panel;
  return panel;
}

function removePanel(diag){
  if (diag.panel?.isConnected) diag.panel.remove();
  diag.panel = null;
}

function parsePayload(body){
  if (typeof body !== 'string' || body === '') return {};
  try {
    const parsed = JSON.parse(body);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (error) {
    return {};
  }
}

function finite(value){
  if (value === null || value === undefined || value === '') return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function formatDelta(value){
  const number = finite(value);
  if (number === null) return '-';
  const sign = number >= 0 ? '+' : '';
  return `${sign}${(number / 1000).toFixed(1)}s`;
}

function fmt(value){
  const number = finite(value);
  return number === null ? '-' : String(Math.round(number));
}

function shortError(error){
  return String(error?.message || error || 'unknown').slice(0, 120);
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
