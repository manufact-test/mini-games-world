import { closeSheet } from './sheet.js?v=1109';

let friendsModulePromise = null;
let accountDataModulePromise = null;

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
    friendsModulePromise = import('../screens/friends-screen-v110.js?v=6&mvp18=instant-route&optimistic-relations&mvp22_3=report-categories-v1')
      .catch(error => {
        friendsModulePromise = null;
        throw error;
      });
  }
  return friendsModulePromise;
}

async function openAccountDataShortcut(){
  closeSheet();
  const module = await loadAccountDataModule();
  if (typeof module.openAccountDataSheet === 'function') await module.openAccountDataSheet();
}

function loadAccountDataModule(){
  if (!accountDataModulePromise) {
    accountDataModulePromise = import('../screens/account-data-sheet-v1.js?v=2&mvp22_8=account-data-v1&ux=corrective-v2')
      .catch(error => {
        accountDataModulePromise = null;
        throw error;
      });
  }
  return accountDataModulePromise;
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

    const accountData = document.createElement('button');
    accountData.className = 'btn menu-item account-menu-entry account-menu-entry--account-data';
    accountData.type = 'button';
    accountData.dataset.accountDataShortcut = '1';
    accountData.innerHTML = `
      <span class="account-menu-icon" aria-hidden="true">
        <svg width="27" height="27" viewBox="0 0 24 24" fill="none" focusable="false">
          <ellipse cx="12" cy="5.5" rx="7.5" ry="3" stroke="currentColor" stroke-width="1.8"/>
          <path d="M4.5 5.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          <path d="M4.5 11.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
      </span>
      <span class="account-menu-copy"><strong>Данные и аккаунт</strong></span>
    `;
    accountData.addEventListener('click', () => {
      void openAccountDataShortcut();
    });
    menu.append(accountData);
    void loadAccountDataModule();
  }

}
