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
  const canonicalProfileLoaded = Boolean(String(state.mgwProfile?.mgw_id || '').trim()) || user?.mgw_profile_loaded === true;
  const canonicalAvatarId = String(state.mgwProfile?.avatar?.item_id || user?.avatar_item_id || 'starter-default-01').trim();
  const avatarLabel = canonicalProfileLoaded ? String(user?.avatar_label || 'MG') : initials(name);
  const photoOwnerId = String(state.mgwProfile?.mgw_id || user?.mgw_id || user?.id || user?.telegram_id || '').trim();
  const telegramOwnerId = String(user?.id || user?.telegram_id || '').trim();
  const explicitPhotoUrl = canonicalProfileLoaded ? '' : String(user?.photo_url || '').trim();
  const telegramPhotoUrl = canonicalProfileLoaded ? '' : currentTelegramPhotoUrl(telegramOwnerId);

  ['topName','profileName','searchMeName'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = name;
  });
  ['topAvatar','profileAvatar','searchMeAvatar'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const existingOwner = String(el.dataset.photoOwner || '').trim();
    const existingPhotoUrl = existingOwner === photoOwnerId ? String(el.dataset.photoUrl || '').trim() : '';
    const photoUrl = explicitPhotoUrl || telegramPhotoUrl || existingPhotoUrl;
    el.dataset.photoOwner = photoOwnerId;
    if (canonicalProfileLoaded) el.dataset.avatarId = canonicalAvatarId;
    if (photoUrl) {
      el.dataset.photoUrl = photoUrl;
      el.textContent = '';
      el.style.backgroundImage = `url("${photoUrl.replace(/["\\]/g, '\\$&')}")`;
      el.style.backgroundSize = 'cover';
      el.style.backgroundPosition = 'center';
      el.style.backgroundRepeat = 'no-repeat';
      el.classList.add('has-photo');
      return;
    }
    delete el.dataset.photoUrl;
    el.textContent = avatarLabel;
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

function currentTelegramPhotoUrl(ownerId){
  const telegramUser = window.Telegram?.WebApp?.initDataUnsafe?.user;
  if (!telegramUser) return '';
  const telegramUserId = String(telegramUser.id || '').trim();
  if (ownerId && telegramUserId && ownerId !== telegramUserId) return '';
  return String(telegramUser.photo_url || '').trim();
}
