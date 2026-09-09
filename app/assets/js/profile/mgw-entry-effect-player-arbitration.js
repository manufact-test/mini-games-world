/* Pure presentation selector only. It does not own timers, rendering, state, game flow, or economy. */
export function arbitratePlayerEntryEffects(players, { isLocalPlayer, resolveEntry } = {}){
  const list = Array.isArray(players) ? players : [];
  if (typeof resolveEntry !== 'function') return [];

  const localIndex = typeof isLocalPlayer === 'function'
    ? list.findIndex((player, index) => isLocalPlayer(player, index))
    : -1;

  /* Spectator/unknown-viewer arbitration is outside this MVP slice. Preserve
   * the pre-existing multi-player presentation behavior instead of inventing
   * a spectator priority policy here. */
  if (localIndex < 0) {
    return list.map((player, index) => resolveEntry(player, index)).filter(Boolean);
  }

  const own = resolveEntry(list[localIndex], localIndex);
  if (own) return [own];

  for (let index = 0; index < list.length; index += 1) {
    if (index === localIndex) continue;
    const opponent = resolveEntry(list[index], index);
    if (opponent) return [opponent];
  }

  return [];
}
