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
import { initV102BattleshipBridge } from './production-v102-battleship-bridge.js?v=102';
import { initV102HistoryController } from './production-v102-history-controller.js?v=102';
import { initV103TargetedInteractions } from './production-v103-targeted-interactions.js?v=103';
import { initV104GamePollTuning } from './production-v104-game-poll-tuning.js?v=104';
import { initV104InviteGameControls } from './production-v104-invite-game-controls.js?v=104';
import { initV104ResultInstant } from './production-v104-result-instant.js?v=104';
import { initV105TicTacToeStability } from './production-v105-tictactoe-stability.js?v=105';
import { initV105InviteLatency } from './production-v105-invite-latency.js?v=105';
import { initV109InviteSpeed } from './production-v109-invite-speed.js?v=109';
import { initV109ShareSpeed } from './production-v109-share-speed.js?v=109';
import { initV109Notifications } from './production-v109-notifications.js?v=109';
import { initV109Presence } from './production-v109-presence.js?v=109';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v109-mvp14-speed-ui-only';

// These window-capture/speed owners are the only v109 client changes. They run
// before retained document/element handlers and do not initialize any game,
// timer, move, result or rematch owner.
initV109Notifications();
initV109ShareSpeed();
initV109InviteSpeed();
initV109Presence();

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
initV103TargetedInteractions();

// Retain the v105 lightweight incoming-invite signal watch. Its document click
// owner is bypassed only for actions already owned by the earlier v109 window
// capture module.
initV105InviteLatency();
initV104InviteGameControls();
initV105TicTacToeStability();
initV101FastInviteWatch();
initV104ResultInstant();

initV100SearchEventBridge();
initV99InvitePickerHold();
initDeterministicGameIcons();
initStandardAvatarPolicy();
