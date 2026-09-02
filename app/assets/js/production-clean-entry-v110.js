import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initV99SessionTransport } from './production-v99-session-transport.js?v=99';
import { initV99ExplicitLockGuard } from './production-v99-explicit-lock-guard.js?v=99';
import { initV100SearchEventBridge } from './production-v100-search-event-bridge.js?v=100';
import { initV101PollTuning } from './production-v101-poll-tuning.js?v=101';
import { initV101SpeedRuntime } from './production-v101-speed-runtime-v102.js?v=102&b=786d11d53360';
import { initV101InviteSyncDedupe } from './production-v101-invite-sync-dedupe.js?v=101';
import { initV101CacheSafety } from './production-v101-cache-safety.js?v=101';
import { initV102BattleshipBridge } from './production-v102-battleship-bridge.js?v=102';
import { initV104GamePollTuning } from './production-v104-game-poll-tuning.js?v=104';
import { initV109SearchSpeed } from './production-v109-search-speed.js?v=109';
import { initV110AcceptanceRuntime } from './production-v110-acceptance-runtime.js?v=110';
import { initV110MatchLifecycle } from './production-v110-match-lifecycle.js?v=1106&release=battleship-action-quarantine';
import { initV110TargetedInteractions } from './production-v110-targeted-interactions.js?v=1102';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=97&sk=6';
import { initMgwAvatarPresentation } from './profile/mgw-avatar-presentation.js?v=6&mvp19_4=illustrated-raster-roster-v5&mvp19_3=name-colors';
import { initMgwProfileBadges } from './profile/mgw-profile-badges.js?v=5&mvp19_3=profile-badge-avatar-shape';
import { initMgwProfileFrames } from './profile/mgw-profile-frames.js?v=3&mvp19_3=profile-frame-name-polish';

window.__MGW_REGRESSION_BUILD__ = 'v110-mvp14-interface-invite-speed-v1135';

// The canonical v110 invitation module owns setup, player selection, link
// creation, sharing and invite actions. Historical share/picker layers must not
// capture the same controls or create speculative drafts in the background.
initV110AcceptanceRuntime();
initV110MatchLifecycle();
initV109SearchSpeed();

initSessionOwnershipFix();
initV99SessionTransport();
initV99ExplicitLockGuard();

initV101PollTuning();
initV104GamePollTuning();
initV101SpeedRuntime();
initV101InviteSyncDedupe();
initV101CacheSafety();
initV102BattleshipBridge();
initV110TargetedInteractions();

initV100SearchEventBridge();
initDeterministicGameIcons();
initMgwAvatarPresentation();
initMgwProfileBadges();
initMgwProfileFrames();
