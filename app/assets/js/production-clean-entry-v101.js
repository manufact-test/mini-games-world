import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initV99SessionTransport } from './production-v99-session-transport.js?v=99';
import { initV99ExplicitLockGuard } from './production-v99-explicit-lock-guard.js?v=99';
import { initV99InvitePickerHold } from './production-v99-invite-picker-hold.js?v=99';
import { initV100SearchEventBridge } from './production-v100-search-event-bridge.js?v=100';
import { initV101SpeedRuntime } from './production-v101-speed-runtime.js?v=101';
import { initV101ShareController } from './production-v101-share-controller.js?v=101';
import { initV101FastInviteWatch } from './production-v101-fast-invite-watch.js?v=101';
import { initV101ResultSpeed } from './production-v101-result-speed.js?v=101';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v101-mvp14-global-speed';

/* Identity and secondary-device protection must remain outermost. */
initSessionOwnershipFix();
initV99SessionTransport();
initV99ExplicitLockGuard();

/* Performance-only owners. They do not validate moves or alter game rules. */
initV101SpeedRuntime();
initV101ShareController();
initV101FastInviteWatch();
initV101ResultSpeed();

/* Retained result buttons still emit the v100 search event. */
initV100SearchEventBridge();

/* Visual-only helpers. */
initV99InvitePickerHold();
initDeterministicGameIcons();
initStandardAvatarPolicy();
