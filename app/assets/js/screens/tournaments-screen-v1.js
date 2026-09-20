import { api } from '../api/client.js?v=47';
import { currentScreen, onScreenEnter } from '../router.js?v=27';
import { t, formatNumber } from '@mgw/i18n';

const GAME_TYPES = Object.freeze([
  'tictactoe',
  'four_in_a_row',
  'battleship',
  'checkers',
  'reversi',
  'chess',
  'go',
  'domino',
]);
const DEFAULT_GAME = 'tictactoe';
const CACHE_TTL_MS = 45_000;
const cache = new Map();
const inFlight = new Map();
let activeGame = DEFAULT_GAME;
let initialized = false;
let dragState = null;
let suppressClickUntil = 0;

export function initTournamentsScreen(){
  if (initialized) return;
  const screen = document.getElementById('screen-tournaments');
  const content = screen?.querySelector('.content');
  if (!(screen instanceof HTMLElement) || !(content instanceof HTMLElement)) return;

  initialized = true;
  screen.dataset.mgwTournaments = 'leaderboards-v2';
  content.innerHTML = `
    <div class="tournaments-v2" id="tournamentsV2Root">
      <div class="page-head app-shell-page-head tournaments-v2-page-head">
        <div>
          <h1 class="page-title">${escapeHtml(t('shell.tournaments_title'))}</h1>
        </div>
      </div>

      <div class="tournaments-v2-mode-tabs" role="tablist" aria-label="${escapeHtml(t('shell.tournaments_title'))}">
        <button class="tournaments-v2-mode-tab active" type="button" role="tab" aria-selected="true" data-competition-mode="rating">${escapeHtml(t('shell.competition_rating'))}</button>
        <button class="tournaments-v2-mode-tab" type="button" role="tab" aria-selected="false" data-competition-mode="tournaments">${escapeHtml(t('shell.competition_tournaments'))}</button>
      </div>

      <div class="tournaments-v2-panel" data-competition-panel="rating">
      <section class="tournaments-v2-board" aria-labelledby="tournamentsLeaderboardTitle">
        <div class="tournaments-v2-board-head">
          <div>
            <h2 id="tournamentsLeaderboardTitle">${escapeHtml(t('profile.leaderboard_title'))}</h2>
            <p>${escapeHtml(t('profile.leaderboard_open_note'))}</p>
          </div>
        </div>

        <div class="tournaments-v2-tabs-shell">
          <button class="tournaments-v2-scroll tournaments-v2-scroll--left" type="button" data-tournaments-scroll="-1" aria-label="Прокрутить игры влево">‹</button>
          <div class="tournaments-v2-tabs" id="tournamentsLeaderboardTabs" aria-label="${escapeHtml(t('profile.leaderboard_title'))}">
            ${GAME_TYPES.map(type => tabMarkup(type, activeGame)).join('')}
          </div>
          <button class="tournaments-v2-scroll tournaments-v2-scroll--right" type="button" data-tournaments-scroll="1" aria-label="Прокрутить игры вправо">›</button>
        </div>

        <div class="tournaments-v2-body" id="tournamentsLeaderboardBody" aria-live="polite">
          ${loadingMarkup()}
        </div>
      </section>
      </div>

      <div class="tournaments-v2-panel" data-competition-panel="tournaments" hidden>
        <section class="tournaments-v2-board tournaments-v2-tournament-placeholder">
          <div class="tournaments-v2-board-head">
            <div>
              <h2>${escapeHtml(t('shell.competition_tournaments'))}</h2>
              <p>${escapeHtml(t('shell.competition_tournaments_note'))}</p>
            </div>
          </div>
        </section>
      </div>
    </div>
  `;

  bindModeTabs(screen);
  bindTabs(screen);
  bindScrollButtons(screen);
  onScreenEnter('tournaments', () => {
    void activateGame(activeGame);
  });

  document.addEventListener('mgw:app-ready', () => {
    window.setTimeout(() => { void warmLeaderboard(DEFAULT_GAME); }, 260);
  }, { once:true });

  if (currentScreen() === 'tournaments') void activateGame(activeGame);
  window.requestAnimationFrame(updateScrollAffordances);
}

