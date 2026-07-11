import { api } from '../api/client.js?v=47';
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
  const orderItems = Array.isArray(orders) ? orders : [];

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
      <button class="profile-orders-action" data-open-store-orders type="button">
        <span class="profile-orders-icon" aria-hidden="true">🎁</span>
        <span class="profile-orders-copy">
          <strong>Мои заявки</strong>
        </span>
        ${orderItems.length > 0 ? `<b class="profile-orders-badge">${orderItems.length > 99 ? '99+' : orderItems.length}</b>` : ''}
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

function formatNumber(value){
  return Number(value || 0).toLocaleString('ru-RU');
}
