import { state } from './state.js?v=27';

const MIN_VISIBLE_MS = 480;
const GAME_READY_TIMEOUT_MS = 2200;
const LAUNCH_TIMEOUT_MS = 12000;

const GAME_TITLES = {
  tictactoe:'Крестики-нолики',
  four_in_a_row:'4 в ряд',
  battleship:'Морской бой',
  checkers:'Шашки',
  reversi:'Реверси',
  chess:'Шахматы',
  go:'Го',
  domino:'Домино',
};

const runtime = window.__MGW_V112_INSTANT_LAUNCH__ ||= {
  initialized:false,
  overlay:null,
  shownAt:0,
  generation:0,
  readyFrame:null,
  failureTimer:null,
  hardTimer:null,
  interactionTimer:null,
  sourceButton:null,
  screenObserver:null,
};

export function initV112InstantLaunchOverlay(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  installStyles();
  ensureOverlay();

  window.addEventListener('pointerdown', handleLaunchIntent, true);
  window.addEventListener('click', handleKeyboardLaunch, true);
  document.addEventListener('mgw:v99-game-found', event => {
    showOverlay(gameTitle(event.detail?.game?.game_type));
  }, true);

  const gameScreen = document.getElementById('screen-game');
  if (gameScreen) {
    runtime.screenObserver = new MutationObserver(() => {
      if (!gameScreen.classList.contains('active')) return;
      if (!isOverlayVisible()) showOverlay(gameTitle(state.activeGame?.game_type));
      waitForReadyGame();
    });
    runtime.screenObserver.observe(gameScreen, { attributes:true, attributeFilter:['class'] });
  }
}

function handleLaunchIntent(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('[data-invite-action="start"]');
  if (!(button instanceof HTMLButtonElement) || button.disabled) return;
  beginImmediateLaunch(button);
}

function handleKeyboardLaunch(event){
  if (Number(event.detail || 0) !== 0) return;
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('[data-invite-action="start"]');
  if (!(button instanceof HTMLButtonElement) || button.disabled) return;
  beginImmediateLaunch(button);
}

function beginImmediateLaunch(button){
  runtime.sourceButton = button;
  showOverlay(readInviteGameTitle());
  watchLaunchResult();
}

function showOverlay(title){
  const overlay = ensureOverlay();
  const game = overlay.querySelector('[data-v112-game]');
  if (game) game.textContent = title || 'Игра';

  runtime.generation++;
  runtime.shownAt = performance.now();
  overlay.dataset.interactive = 'false';
  overlay.hidden = false;
  overlay.setAttribute('aria-hidden', 'false');
  document.documentElement.classList.add('mgw-v112-launch-open');
  document.body.classList.add('mgw-v112-launch-open');

  // The layer paints on pointerdown, but it must not steal the pointerup/click
  // that belongs to the original Start button. It becomes blocking next task.
  window.clearTimeout(runtime.interactionTimer);
  runtime.interactionTimer = window.setTimeout(() => {
    if (!overlay.hidden) overlay.dataset.interactive = 'true';
  }, 0);

  window.clearTimeout(runtime.hardTimer);
  runtime.hardTimer = window.setTimeout(() => {
    if (!document.getElementById('screen-game')?.classList.contains('active')) hideOverlay();
  }, LAUNCH_TIMEOUT_MS);
}

function hideOverlay(){
  const overlay = runtime.overlay;
  if (!overlay || overlay.hidden) return;

  runtime.generation++;
  window.cancelAnimationFrame(runtime.readyFrame);
  window.clearInterval(runtime.failureTimer);
  window.clearTimeout(runtime.hardTimer);
  window.clearTimeout(runtime.interactionTimer);
  runtime.readyFrame = null;
  runtime.failureTimer = null;
  runtime.hardTimer = null;
  runtime.interactionTimer = null;
  runtime.sourceButton = null;

  overlay.dataset.interactive = 'false';
  overlay.hidden = true;
  overlay.setAttribute('aria-hidden', 'true');
  document.documentElement.classList.remove('mgw-v112-launch-open');
  document.body.classList.remove('mgw-v112-launch-open');
}

function watchLaunchResult(){
  window.clearInterval(runtime.failureTimer);
  runtime.failureTimer = window.setInterval(() => {
    const gameActive = document.getElementById('screen-game')?.classList.contains('active');
    if (gameActive) {
      window.clearInterval(runtime.failureTimer);
      runtime.failureTimer = null;
      waitForReadyGame();
      return;
    }

    const button = runtime.sourceButton;
    if (button?.isConnected && !button.disabled && /начать игру/i.test(String(button.textContent || ''))) {
      hideOverlay();
    }
  }, 80);
}

function waitForReadyGame(){
  const generation = runtime.generation;
  const startedAt = performance.now();

  const tick = now => {
    if (generation !== runtime.generation || !isOverlayVisible()) return;

    const screen = document.getElementById('screen-game');
    const board = document.getElementById('gameBoard');
    const players = document.getElementById('playersRow');
    const gameActive = Boolean(screen?.classList.contains('active'));
    const gameReady = gameActive
      && Boolean(state.activeGame?.id)
      && Boolean(board?.childElementCount)
      && Boolean(players?.childElementCount);
    const minimumElapsed = now - runtime.shownAt >= MIN_VISIBLE_MS;
    const readyTimeout = now - startedAt >= GAME_READY_TIMEOUT_MS;

    if (gameActive && minimumElapsed && (gameReady || readyTimeout)) {
      hideOverlay();
      return;
    }
    runtime.readyFrame = window.requestAnimationFrame(tick);
  };

  window.cancelAnimationFrame(runtime.readyFrame);
  runtime.readyFrame = window.requestAnimationFrame(tick);
}

