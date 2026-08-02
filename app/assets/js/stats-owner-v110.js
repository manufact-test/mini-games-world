import { state } from './state.js?v=27';
import { renderStats } from './screens/home-screen.js?v=74';

const runtime = window.__MGW_V110_STATS_OWNER__ ||= {
  issued:{ api:0, presence:0 },
  applied:{ api:0, presence:0 },
};

export function beginStatsRequest(source = 'api'){
  const owner = normalizeSource(source);
  runtime.issued[owner] = Number(runtime.issued?.[owner] || 0) + 1;
  return { owner, sequence:runtime.issued[owner] };
}

export function applyStatsSnapshot(ticket, stats){
  if (!stats || typeof stats !== 'object') return false;

  const owner = normalizeSource(ticket?.owner);
  const sequence = Number(ticket?.sequence || 0);
  if (!Number.isFinite(sequence) || sequence <= 0) return false;
  if (sequence < Number(runtime.applied?.[owner] || 0)) return false;

  runtime.applied[owner] = sequence;
  const current = state.stats && typeof state.stats === 'object' ? state.stats : {};
  const next = { ...current };

  if (owner === 'presence') {
    if (Object.prototype.hasOwnProperty.call(stats, 'online_players')) {
      next.online_players = stats.online_players;
    }
  } else {
    for (const [key, value] of Object.entries(stats)) {
      if (key === 'online_players') continue;
      next[key] = value;
    }
  }

  state.stats = next;
  renderStats(state.stats);
  return true;
}

function normalizeSource(source){
  return String(source || '') === 'presence' ? 'presence' : 'api';
}
