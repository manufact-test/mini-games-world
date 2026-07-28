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
import { initV102ShareController } from './production-v102-share-controller.js?v=102';
import { initV103TargetedInteractions } from './production-v103-targeted-interactions.js?v=103';
import { initV104Presence } from './production-v104-presence.js?v=104';
import { initV104GamePollTuning } from './production-v104-game-poll-tuning.js?v=104';
import { initV104InviteGameControls } from './production-v104-invite-game-controls.js?v=104';
import { initV104TicTacToeStability } from './production-v104-tictactoe-stability.js?v=104';
import { initV104ResultInstant } from './production-v104-result-instant.js?v=104';
import { initDeterministicGameIcons } from './production-deterministic-icons.js?v=96';
import { initStandardAvatarPolicy } from './production-standard-avatar.js?v=93';

window.__MGW_REGRESSION_BUILD__ = 'v104-mvp14-game-interaction-finalization';

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
initV102ShareController();
initV103TargetedInteractions();
initV104Presence();
initV104InviteGameControls();
initV104TicTacToeStability();
initV101FastInviteWatch();
initV104ResultInstant();

initV100SearchEventBridge();

initV99InvitePickerHold();
initDeterministicGameIcons();
initStandardAvatarPolicy();
