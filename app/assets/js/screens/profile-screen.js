import { api } from '../api/client.js?v=27';
import { state } from '../state.js?v=27';
import { showScreen } from '../router.js?v=27';
import { toast } from '../components/toast.js?v=27';
import { renderUser, renderBalances } from '../ui.js?v=27';

export function initProfileScreen(){
  document.addEventListener('mgw:open-profile', openProfile);
}
export async function openProfile(){
  try {
    const result = await api.profile();
    state.user = result.user;
    state.session = result.session || state.session;
renderUser(state.user);
    renderBalances(state.user);
    renderProfileStats(result.stats);
    showScreen('profile');
  } catch (error) { toast(error.message); }
}
function renderProfileStats(stats = {}){
  const el = document.getElementById('profileStats');
  if (!el) return;
  el.innerHTML = `
    <div class="stat"><div class="num">${stats.games_played ?? 0}</div><div class="label">игр сыграно</div></div>
    <div class="stat"><div class="num">${stats.wins ?? 0}</div><div class="label">побед</div></div>
    <div class="stat"><div class="num">${stats.losses ?? 0}</div><div class="label">поражений</div></div>
    <div class="stat"><div class="num">${stats.draws ?? 0}</div><div class="label">ничьих</div></div>
  `;
}