function ensureOverlay(){
  if (runtime.overlay?.isConnected) return runtime.overlay;

  const overlay = document.createElement('div');
  overlay.id = 'mgwV112LaunchOverlay';
  overlay.className = 'mgw-v112-launch-overlay';
  overlay.dataset.interactive = 'false';
  overlay.hidden = true;
  overlay.setAttribute('aria-hidden', 'true');
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-live', 'polite');
  overlay.innerHTML = `
    <div class="mgw-v112-launch-card">
      <div class="mgw-v112-launch-game" data-v112-game>Игра</div>
      <div class="mgw-v112-launch-visual" aria-hidden="true">
        <span class="mgw-v112-player">●</span>
        <span class="mgw-v112-connection"><i></i><i></i><i></i></span>
        <span class="mgw-v112-player">●</span>
      </div>
      <h2>Готовим игру</h2>
      <p>Подключаем игроков…</p>
      <div class="mgw-v112-progress" aria-hidden="true"><span></span></div>
      <small>Матч начнётся через мгновение</small>
    </div>`;
  document.body.appendChild(overlay);
  runtime.overlay = overlay;
  return overlay;
}

function installStyles(){
  if (document.getElementById('mgwV112LaunchStyles')) return;
  const style = document.createElement('style');
  style.id = 'mgwV112LaunchStyles';
  style.textContent = `
    .mgw-v112-launch-open{overflow:hidden!important}
    .mgw-v112-launch-overlay{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 50% 38%,rgba(79,109,255,.18),transparent 38%),rgba(9,12,20,.98);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
    .mgw-v112-launch-overlay[data-interactive="false"]{pointer-events:none}
    .mgw-v112-launch-overlay[hidden]{display:none!important}
    .mgw-v112-launch-card{width:min(100%,380px);text-align:center;padding:30px 24px 26px;border:1px solid rgba(255,255,255,.1);border-radius:24px;background:linear-gradient(180deg,rgba(28,34,52,.96),rgba(15,19,31,.98));box-shadow:0 24px 70px rgba(0,0,0,.42)}
    .mgw-v112-launch-game{display:inline-flex;align-items:center;min-height:30px;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.07);color:#cbd4ff;font-size:13px;font-weight:700;letter-spacing:.02em}
    .mgw-v112-launch-visual{display:flex;align-items:center;justify-content:center;gap:13px;margin:24px 0 20px}
    .mgw-v112-player{display:grid;place-items:center;width:48px;height:48px;border-radius:16px;background:linear-gradient(145deg,#788cff,#4c5fd7);color:#fff;font-size:17px;box-shadow:0 10px 28px rgba(76,95,215,.32)}
    .mgw-v112-connection{display:flex;align-items:center;gap:6px}
    .mgw-v112-connection i{display:block;width:6px;height:6px;border-radius:50%;background:#95a5ff;animation:mgwV112Pulse 1.05s ease-in-out infinite}
    .mgw-v112-connection i:nth-child(2){animation-delay:.14s}.mgw-v112-connection i:nth-child(3){animation-delay:.28s}
    .mgw-v112-launch-card h2{margin:0;color:#fff;font-size:25px;line-height:1.16}
    .mgw-v112-launch-card p{margin:9px 0 0;color:#d8def7;font-size:15px;line-height:1.4}
    .mgw-v112-launch-card small{display:block;margin-top:13px;color:#8f98b5;font-size:12px;line-height:1.35}
    .mgw-v112-progress{position:relative;height:4px;margin-top:22px;overflow:hidden;border-radius:99px;background:rgba(255,255,255,.08)}
    .mgw-v112-progress span{position:absolute;inset:0 auto 0 -42%;width:42%;border-radius:inherit;background:linear-gradient(90deg,transparent,#8395ff,#b4c0ff,transparent);animation:mgwV112Progress 1.25s ease-in-out infinite}
    @keyframes mgwV112Pulse{0%,100%{opacity:.3;transform:scale(.82)}50%{opacity:1;transform:scale(1)}}
    @keyframes mgwV112Progress{0%{left:-42%}100%{left:100%}}
    @media (max-width:480px){.mgw-v112-launch-overlay{padding:18px}.mgw-v112-launch-card{padding:27px 20px 24px;border-radius:21px}.mgw-v112-launch-card h2{font-size:23px}}
    @media (prefers-reduced-motion:reduce){.mgw-v112-connection i,.mgw-v112-progress span{animation-duration:2.4s}}
  `;
  document.head.appendChild(style);
}

function isOverlayVisible(){
  return Boolean(runtime.overlay && !runtime.overlay.hidden);
}

function readInviteGameTitle(){
  const value = document.querySelector('#sheet .topup-success > div:first-child strong')?.textContent;
  return String(value || '').trim() || gameTitle(state.selectedGame);
}

function gameTitle(type){
  return GAME_TITLES[String(type || '')] || 'Игра';
}
