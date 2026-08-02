import { state } from './state.js?v=27';
import { renderStats } from './screens/home-screen.js?v=74';

const ONLINE_DROP_GRACE_MS = 6500;

const runtime = window.__MGW_V110_STATS_OWNER__ ||= {
  issued:0,
  applied:0,
  pendingOnlineDrop:null,
};

export function beginStatsRequest(){
  runtime.issued += 1;
  return runtime.issued;
}

export function applyStatsSnapshot(ticket, stats){
  const sequence = Number(ticket || 0);
  if (!stats || typeof stats !== 'object' || sequence <= 0) return false;
  if (sequence < runtime.applied) return false;

  runtime.applied = sequence;
  const next = { ...stats };
  next.online_players = stableOnlineCount(next.online_players);
  state.stats = next;
  renderStats(state.stats);
  return true;
}

function stableOnlineCount(value){
  const next = finiteCount(value);
  if (next === null) return value;

  const current = finiteCount(state.stats?.online_players);
  if (current === null || document.visibilityState !== 'visible' || next >= current) {
    runtime.pendingOnlineDrop = null;
    return next;
  }

  const now = Date.now();
  const pending = runtime.pendingOnlineDrop;
  if (!pending || pending.value !== next || pending.from !== current) {
    runtime.pendingOnlineDrop = { value:next, from:current, startedAt:now };
    return current;
  }

  if (now - Number(pending.startedAt || 0) < ONLINE_DROP_GRACE_MS) return current;
  runtime.pendingOnlineDrop = null;
  return next;
}

function finiteCount(value){
  const number = Number(value);
  if (!Number.isFinite(number)) return null;
  return Math.max(0, Math.trunc(number));
}
