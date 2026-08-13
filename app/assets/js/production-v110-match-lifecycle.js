import { state } from './state.js?v=27';
import { api } from './api/client.js?v=47';
import { closeSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { showScreen } from './router.js?v=27';
import { clearTimer, renderBalances } from './ui.js?v=89';
import { haptic } from './telegram/telegram-app.js?v=27';
import { clearV99PassiveLock } from './production-v99-session-transport.js?v=99';
import { enterGame, clearGameView } from './screens/game-screen-v102-safe.js?v=102';

const runtime = window.__MGW_V110_MATCH_LIFECYCLE__ ||= {
  initialized:false,
  leavePending:false,
  gameId:'',
  releaseBarrier:null,
};
runtime.releaseBarrier ||= null;

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
  void surrenderToHome(game);
}

function surrenderToHome(game){
  if (runtime.leavePending) return runtime.releaseBarrier;
  runtime.leavePending = true;
  runtime.gameId = String(game.id || '');

  abortCompetingReads();
  haptic('medium');

  const snapshot = clone(game);
  const viewer = resolveViewer(snapshot);

  state.timers.game = clearTimer(state.timers.game);
  state.activeGame = null;
  closeSheet();
  clearGameView();
  showScreen('home');

  const completion = (async () => {
    try {
      const result = await api.leaveGame(String(snapshot.id));
      rememberState(result);

      const authoritative = result?.game || snapshot;
      state.activeGame = null;
      clearV99PassiveLock();

      document.dispatchEvent(new CustomEvent('mgw:game-finished', {
        detail:{ game:authoritative, gameId:String(authoritative?.id || snapshot.id), source:'v110-surrender-home' },
      }));
      document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
      return { released:true, game:authoritative };
    } catch (error) {
      closeSheet();
      enterGame(snapshot, viewer);
      toast(error?.message || 'Не удалось завершить матч. Игра восстановлена.');
      return { released:false, game:snapshot };
    } finally {
      runtime.leavePending = false;
      runtime.gameId = '';
      if (runtime.releaseBarrier === completion) runtime.releaseBarrier = null;
    }
  })();

  runtime.releaseBarrier = completion;
  return completion;
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
      try { controller.abort('v110-surrender-home'); } catch (error) {}
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