function bindModeTabs(screen){
  screen.querySelectorAll('[data-competition-mode]').forEach(button => {
    button.addEventListener('click', () => {
      const mode = String(button.dataset.competitionMode || '');
      if (!['rating','tournaments'].includes(mode)) return;
      screen.querySelectorAll('[data-competition-mode]').forEach(candidate => {
        const active = String(candidate.dataset.competitionMode || '') === mode;
        candidate.classList.toggle('active', active);
        candidate.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      screen.querySelectorAll('[data-competition-panel]').forEach(panel => {
        panel.hidden = String(panel.dataset.competitionPanel || '') !== mode;
      });
      if (mode === 'rating') void activateGame(activeGame);
    });
  });
}

function bindTabs(screen){
  const tabs = screen.querySelector('#tournamentsLeaderboardTabs');
  if (!(tabs instanceof HTMLElement)) return;

  tabs.addEventListener('scroll', updateScrollAffordances, { passive:true });
  window.addEventListener('resize', updateScrollAffordances, { passive:true });

  tabs.addEventListener('wheel', event => {
    if (tabs.scrollWidth <= tabs.clientWidth + 2) return;
    const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
    if (!Number.isFinite(delta) || delta === 0) return;
    tabs.scrollLeft += delta;
    event.preventDefault();
  }, { passive:false });

  tabs.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0) return;
    dragState = {
      pointerId:event.pointerId,
      startX:event.clientX,
      startScrollLeft:tabs.scrollLeft,
      moved:false,
    };
  });

  tabs.addEventListener('pointermove', event => {
    if (!dragState || event.pointerId !== dragState.pointerId) return;
    const delta = event.clientX - dragState.startX;
    if (!dragState.moved && Math.abs(delta) < 6) return;
    if (!dragState.moved) {
      dragState.moved = true;
      try { tabs.setPointerCapture(event.pointerId); } catch (_) {}
      tabs.classList.add('is-dragging');
    }
    tabs.scrollLeft = dragState.startScrollLeft - delta;
    updateScrollAffordances();
  });

  const finishDrag = event => {
    if (!dragState || event.pointerId !== dragState.pointerId) return;
    if (dragState.moved) suppressClickUntil = performance.now() + 180;
    tabs.classList.remove('is-dragging');
    try {
      if (tabs.hasPointerCapture(event.pointerId)) tabs.releasePointerCapture(event.pointerId);
    } catch (_) {}
    dragState = null;
    updateScrollAffordances();
  };
  tabs.addEventListener('pointerup', finishDrag);
  tabs.addEventListener('pointercancel', finishDrag);

  tabs.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('[data-tournaments-game]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    if (performance.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    const nextGame = String(button.dataset.tournamentsGame || '').trim();
    if (!GAME_TYPES.includes(nextGame)) return;
    void activateGame(nextGame);
  });
}

function bindScrollButtons(screen){
  screen.querySelectorAll('[data-tournaments-scroll]').forEach(button => {
    button.addEventListener('click', () => {
      const tabs = document.getElementById('tournamentsLeaderboardTabs');
      if (!(tabs instanceof HTMLElement)) return;
      const direction = Number(button.dataset.tournamentsScroll || 0) < 0 ? -1 : 1;
      const amount = Math.max(180, Math.round(tabs.clientWidth * 0.72));
      tabs.scrollBy({ left:direction * amount, behavior:'smooth' });
    });
  });
}

