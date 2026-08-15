export function formatDate(value){
  if (!value) return 'Дата регистрации появится после входа';
  return new Intl.DateTimeFormat('ru-RU', { day:'2-digit', month:'long', year:'numeric' }).format(new Date(value));
}
export function initials(name){
  const clean = (name || 'MG').replace('@','').trim();
  return clean.slice(0,2).toUpperCase() || 'MG';
}
export function username(user){
  if (user?.username) return '@' + user.username;
  return user?.display_name || user?.first_name || 'Игрок';
}
export function roomName(){ return 'Обычный матч';
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
