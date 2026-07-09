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
  return user?.first_name || 'Игрок';
}
export function roomName(room){ return room === 'gold' ? 'Gold-комната' : 'Матч-комната'; }
export function renderUser(user){
  const name = username(user);
  const letter = initials(name);
  ['topName','profileName','searchMeName'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = name; });
  ['topAvatar','profileAvatar','searchMeAvatar'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = letter; });
  const date = document.getElementById('profileDate');
  if (date) date.textContent = user?.registered_at ? `В игре с ${formatDate(user.registered_at)}` : 'Дата регистрации появится после входа';
}
export function renderBalances(user){
  const match = document.getElementById('balanceMatch');
  const gold = document.getElementById('balanceGold');
  const shopAvailable = document.getElementById('shopAvailable');
  if (match) match.textContent = user?.balance_match ?? '—';
  if (gold) gold.textContent = user?.balance_gold ?? '—';
  if (shopAvailable) shopAvailable.textContent = user?.gold_shop_available ?? '—';
}
export function clearTimer(timer){ if (timer) clearInterval(timer); return null; }
