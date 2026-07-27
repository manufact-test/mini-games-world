import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initV99SessionTransport } from './production-v99-session-transport.js?v=99';
import { initV99ExplicitLockGuard } from './production-v99-explicit-lock-guard.js?v=99';
import { initV99InvitePickerHold } from './production-v99-invite-picker-hold.js?v=99';
import { initV100ShareController } from './production-v100-share-controller.js?v=100';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v100-mvp14-global-latency-share';

initSessionOwnershipFix();
initV99SessionTransport();
initV99ExplicitLockGuard();

/* The v100 controller is the only owner of Telegram link sharing. */
initV100ShareController();

/* Visual-only helpers do not own search, game actions or polling. */
initV99InvitePickerHold();
initDeterministicGameIcons();
initStandardAvatarPolicy();
