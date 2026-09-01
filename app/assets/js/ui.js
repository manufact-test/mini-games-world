import { state } from './state.js?v=27';

const PROFILE_NAME_COLOR_IDS = new Set([
  'profile-name-color-sky',
  'profile-name-color-gold',
  'profile-name-color-aurora',
]);

function activeProfileNameColorItemId(user){
  const equipped = state.profileInventory && typeof state.profileInventory === 'object' && state.profileInventory.equipped && typeof state.profileInventory.equipped === 'object'
    ? state.profileInventory.equipped
    : {};
  const candidate = String(equipped.profile_name_color || state.mgwProfile?.name_color_item_id || user?.name_color_item_id || '').trim();
  return PROFILE_NAME_COLOR_IDS.has(candidate) ? candidate : '';
}

export function formatDate(value){
  if (!value) return 'Дата регистрации появится после входа';
  return new Intl.DateTimeFormat('ru-RU', { day:'2-digit', month:'long', year:'numeric' }).format(new Date(value));
}
export function initials(name){
  const clean = (name || 'MG').replace('@','').trim();
  return clean.slice(0,2).toUpperCase() || 'MG';
}
export function username(user){
  const profileNickname = String(state.mgwProfile?.nickname || state.mgwProfile?.display_name || '').trim();
  if (profileNickname) return profileNickname;
  if (user?.mgw_profile_loaded === true) return user?.display_name || user?.first_name || 'Игрок';
  if (user?.username) return '@' + user.username;
  return user?.display_name || user?.first_name || 'Игрок';
}
export function roomName(){ return 'Обычный матч'; }
export function renderUser(user){
  const name = username(user);
  const nameColorItemId = activeProfileNameColorItemId(user);
  const resolvedAvatarId = String(state.selectedAvatarId || state.mgwProfile?.avatar?.item_id || user?.avatar_item_id || '').trim();
  const canonicalAvatarId = resolvedAvatarId || 'starter-default-01';
  if (!state.selectedAvatarId && resolvedAvatarId) state.selectedAvatarId = canonicalAvatarId;

  ['topName','profileName','searchMeName'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = name;
    if (nameColorItemId) el.dataset.nameColorItemId = nameColorItemId;
    else delete el.dataset.nameColorItemId;
  });
  ['topAvatar','profileAvatar','searchMeAvatar'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.dataset.avatarId = canonicalAvatarId;
    delete el.dataset.photoUrl;
    delete el.dataset.photoOwner;
    el.textContent = 'MG';
    el.style.backgroundImage = '';
    el.style.backgroundSize = '';
    el.style.backgroundPosition = '';
    el.style.backgroundRepeat = '';
    el.classList.remove('has-photo');
  });
  const date = document.getElementById('profileDate');
  if (date) date.textContent = user?.registered_at ? `В игре с ${formatDate(user.registered_at)}` : 'Дата регистрации появится после входа';
}
export function renderBalances(user){
  const unified = document.getElementById('balanceUnified');
  if (unified) unified.textContent = user?.balance ?? '—';
}
export function clearTimer(timer){ if (timer) clearInterval(timer); return null; }

document.addEventListener('mgw:cosmetic-inventory-changed', () => {
  if (state.user && typeof state.user === 'object') renderUser(state.user);
});
