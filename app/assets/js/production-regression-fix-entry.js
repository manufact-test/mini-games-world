import { initProductionUiStabilityFix } from './production-ui-stability-fix.js?v=95';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=95';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';
import { initPreparedShareFix } from './production-prepared-share-fix.js?v=93';
import {
  initTicTacToeTurnFixEarly,
  scheduleTicTacToeTurnFixAfter,
} from './production-tictactoe-turn-fix.js?v=94';

window.__MGW_REGRESSION_BUILD__ = 'v95-mvp14-cross-game-consistency-fix';

/* Install shared fetch/API/UI coordination before the v92 module graph captures it. */
initProductionUiStabilityFix();
initDeterministicGameIcons();
initStandardAvatarPolicy();
initPreparedShareFix();
initTicTacToeTurnFixEarly();
scheduleTicTacToeTurnFixAfter();