async function activateGame(gameType){
  const next = GAME_TYPES.includes(gameType) ? gameType : DEFAULT_GAME;
  activeGame = next;
  syncActiveTab();

  const cached = currentCache(next);
  if (cached) {
    renderBoard(cached.board);
  } else {
    const body = document.getElementById('tournamentsLeaderboardBody');
    if (body) body.innerHTML = loadingMarkup();
  }

  try {
    const board = await warmLeaderboard(next);
    if (activeGame !== next || currentScreen() !== 'tournaments') return;
    renderBoard(board);
  } catch (error) {
    if (activeGame !== next || currentScreen() !== 'tournaments') return;
    const body = document.getElementById('tournamentsLeaderboardBody');
    if (body) body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || t('profile.leaderboard_error'))}</div>`;
  }
}

async function warmLeaderboard(gameType){
  const cached = currentCache(gameType);
  if (cached) return cached.board;
  if (inFlight.has(gameType)) return inFlight.get(gameType);

  const request = api.leaderboard(gameType)
    .then(result => {
      const board = result?.leaderboard && typeof result.leaderboard === 'object'
        ? result.leaderboard
        : {};
      cache.set(gameType, { board, loadedAt:Date.now() });
      return board;
    })
    .finally(() => {
      inFlight.delete(gameType);
    });

  inFlight.set(gameType, request);
  return request;
}

function currentCache(gameType){
  const item = cache.get(gameType);
  if (!item || Date.now() - Number(item.loadedAt || 0) > CACHE_TTL_MS) return null;
  return item;
}

function syncActiveTab(){
  document.querySelectorAll('#tournamentsLeaderboardTabs [data-tournaments-game]').forEach(button => {
    const active = String(button.dataset.tournamentsGame || '') === activeGame;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
    if (active) {
      button.scrollIntoView({ block:'nearest', inline:'nearest', behavior:'smooth' });
    }
  });
  window.requestAnimationFrame(updateScrollAffordances);
}

function updateScrollAffordances(){
  const tabs = document.getElementById('tournamentsLeaderboardTabs');
  const root = document.getElementById('tournamentsV2Root');
  if (!(tabs instanceof HTMLElement) || !(root instanceof HTMLElement)) return;

  const max = Math.max(0, tabs.scrollWidth - tabs.clientWidth);
  const left = tabs.scrollLeft > 3;
  const right = tabs.scrollLeft < max - 3;
  root.classList.toggle('can-scroll-left', left);
  root.classList.toggle('can-scroll-right', right);

  const leftButton = root.querySelector('[data-tournaments-scroll="-1"]');
  const rightButton = root.querySelector('[data-tournaments-scroll="1"]');
  if (leftButton instanceof HTMLButtonElement) leftButton.disabled = !left;
  if (rightButton instanceof HTMLButtonElement) rightButton.disabled = !right;
}

function renderBoard(board){
  const body = document.getElementById('tournamentsLeaderboardBody');
  if (!body) return;
  const data = board && typeof board === 'object' ? board : {};
  const entries = Array.isArray(data.entries) ? data.entries : [];
  const gameType = GAME_TYPES.includes(String(data.game_type || ''))
    ? String(data.game_type)
    : activeGame;

  body.innerHTML = `
    <div class="tournaments-v2-current-game">${escapeHtml(gameName(gameType))}</div>
    <div class="tournaments-v2-list">
      ${entries.length
        ? entries.map(leaderboardRow).join('')
        : `<div class="tournaments-v2-empty">${escapeHtml(t('profile.leaderboard_empty'))}</div>`}
    </div>
  `;
}

function leaderboardRow(entry){
  const rank = Math.max(1, Number(entry?.rank || 1));
  const nickname = String(entry?.nickname || t('profile.player')).trim() || t('profile.player');
  const avatar = String(entry?.avatar_item_id || 'starter-default-01').trim() || 'starter-default-01';
  const points = Math.max(0, Number(entry?.points || 0));
  const rankClass = rank <= 3 ? ` top-${rank}` : '';
  return `<div class="tournaments-v2-row${rankClass}">
    <b class="tournaments-v2-rank">${escapeHtml(formatNumber(rank))}</b>
    <span class="tournaments-v2-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">MG</span>
    <strong class="tournaments-v2-name">${escapeHtml(nickname)}</strong>
    <span class="tournaments-v2-points" aria-label="${escapeHtml(t('profile.rating_points'))}: ${escapeHtml(formatNumber(points))}">${escapeHtml(formatNumber(points))}</span>
  </div>`;
}

function tabMarkup(gameType, current){
  return `<button class="tournaments-v2-tab${gameType === current ? ' active' : ''}" type="button" role="tab" aria-selected="${gameType === current ? 'true' : 'false'}" data-tournaments-game="${escapeHtml(gameType)}">${escapeHtml(gameName(gameType))}</button>`;
}

function loadingMarkup(){
  return `<div class="tournaments-v2-empty">${escapeHtml(t('common.loading'))}</div>`;
}

function gameName(gameType){
  try { return t(`games.${gameType}.name`); } catch (_) { return gameType; }
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#39;');
}
