import {
  renderBattleshipSurface as renderBaseBattleshipSurface,
  battleshipMeta,
  battleshipPlayerMark,
  battleshipStatus,
} from './renderer.js?v=56';
import { createV102RandomizeAction } from '../../production-v102-battleship-models.js?v=102';

export { battleshipMeta, battleshipPlayerMark, battleshipStatus };

export function renderBattleshipSurface({ game, me, container, onAction }){
  renderBaseBattleshipSurface({
    game,
    me,
    container,
    onAction:action => {
      if (String(action?.type || '') === 'randomize_fleet' && !Array.isArray(action?.ships)) {
        onAction?.(createV102RandomizeAction());
        return;
      }
      onAction?.(action);
    },
  });
}
