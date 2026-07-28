import { APP_CONFIG } from './config.js?v=38';

let initialized = false;

export function initV104GamePollTuning(){
  if (initialized) return;
  initialized = true;

  /* Speed-only: server rules and action ownership remain unchanged. */
  APP_CONFIG.gameIntervalMs = Math.min(Number(APP_CONFIG.gameIntervalMs || 800), 500);
}
