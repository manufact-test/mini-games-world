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
    renderBattleshipSurface({ game, me, container, onAction });
    return;
  }
  renderBaseGameSurface({ game, me, container, onAction });
}
