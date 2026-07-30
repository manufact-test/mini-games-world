window.__MGW_BUILD__ = 'v110-mvp14r2-handoff-batch';

// Use an isolated v110 shell so historical v103/v105 rollback assets remain
// byte-for-byte unchanged while the two corrected handoffs ship together.
import './main-v110-handoff-shell.js?v=1102';
