import { api } from '../api/client.js?v=47';
import { closeSheet } from './sheet.js?v=1109';

let friendsModulePromise = null;

export function initAccountShortcuts(){
  document.addEventListener('click', event => {
    const trigger = event.target.closest('#moreMenuOpen, #gameMenuOpen');
    if (!trigger) return;

    // home-screen opens the menu synchronously before this listener runs.
    const allowSocialNavigation = trigger.id === 'moreMenuOpen';
    queueMicrotask(() => enhanceCurrentMenu(allowSocialNavigation));
  });
}

async function openFriendsShortcut(){
  closeSheet();
  const module = await loadFriendsModule();
  if (typeof module.initFriendsScreen === 'function') module.initFriendsScreen();
  document.dispatchEvent(new CustomEvent('mgw:open-friends'));
}

function loadFriendsModule(){
  if (!friendsModulePromise) {
    friendsModulePromise = import('../screens/friends-screen-v110.js?v=5&mvp18=instant-route&optimistic-relations')
      .catch(error => {
        friendsModulePromise = null;
        throw error;
      });
  }
  return friendsModulePromise;
}

async function enhanceCurrentMenu(allowSocialNavigation = false){
  const sheet = document.getElementById('sheet');
  const menu = sheet?.querySelector('.menu-list');
  if (!menu) return;

  if (allowSocialNavigation && !sheet.querySelector('[data-account-friends-shortcut]')) {
    const friends = document.createElement('button');
    friends.className = 'btn menu-item account-menu-entry account-menu-entry--friends';
    friends.type = 'button';
    friends.dataset.accountFriendsShortcut = '1';
    friends.innerHTML = `
      <span class="account-menu-icon" aria-hidden="true">👥</span>
      <span class="account-menu-copy"><strong>Друзья</strong></span>
    `;
    friends.addEventListener('click', () => {
      void openFriendsShortcut();
    });
    menu.prepend(friends);
    void loadFriendsModule();
  }

  if (sheet.querySelector('[data-account-orders-shortcut]')) return;

  const button = document.createElement('button');
  button.className = 'btn menu-item account-menu-entry account-menu-entry--orders';
  button.type = 'button';
  button.dataset.accountOrdersShortcut = '1';
  button.dataset.openStoreOrders = '1';
  button.innerHTML = `
    <span class="account-menu-icon" aria-hidden="true">🎁</span>
    <span class="account-menu-copy">
      <strong>Мои заявки</strong>
    </span>
    <b class="account-menu-count" hidden>0</b>
  `;

  menu.prepend(button);

  try {
    const result = await api.shopOrders();
    if (!document.body.contains(button)) return;

    const orders = Array.isArray(result.orders) ? result.orders : [];
    const badge = button.querySelector('.account-menu-count');

    if (badge && orders.length > 0) {
      badge.hidden = false;
      badge.textContent = orders.length > 99 ? '99+' : String(orders.length);
    }
  } catch (error) {
    // Keep the shortcut usable even if the count cannot be refreshed.
  }
}
