import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const SEARCH_SPEED_URL = `${window.location.origin}/bot/search-speed.php`;
const SPEED_CHECK_MS = 2200;
const RETRY_CHECK_MS = 900;
const MAX_CHECKS = 3;
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
  scheduleAttempt(generation, 0, SPEED_CHECK_MS);
}

function scheduleAttempt(generation, attempt, delay){
  window.clearTimeout(runtime.timer);
  runtime.timer = window.setTimeout(() => {
    runtime.timer = null;
    void accelerateIfStillSearching(generation, attempt);
  }, delay);
}

function stopCheckpoint(){
  runtime.generation++;
  window.clearTimeout(runtime.timer);
  runtime.timer = null;
}

async function accelerateIfStillSearching(generation, attempt){
  if (generation !== runtime.generation || !stillSearching()) return;

  let accelerated = false;
  try {
    const response = await fetch(SEARCH_SPEED_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId() }),
      priority:'high',
    });
    const data = await response.json().catch(() => null);
    accelerated = Boolean(response.ok && data?.ok !== false && data?.accelerated);
  } catch (error) {
    // Existing matchmaking polling remains the fallback.
  }

  if (generation !== runtime.generation || accelerated || !stillSearching()) return;
  if (attempt + 1 >= MAX_CHECKS) return;
  scheduleAttempt(generation, attempt + 1, RETRY_CHECK_MS);
}

function stillSearching(){
  const activeScreen = document.querySelector('.screen.active');
  const searchRuntime = window.__MGW_V100_SEARCH_RUNTIME__;
  return String(activeScreen?.dataset.screen || '') === 'search' && Boolean(searchRuntime?.active);
}
