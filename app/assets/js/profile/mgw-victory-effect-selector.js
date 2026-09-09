const VICTORY_EFFECT_IDS = new Set([
  'profile-victory-effect-01',
  'profile-victory-effect-02',
  'profile-victory-effect-03',
]);

export function selectWinnerVictoryEffect(game){
  if (!game || typeof game !== 'object' || String(game.status || '') !== 'finished') return null;
  const winnerId = String(game.winner_id || '').trim();
  if (!winnerId) return null;

  const players = Array.isArray(game.players) ? game.players : [];
  const playerIndex = players.findIndex(player => String(player?.id || '').trim() === winnerId);
  if (playerIndex < 0) return null;

  const winner = players[playerIndex];
  const itemId = String(winner?.victory_effect_item_id || '').trim();
  if (!VICTORY_EFFECT_IDS.has(itemId)) return null;

  return Object.freeze({
    winnerId,
    itemId,
    playerIndex,
    name:String(winner?.name || `Игрок ${playerIndex + 1}`),
  });
}

export function isCanonicalVictoryEffectItemId(itemId){
  return VICTORY_EFFECT_IDS.has(String(itemId || '').trim());
}
