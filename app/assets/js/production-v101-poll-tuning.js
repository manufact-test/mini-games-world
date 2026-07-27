import { APP_CONFIG } from './config.js?v=38';

let initialized = false;

export function initV101PollTuning(){
  if (initialized) return;
  initialized = true;

  /*
   * Speed-only tuning. The reviewed v100 search/game owners stay unchanged;
   * only their shared polling cadence is shortened to reduce remote-player lag.
   */
  APP_CONFIG.searchIntervalMs = Math.min(Number(APP_CONFIG.searchIntervalMs || 2500), 900);
  APP_CONFIG.gameIntervalMs = Math.min(Number(APP_CONFIG.gameIntervalMs || 1500), 800);
}
