import { initProductionUiStabilityFix } from './production-ui-stability-fix.js?v=94';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';
import { initPreparedShareFix } from './production-prepared-share-fix.js?v=93';
import {
  initTicTacToeTurnFixEarly,
  scheduleTicTacToeTurnFixAfter,
} from './production-tictactoe-turn-fix.js?v=94';

window.__MGW_REGRESSION_BUILD__ = 'v94-mvp14-ui-stability-fix';

/* Install the underlying read fallback before the v92 module graph captures fetch. */
initProductionUiStabilityFix();
initStandardAvatarPolicy();
initPreparedShareFix();
initTicTacToeTurnFixEarly();
scheduleTicTacToeTurnFixAfter();
