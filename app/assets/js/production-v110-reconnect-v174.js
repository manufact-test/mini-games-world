import { api } from './api/client.js?v=47';
import { state } from './state.js?v=27';
import { enterGame } from './screens/game-screen-v102-safe.js?v=102';
import { waitForV110InitialPresence } from './production-v110-presence.js?v=1121&b=f5a28b030c69';

const runtime = window.__MGW_V110_RECONNECT_V174__ ||= {
  initialized:false,
  bootstrapWrapped:false,
  resumeBusy:false,
  lastResumeGameId:'',
  lastResumeAt:0,
  lastError:'',
};

initV110ReconnectV174();

function initV110ReconnectV174(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  wrapBootstrapActiveGame();

  // The active v110 presence owner emits this only after a foreground/resume
  // ping succeeds. That makes a returning document perform one authoritative
  // game_state mutation/read instead of relying on the read-only game watcher.
  document.addEventListener('mgw:v110-presence-ready', () => {
    void resumeVisibleActiveGame();
  });
}

function wrapBootstrapActiveGame(){
  if (runtime.bootstrapWrapped) return;
  runtime.bootstrapWrapped = true;

  const baseBootstrap = api.bootstrap.bind(api);
  api.bootstrap = async (...args) => {
    const result = await baseBootstrap(...args);
    const gameId = String(result?.active_game?.id || '');
    if (!gameId) return result;

    // The TEST/Telegram route is v110. Presence starts before boot, so wait for
    // that owner before the first authoritative game_state. This closes the
    // old bootstrap-frozen-snapshot path without replacing the accepted v110
    // shell or any game engine.
    await waitForV110InitialPresence();

    const activeState = await authoritativeGameState(gameId, true);
    if (!activeState) return result;

    return {
      ...result,
      user:activeState.user || result.user,
      session:activeState.session || result.session,
      active_game:activeState.game || null,
      me:activeState.me || result.me || null,
    };
  };
}

async function resumeVisibleActiveGame(){
  if (runtime.resumeBusy || document.visibilityState !== 'visible') return false;

  const game = state.activeGame;
  const gameId = String(game?.id || '');
  if (!gameId || String(game?.status || '') !== 'active') return false;

  const screen = document.querySelector('.screen.active');
  if (String(screen?.dataset.screen || '') !== 'game') return false;

  // Collapse duplicate Telegram/pageshow/visibility resume signals into one
  // authoritative request. Normal in-game polling remains untouched.
  const now = Date.now();
  if (runtime.lastResumeGameId === gameId && now - runtime.lastResumeAt < 250) return false;

  runtime.resumeBusy = true;
  runtime.lastResumeGameId = gameId;
  runtime.lastResumeAt = now;
  try {
    const result = await authoritativeGameState(gameId, false);
    if (!result?.game || String(result.game.id || '') !== gameId) return false;

    if (result.session) state.session = result.session;
    enterGame(result.game, result.me || null);
    runtime.lastError = '';
    return true;
  } finally {
    runtime.resumeBusy = false;
  }
}

async function authoritativeGameState(gameId, allowRetry){
  try {
    return await api.gameState(gameId);
  } catch (error) {
    runtime.lastError = String(error?.message || error || 'game_state failed');
    if (!allowRetry) return null;

    // A newly opened Telegram document can race the final presence/session write
    // by a few milliseconds. Retry exactly once; never introduce a polling loop.
    await delay(180);
    try {
      const result = await api.gameState(gameId);
      runtime.lastError = '';
      return result;
    } catch (retryError) {
      runtime.lastError = String(retryError?.message || retryError || 'game_state retry failed');
      return null;
    }
  }
}

function delay(ms){
  return new Promise(resolve => window.setTimeout(resolve, ms));
}
