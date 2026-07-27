import { state } from '../state.js?v=27';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { showScreen } from '../router.js?v=27';
import { clearTimer, renderBalances } from '../ui.js?v=89';
import { APP_CONFIG } from '../config.js?v=38';
import { haptic } from '../telegram/telegram-app.js?v=27';
import {
  gameMetaText,
  gameStatusText,
  gameTypeOf,
  playerMarkText,
  renderGameSurface,
} from '../games/game-router.js?v=74';
import { gameSurfaceFingerprint } from '../production-v97-models.js?v=97';
import {
  initGameScreen as initLegacyGameScreen,
  startGamePolling as startLegacyGamePolling,
} from './game-screen.js?v=74';

const runtime = window.__MGW_V98_GAME_SCREEN_RUNTIME__ ||= {
  busy:false,
  viewerByGame:new Map(),
  finishedHandedOff:new Set(),
};

export function initGameScreen(){
  initLegacyGameScreen();
}

export function startGamePolling(gameId){
  const id = String(gameId || state.activeGame?.id || '');
  if (!id) return;

  state.timers.search = clearTimer(state.timers.search);
  state.timers.game = clearTimer(state.timers.game);

  const local = state.activeGame;
  if (String(local?.id || '') === id) {
    const viewer = runtime.viewerByGame.get(id) || resolveViewer(local);
    if (viewer) {
      runtime.viewerByGame.set(id, viewer);
      renderGame(local, viewer, true);
    }
  }

  state.timers.game = window.setInterval(() => refreshGame(id), APP_CONFIG.gameIntervalMs);
  refreshGame(id);
}

async function refreshGame(gameId){
  if (runtime.busy) return;
  runtime.busy = true;

  try {
    const result = await api.gameState(gameId);
    if (result?.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }

    if (!result?.game) {
      state.timers.game = clearTimer(state.timers.game);
      state.activeGame = null;
      if (document.getElementById('screen-game')?.classList.contains('active')) showScreen('home');
      return;
    }

    const game = result.game;
    const id = String(game.id || gameId);
    const viewer = normalizeViewer(result.me) || runtime.viewerByGame.get(id) || resolveViewer(game);
    if (!viewer) return;

    runtime.viewerByGame.set(id, viewer);
    state.activeGame = game;
    state.selectedGame = gameTypeOf(game);

    if (String(game.status || '') === 'finished') {
      state.timers.game = clearTimer(state.timers.game);
      if (!runtime.finishedHandedOff.has(id)) {
        runtime.finishedHandedOff.add(id);
        startLegacyGamePolling(id);
      }
      return;
    }

    renderGame(game, viewer, false);
  } catch (error) {
    const message = String(error?.message || '');
    if (message) toast(message);
  } finally {
    runtime.busy = false;
  }
}

function renderGame(game, me, forceSurface){
  const meta = document.getElementById('matchMeta');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const players = document.getElementById('playersRow');
  const surface = document.getElementById('gameBoard');
  const screen = document.getElementById('screen-game');
  if (!meta || !turn || !timer || !players || !surface || !me?.id) return;

  const gameType = gameTypeOf(game);
  if (screen) {
    screen.dataset.gameType = gameType;
    screen.dataset.gamePhase = String(game?.phase || '');
  }

  meta.textContent = gameMetaText(game);
  turn.textContent = gameStatusText(game, me);
  timer.textContent = game.status === 'active' ? `${game.time_left ?? 60} сек` : '—';

  const playersMarkup = (game.players || []).map(player => `
    <div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}">
      <div class="name">${escapeHtml(player.name)}</div>
      <div class="mark">${escapeHtml(playerMarkText(game, player))} · ${String(player.id) === String(me.id) ? 'вы' : 'соперник'}</div>
    </div>
  `).join('');
  if (players.innerHTML !== playersMarkup) players.innerHTML = playersMarkup;

  const fingerprint = gameSurfaceFingerprint(game, me.id);
  const renderedFingerprint = String(
    surface.dataset.mgwV98Fingerprint
    || surface.dataset.mgwV97Fingerprint
    || ''
  );
  const surfaceMissing = surface.childElementCount === 0;

  if (forceSurface || surfaceMissing || fingerprint !== renderedFingerprint) {
    renderGameSurface({
      game,
      me,
      container:surface,
      onAction:action => submitAction(game.id, action),
    });
    surface.dataset.mgwV98Fingerprint = fingerprint;
    surface.dataset.mgwV97Fingerprint = fingerprint;
  }
}

function submitAction(gameId, action){
  haptic('light');
  api.gameAction(gameId, action)
    .then(result => {
      if (result?.user) {
        state.user = result.user;
        state.session = result.session || state.session;
        renderBalances(state.user);
      }
      if (result?.game) {
        const viewer = normalizeViewer(result.me)
          || runtime.viewerByGame.get(String(result.game.id || gameId))
          || resolveViewer(result.game);
        if (viewer) {
          runtime.viewerByGame.set(String(result.game.id || gameId), viewer);
          state.activeGame = result.game;
          renderGame(result.game, viewer, false);
        }
      }
    })
    .catch(error => toast(error?.message || 'Не удалось выполнить действие.'));
}

function resolveViewer(game){
  const players = Array.isArray(game?.players) ? game.players : [];
  const explicit = players.find(player => player?.is_me === true || player?.viewer === true);
  if (explicit?.id !== undefined) return normalizeViewer(explicit);

  const candidates = [state.user?.id, state.user?.telegram_id, state.user?.mgw_id]
    .map(value => String(value || ''))
    .filter(Boolean);
  for (const candidate of candidates) {
    const match = players.find(player => String(player?.id || '') === candidate);
    if (match) return normalizeViewer(match);
  }

  const side = String(game?.viewer_side || '');
  const matches = side ? players.filter(player => String(player?.side || '') === side) : [];
  return matches.length === 1 ? normalizeViewer(matches[0]) : null;
}

function normalizeViewer(viewer){
  const id = String(viewer?.id || '');
  return id ? { ...viewer, id } : null;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
