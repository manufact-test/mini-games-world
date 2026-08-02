import { state } from './state.js?v=27';
import { renderStats } from './screens/home-screen.js?v=74';

const runtime = window.__MGW_V110_STATS_OWNER__ ||= {
  issued:0,
  applied:0,
};

export function beginStatsRequest(){
  runtime.issued += 1;
  return runtime.issued;
}

export function applyStatsSnapshot(ticket, stats){
  if (!stats || typeof stats !== 'object') return false;
  const sequence = Number(ticket || 0);
  if (!Number.isFinite(sequence) || sequence <= 0) return false;
  if (sequence < runtime.applied) return false;

  runtime.applied = sequence;
  state.stats = { ...stats };
  renderStats(state.stats);
  return true;
}
