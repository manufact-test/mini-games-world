import { initSessionOwnershipFix } from './production-session-ownership-fix.js?v=96';
import { initV99SessionTransport } from './production-v99-session-transport.js?v=99';
import { initV99ExplicitLockGuard } from './production-v99-explicit-lock-guard.js?v=99';
import { initV99InvitePickerHold } from './production-v99-invite-picker-hold.js?v=99';
import { initV100SearchEventBridge } from './production-v100-search-event-bridge.js?v=100';
import { initV101PollTuning } from './production-v101-poll-tuning.js?v=101';
import { initV101SpeedRuntime } from './production-v101-speed-runtime.js?v=101';
import { initV101InviteSyncDedupe } from './production-v101-invite-sync-dedupe.js?v=101';
import { initV101CacheSafety } from './production-v101-cache-safety.js?v=101';
import { initV101FastInviteWatch } from './production-v101-fast-invite-watch.js?v=101';
import { initV101ResultSpeed } from './production-v101-result-speed.js?v=101';
import { initV102BattleshipBridge } from './production-v102-battleship-bridge.js?v=102';
import { initV102HistoryController } from './production-v102-history-controller.js?v=102';
import { initV102ShareController } from './production-v102-share-controller.js?v=102';
import { initV103TargetedInteractions } from './production-v103-targeted-interactions.js?v=103';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v103-mvp14-targeted-ui-turn-lock';

initSessionOwnershipFix();
initV99SessionTransport();
initV99ExplicitLockGuard();

initV101PollTuning();
initV101SpeedRuntime();
initV101InviteSyncDedupe();
initV101CacheSafety();
initV102BattleshipBridge();
initV102HistoryController();
initV102ShareController();
initV103TargetedInteractions();
initV101FastInviteWatch();
initV101ResultSpeed();

initV100SearchEventBridge();

initV99InvitePickerHold();
initDeterministicGameIcons();
initStandardAvatarPolicy();
