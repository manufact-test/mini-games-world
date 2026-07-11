import { api } from '../api/client.js?v=38';
import { state } from '../state.js?v=27';
import { showScreen } from '../router.js?v=27';
import { toast } from '../components/toast.js?v=27';
import { renderUser, renderBalances } from '../ui.js?v=27';

export function initProfileScreen(){
  document.addEventListener('mgw:open-profile', openProfile);
}

export async function openProfile(){
  try {
    const [result, ordersResult] = await Promise.all([
      api.profile(),
      api.shopOrders().catch(() => null),
    ]);

    state.user = result.user;
    state.session = result.session || state.session;
    renderUser(state.user);
    renderBalances(state.user);
    renderProfileStats(result.stats);
    renderProfileOverview(
      state.user || {},
      Array.isArray(ordersResult?.orders) ? ordersResult.orders : null,
    );
    showScreen('profile');
  } catch (error) {
    toast(error.message);
  }
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

function renderProfileOverview(user = {}, orders = null){
  const el = ensureProfileOverview();
  if (!el) return;

  const match = Number(user.balance_match || 0);
  const gold = Number(user.balance_gold || 0);
  const shopAvailable = Number(user.gold_shop_available || 0);
  const ordersAvailable = Array.isArray(orders);
  const orderItems = ordersAvailable ? orders : [];
  const activeOrders = orderItems.filter(order => ['pending', 'processing'].includes(String(order.status || ''))).length;
  const latest = orderItems[0] || null;
  const latestStatus = latest ? String(latest.status_label || statusLabel(latest.status)) : '';
  const latestTitle = latest ? String(latest.prize_title || latest.provider || 'Приз') : '';
  const tone = safeTone(latest?.status_tone);

  const ordersMeta = !ordersAvailable
    ? 'Не удалось обновить историю заказов.'
    : orderItems.length === 0
      ? 'Заказов пока нет.'
      : activeOrders > 0
        ? `${plural(activeOrders, 'активная заявка', 'активные заявки', 'активных заявок')} · последняя: ${latestStatus}`
        : `${plural(orderItems.length, 'заявка в истории', 'заявки в истории', 'заявок в истории')} · последняя: ${latestStatus}`;

  const ordersDetail = latest
    ? `${latestTitle}${latest.denomination_label ? ` · ${latest.denomination_label}` : ''}`
    : 'Здесь будут статусы заказов, причины отклонения и возвраты Gold.';

  el.innerHTML = `
    <section class="profile-overview-section">
      <div class="profile-section-head">
        <div>
          <h2>Мои средства</h2>
          <p>Текущие балансы и доступный Gold.</p>
        </div>
      </div>

      <div class="profile-wallet-grid">
        <div class="profile-wallet-card match">
          <span>🎲 Match</span>
          <strong>${formatNumber(match)}</strong>
          <small>для обычных матчей</small>
        </div>
        <div class="profile-wallet-card gold">
          <span>✨ Gold</span>
          <strong>${formatNumber(gold)}</strong>
          <small>баланс Gold-комнаты</small>
        </div>
        <div class="profile-wallet-card shop-available">
          <div>
            <span>🎁 Доступно для магазина</span>
            <small>Gold, который можно использовать для заказа призов</small>
          </div>
          <strong>${formatNumber(shopAvailable)}</strong>
        </div>
      </div>
    </section>

    <section class="profile-overview-section">
      <div class="profile-section-head compact">
        <div>
          <h2>Мои заявки</h2>
          <p>Быстрый доступ к истории заказов.</p>
        </div>
      </div>

      <button class="profile-orders-action" data-open-store-orders type="button">
        <span class="profile-orders-icon" aria-hidden="true">🧾</span>
        <span class="profile-orders-copy">
          <strong>${escapeHtml(ordersMeta)}</strong>
          <small>${escapeHtml(ordersDetail)}</small>
        </span>
        ${orderItems.length > 0 ? `<b class="profile-orders-badge ${tone}">${orderItems.length > 99 ? '99+' : orderItems.length}</b>` : ''}
        <span class="profile-orders-arrow" aria-hidden="true">›</span>
      </button>
    </section>
  `;
}

function ensureProfileOverview(){
  let el = document.getElementById('profileOverview');
  if (el) return el;

  const card = document.querySelector('#screen-profile .profile-card');
  if (!card) return null;

  el = document.createElement('div');
  el.id = 'profileOverview';
  el.className = 'profile-overview';
  card.insertAdjacentElement('afterend', el);
  return el;
}

function statusLabel(status){
  return {
    pending: 'Ожидает обработки',
    processing: 'В обработке',
    done: 'Выполнена',
    rejected: 'Отклонена',
    cancelled: 'Отменена',
  }[String(status || '')] || 'Статус уточняется';
}

function safeTone(value){
  const tone = String(value || 'muted');
  return ['success', 'danger', 'info', 'warning', 'muted'].includes(tone) ? tone : 'muted';
}

function plural(value, one, few, many){
  const number = Math.abs(Number(value || 0));
  const mod100 = number % 100;
  const mod10 = number % 10;
  if (mod100 >= 11 && mod100 <= 19) return `${number} ${many}`;
  if (mod10 === 1) return `${number} ${one}`;
  if (mod10 >= 2 && mod10 <= 4) return `${number} ${few}`;
  return `${number} ${many}`;
}

function formatNumber(value){
  return Number(value || 0).toLocaleString('ru-RU');
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
