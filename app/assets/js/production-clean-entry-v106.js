import './production-clean-entry-v105.js?v=105';
import { initV106InviteActions } from './production-v106-invite-actions.js?v=106';
import { initV106SelfToastPolicy } from './production-v106-self-toast-policy.js?v=106';
import { initV106TicTacToeTimerAndMobilePin } from './production-v106-timer-mobile.js?v=106';

window.__MGW_REGRESSION_BUILD__ = 'v106-mvp14-invite-timer-mobile-stability';

/* Register the exact self-action toast policy first. The following window
 * capture owner then stops retained document handlers before their wait state. */
initV106SelfToastPolicy();
initV106InviteActions();
initV106TicTacToeTimerAndMobilePin();
