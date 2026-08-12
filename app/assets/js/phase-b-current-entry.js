import './production-regression-fix-entry.js?v=122&ttt=single-owner';
import { initPhaseBCurrentRuntime } from './phase-b-current-runtime.js?v=122&ttt=authoritative-clock';

window.__MGW_PHASE_B_BUILD__ = 'phase-b-current-v122-ttt-authoritative-clock';
initPhaseBCurrentRuntime();
