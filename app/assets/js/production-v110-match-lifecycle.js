import { state } from './state.js?v=27';
import { api } from './api/client.js?v=47';
import { APP_CONFIG } from './config.js?v=38';
import { openSheet, closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { clearTimer, renderBalances } from './ui.js?v=89';
import { haptic } from './telegram/telegram-app.js?v=27';
import { clearV99PassiveLock } from './production-v99-session-transport.js?v=99';
import { enterGame, clearGameView } from './screens/game-screen-v102-safe.js?v=102';
import { gameTypeOf } from './games/game-router-v102.js?v=102';

const runtime = window.__MGW_V110_MATCH_LIFECYCLE__ ||= {
  initialized:false,
  leavePending:false,
  gameId:'',
};

export function initV110MatchLifecycle(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  window.addEventListener('click', ownMatchLifecycleClick, true);
}

function ownMatchLifecycleClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;

  if (runtime.leavePending) {
    const blocked = origin.closest('#confirmLeaveGame, #newOpponent, #goHome, [data-create-rematch], [data-invite-action]');
    if (blocked) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
  }

  const confirm = origin.closest('#confirmLeaveGame');
  if (!(confirm instanceof HTMLButtonElement)) return;

  const game = state.activeGame;
  if (!game?.id || String(game.status || '') !== 'active') return;

  event.preventDefault();
  event.stopImmediatePropagation();
  void surrenderImmediately(game);
}

async function surrenderImmediately(game){
  if (runtime.leavePending) return;
  runtime.leavePending = true;
  runtime.gameId = String(game.id || '');

  abortCompetingReads();
  haptic('medium');

  const snapshot = clone(game);
  const viewer = resolveViewer(snapshot);
  const optimistic = buildOptimisticSurrender(snapshot, viewer?.id || state.user?.id || '');

  state.timers.game = clearTimer(state.timers.game);
  state.activeGame = optimistic;

  // The user leaves the playable surface immediately. The result is painted
  // before the network request starts, while every follow-up action remains
  // unavailable until the server has atomically finished and released session.
  renderPendingResult(optimistic, viewer);

  try {
    const result = await api.leaveGame(String(snapshot.id));
    rememberState(result);

    const authoritative = result?.game || optimistic;
    const confirmedViewer = normalizeViewer(result?.me) || viewer || resolveViewer(authoritative);
    state.activeGame = authoritative;
    clearV99PassiveLock();

    runtime.leavePending = false;
    runtime.gameId = '';

    document.dispatchEvent(new CustomEvent('mgw:game-finished', {
      detail:{ game:authoritative, gameId:String(authoritative?.id || snapshot.id), source:'v110-atomic-leave' },
    }));

    renderConfirmedResult(authoritative, confirmedViewer);
  } catch (error) {
    runtime.leavePending = false;
    runtime.gameId = '';
    closeSheet();
    enterGame(snapshot, viewer);
    toast(error?.message || 'Не удалось завершить матч. Игра восстановлена.');
  }
}

function renderPendingResult(game, viewer){
  const copy = surrenderCopy(game, viewer);
  openSheet(`
    <span data-v110-leave-pending data-result-game-id="${escapeHtml(game?.id || '')}" hidden></span>
    <div class="sheet-head">
      <div><h2>${escapeHtml(copy.title)}</h2><p>${escapeHtml(copy.text)}</p></div>
    </div>
    <div class="small-note">Завершаем матч и освобождаем игровую сессию…</div>
    <div class="stack">
      <button class="btn primary full" type="button" disabled aria-busy="true">Найти нового соперника</button>
      <button class="btn ghost full" type="button" disabled aria-busy="true">В меню</button>
    </div>
  `);
}

function renderConfirmedResult(game, viewer){
  const copy = surrenderCopy(game, viewer);
  openSheet(`
    <span data-result-game-id="${escapeHtml(game?.id || '')}" hidden></span>
    <div class="sheet-head">
      <div><h2>${escapeHtml(copy.title)}</h2><p>${escapeHtml(copy.text)}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="stack">
      <button class="btn primary full" id="newOpponent" type="button">Найти нового соперника</button>
      <button class="btn ghost full" id="goHome" type="button">В меню</button>
    </div>
  `);

  document.getElementById('newOpponent')?.addEventListener('click', () => {
    const detail = searchContextFromGame(game);
    closeSheet();
    state.activeGame = null;
    clearGameView();
    showScreen('home');
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
    document.dispatchEvent(new CustomEvent('mgw:v99-search-request', { detail }));
  });

  document.getElementById('goHome')?.addEventListener('click', () => {
    closeSheet();
    state.activeGame = null;
    clearGameView();
    showScreen('home');
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
  });
}

function buildOptimisticSurrender(game, viewerId){
  const next = clone(game);
  const players = Array.isArray(next?.players) ? next.players : [];
  const winner = players.find(player => String(player?.id || '') !== String(viewerId || ''));
  next.status = 'finished';
  next.winner_id = String(winner?.id || '');
  next.loser_id = String(viewerId || '');
  next.finish_reason = 'player_left';
  next.time_left = 0;
  return next;
}

function surrenderCopy(game, viewer){
  const winnerId = String(game?.winner_id || '');
  const viewerId = String(viewer?.id || state.user?.id || '');
  const isWin = winnerId !== '' && winnerId === viewerId;
  return {
    title:isWin ? 'Победа!' : 'Поражение',
    text:isWin
      ? `Соперник вышел из матча. Вы получили ${Number(game?.payout || 0)} коинов.`
      : 'Вы вышли из матча. Засчитано техническое поражение.',
  };
}

function searchContextFromGame(game){
  const type = gameTypeOf(game);
  return {
    gameType:type,
    room:String(game?.room || state.room || 'match') === 'gold' ? 'gold' : 'match',
    bet:Number(game?.bet || state.selectedBet || APP_CONFIG.matchBet),
    size:Number(game?.board_size || state.selectedBoardSize || 3),
  };
}

function rememberState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function abortCompetingReads(){
  const speed = window.__MGW_V101_SPEED__;
  for (const set of [speed?.gamePollControllers, speed?.backgroundControllers]) {
    if (!set || typeof set[Symbol.iterator] !== 'function') continue;
    for (const controller of [...set]) {
      try { controller.abort('v110-atomic-leave'); } catch (error) {}
    }
    set.clear?.();
  }
}

function resolveViewer(game){
  const players = Array.isArray(game?.players) ? game.players : [];
  const candidates = [state.user?.id, state.user?.mgw_id, state.user?.telegram_id]
    .map(value => String(value || ''))
    .filter(Boolean);
  for (const candidate of candidates) {
    const found = players.find(player => String(player?.id || '') === candidate);
    if (found) return normalizeViewer(found);
  }
  const explicit = players.find(player => player?.is_me === true || player?.viewer === true);
  return normalizeViewer(explicit);
}

function normalizeViewer(viewer){
  const id = String(viewer?.id || '');
  return id ? { ...viewer, id } : null;
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[character]));
}
