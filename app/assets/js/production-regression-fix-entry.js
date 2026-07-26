import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';
import { initPreparedShareFix } from './production-prepared-share-fix.js?v=93';
import {
  initTicTacToeTurnFixEarly,
  scheduleTicTacToeTurnFixAfter,
} from './production-tictactoe-turn-fix.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v93-mvp14-share-game-avatar-regression-fix';

initStandardAvatarPolicy();
initPreparedShareFix();
initTicTacToeTurnFixEarly();
scheduleTicTacToeTurnFixAfter();
