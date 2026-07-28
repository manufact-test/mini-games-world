import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const SEARCH_SPEED_URL = `${window.location.origin}/bot/search-speed.php`;
const SPEED_CHECK_MS = 2200;
const START_IDS = new Set([
  'startSearchBtn',
  'startFourSearchBtn',
  'startBattleshipSearchBtn',
  'startCheckersSearchBtn',
  'startReversiSearchBtn',
  'startChessSearchBtn',
  'startGoSearchBtn',
  'startDominoSearchBtn',
]);

const runtime = window.__MGW_V109_SEARCH_SPEED__ ||= {
  initialized:false,
  generation:0,
  timer:null,
};

export function initV109SearchSpeed(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  window.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('button, [role="button"]');
    if (!(button instanceof Element)) return;

    if (button.id === 'cancelSearch' || button.id === 'changeSearch') {
      stopCheckpoint();
      return;
    }
    if (!START_IDS.has(button.id)) return;
    scheduleCheckpoint();
  }, true);

  document.addEventListener('mgw:v99-game-found', stopCheckpoint);
  document.addEventListener('mgw:game-dismissed', stopCheckpoint);
}

function scheduleCheckpoint(){
  stopCheckpoint();
  const generation = ++runtime.generation;
  runtime.timer = window.setTimeout(() => {
    runtime.timer = null;
    void accelerateIfStillSearching(generation);
  }, SPEED_CHECK_MS);
}

function stopCheckpoint(){
  runtime.generation++;
  window.clearTimeout(runtime.timer);
  runtime.timer = null;
}

async function accelerateIfStillSearching(generation){
  if (generation !== runtime.generation) return;
  const activeScreen = document.querySelector('.screen.active');
  const searchRuntime = window.__MGW_V100_SEARCH_RUNTIME__;
  if (String(activeScreen?.dataset.screen || '') !== 'search' || !searchRuntime?.active) return;

  try {
    await fetch(SEARCH_SPEED_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId() }),
      priority:'high',
    });
  } catch (error) {
    // Existing matchmaking polling remains the fallback.
  }
}
