import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initV98PassiveSessionTransport } from './production-v98-passive-session-transport.js?v=98';
import { initV98UiOwnerEarly, initV98UiOwnerAfter } from './production-v98-ui-owner.js?v=98';
import { initProductionUiStabilityFix } from './production-ui-stability-fix.js?v=94';
import { initProductionV97RuntimeOwner } from './production-v97-runtime-owner.js?v=97';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';
import { initPreparedShareFix } from './production-prepared-share-fix.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v98-mvp14-passive-session-ui-stability';

/* Scope request identity first, then silence only passive secondary-device reads. */
initSessionOwnershipFix();
initV98PassiveSessionTransport();

/* Register visible transition guards before legacy and v97 capture listeners. */
initV98UiOwnerEarly();
initProductionUiStabilityFix();

/* Retain the reviewed v97 action queue, but replace its polling bridge with v98. */
initProductionV97RuntimeOwner();
initV98UiOwnerAfter();

initDeterministicGameIcons();
initStandardAvatarPolicy();
initPreparedShareFix();
