import { buildV102BattleshipSetupOptimistic } from './production-v102-battleship-models.js?v=102';

let initialized = false;

export function initV102BattleshipBridge(){
  if (initialized) return;
  initialized = true;
  window.__MGW_V102_BUILD_BATTLESHIP_SETUP__ = buildV102BattleshipSetupOptimistic;
}
