import { state } from './state.js?v=27';

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
  const canonicalAvatarId = String(state.selectedAvatarId || state.mgwProfile?.avatar?.item_id || user?.avatar_item_id || 'starter-default-01').trim() || 'starter-default-01';
  if (!state.selectedAvatarId) state.selectedAvatarId = canonicalAvatarId;

  ['topName','profileName','searchMeName'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = name;
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
