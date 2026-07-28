import './production-clean-entry-v105.js?v=105';
import { initV106InviteActions } from './production-v106-invite-actions.js?v=106';
import { initV106TicTacToeTimerAndMobilePin } from './production-v106-timer-mobile.js?v=106';

window.__MGW_REGRESSION_BUILD__ = 'v106-mvp14-invite-timer-mobile-stability';

/* Window-capture ownership runs before every retained document-level invite
 * handler, so accepted/start/cancel actions never enter the old wait state. */
initV106InviteActions();
initV106TicTacToeTimerAndMobilePin();
