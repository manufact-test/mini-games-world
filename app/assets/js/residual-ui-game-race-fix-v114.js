import { api } from './api/client.js?v=47';

let earlyInstalled = false;
let afterInstalled = false;
let baseGameState = null;
const gameStateInFlightByKey = new Map();

export function initResidualUiGameRaceFixEarly(){
  if (earlyInstalled) return;
  earlyInstalled = true;

  // v114 deliberately owns no clicks and renders no interface. Notifications,
  // Share, invite terminal actions, histories and game moves remain with their
  // canonical modules. This layer only exists to preserve request coalescing.
  window.__MGW_RESIDUAL_V114__ = Object.freeze({
    uiOwner:false,
    notificationOwner:false,
    shareOwner:false,
    inviteActionOwner:false,
    gameMoveOwner:false,
    gameStateCoalescing:true,
  });
}

export function initResidualUiGameRaceFixAfter(){
  if (afterInstalled) return;
  afterInstalled = true;

  baseGameState = api.gameState.bind(api);
  api.gameState = coalescedGameState;
}

function coalescedGameState(gameId = null){
  const key = String(gameId || 'active');
  const existing = gameStateInFlightByKey.get(key);
  if (existing) return existing;

  const request = Promise.resolve()
    .then(() => baseGameState(gameId))
    .finally(() => {
      if (gameStateInFlightByKey.get(key) === request) {
        gameStateInFlightByKey.delete(key);
      }
    });

  gameStateInFlightByKey.set(key, request);
  return request;
}
