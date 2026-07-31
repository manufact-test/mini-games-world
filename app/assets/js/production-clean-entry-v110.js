import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initV99SessionTransport } from './production-v99-session-transport.js?v=99';
import { initV99ExplicitLockGuard } from './production-v99-explicit-lock-guard.js?v=99';
import { initV99InvitePickerHold } from './production-v99-invite-picker-hold.js?v=99';
import { initV100SearchEventBridge } from './production-v100-search-event-bridge.js?v=100';
import { initV101PollTuning } from './production-v101-poll-tuning.js?v=101';
import { initV101SpeedRuntime } from './production-v101-speed-runtime.js?v=101';
import { initV101InviteSyncDedupe } from './production-v101-invite-sync-dedupe.js?v=101';
import { initV101CacheSafety } from './production-v101-cache-safety.js?v=101';
import { initV102BattleshipBridge } from './production-v102-battleship-bridge.js?v=102';
import { initV102HistoryController } from './production-v102-history-controller.js?v=102';
import { initV104GamePollTuning } from './production-v104-game-poll-tuning.js?v=104';
import { initV109SelfCancelRefreshGuard } from './production-v109-self-cancel-refresh-guard.js?v=109';
import { initV109ShareFallbackGuard } from './production-v109-share-fallback-guard.js?v=109';
import { initV109ShareSpeed } from './production-v109-share-speed.js?v=109';
import { initV109SearchSpeed } from './production-v109-search-speed.js?v=109';
import { initV110AcceptanceRuntime } from './production-v110-acceptance-runtime.js?v=110';
import { initV110MatchLifecycle } from './production-v110-match-lifecycle.js?v=1104';
import { initV110TargetedInteractions } from './production-v110-targeted-interactions.js?v=1102';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v110-mvp14r3-invite-presence-notification-profile-root';

// Active v110 keeps one invitation owner, one notification owner, one game
// renderer/result owner, one presence owner and one manual surrender owner.
// The notification preflight is transport only and never renders a second sheet.
initV110AcceptanceRuntime();
initV110MatchLifecycle();
initV109SelfCancelRefreshGuard();
initV109ShareFallbackGuard();
initV109ShareSpeed();
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
initV102HistoryController();
initV110TargetedInteractions();

initV100SearchEventBridge();
initV99InvitePickerHold();
initDeterministicGameIcons();
initStandardAvatarPolicy();
