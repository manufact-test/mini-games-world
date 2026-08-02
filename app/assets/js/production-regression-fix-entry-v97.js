import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initProductionUiStabilityFix } from './production-ui-stability-fix.js?v=94';
import { initProductionV97RuntimeOwner } from './production-v97-runtime-owner.js?v=97';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';
import { initPreparedShareFix } from './production-prepared-share-fix.js?v=93';
import './production-v97-game-poll-bridge.js?v=97';

window.__MGW_REGRESSION_BUILD__ = 'v97-mvp14-single-runtime-owner';

/* Request identity must be scoped before any later fetch wrapper captures it. */
initSessionOwnershipFix();
initProductionUiStabilityFix();

/* One v97 coordinator owns search, notifications, session lock and all game actions. */
initProductionV97RuntimeOwner();

initDeterministicGameIcons();
initStandardAvatarPolicy();
initPreparedShareFix();
