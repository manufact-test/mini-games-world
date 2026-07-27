import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initV99SessionTransport } from './production-v99-session-transport.js?v=99';
import { initV99InvitePickerHold } from './production-v99-invite-picker-hold.js?v=99';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v99-mvp14-clean-runtime';

/* Identity and passive reads are the only request wrappers in the clean graph. */
initSessionOwnershipFix();
initV99SessionTransport();

/* Visual-only helpers do not own search, game actions or polling. */
initV99InvitePickerHold();
initDeterministicGameIcons();
initStandardAvatarPolicy();
