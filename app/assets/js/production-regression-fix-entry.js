import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initProductionUiStabilityFix } from './production-ui-stability-fix.js?v=94';
import {
  initCrossGameCoordinator,
  scheduleCrossGameCoordinatorAfterMain,
} from './production-cross-game-coordinator.js?v=96';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';
import {
  initTicTacToeTurnFixEarly,
  scheduleTicTacToeTurnFixAfter,
} from './production-tictactoe-turn-fix.js?v=94';

window.__MGW_REGRESSION_BUILD__ = 'v96-mvp14-root-cause-stabilization';

/* Session scoping must own request identity before any other fetch wrapper captures it. */
initSessionOwnershipFix();
initProductionUiStabilityFix();
initCrossGameCoordinator();
scheduleCrossGameCoordinatorAfterMain();
initDeterministicGameIcons();
initStandardAvatarPolicy();
initTicTacToeTurnFixEarly();
scheduleTicTacToeTurnFixAfter();
