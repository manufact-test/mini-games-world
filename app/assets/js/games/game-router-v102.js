import {
  gameMetaText,
  gameStatusText,
  gameTypeOf,
  playerMarkText,
  renderGameSurface as renderBaseGameSurface,
} from './game-router.js?v=74';
import { renderBattleshipSurface } from './battleship/renderer-v102.js?v=102';

export { gameMetaText, gameStatusText, gameTypeOf, playerMarkText };

export function renderGameSurface({ game, me, container, onAction }){
  if (gameTypeOf(game) === 'battleship') {
    const pendingFireCell = Number(game?.pending_fire_cell);
    const preservePendingBattleDom = String(game?.phase || '') !== 'setup'
      && Number.isInteger(pendingFireCell)
      && pendingFireCell >= 0
      && pendingFireCell < 100
      && Boolean(container?.querySelector?.('.battleship-coordinate-board'));

    // Pending fire is presentation-only and decoratePendingSurface owns its marker.
    // Keep the existing board DOM alive so one tap does not rebuild the whole field
    // before the authoritative miss/hit/sunk response arrives.
    if (preservePendingBattleDom) return;

    renderBattleshipSurface({ game, me, container, onAction });
    return;
  }
  renderBaseGameSurface({ game, me, container, onAction });
}