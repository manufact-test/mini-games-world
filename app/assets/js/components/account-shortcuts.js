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
    friendsModulePromise = import('../screens/friends-screen-v110.js?v=6&mvp18=instant-route&optimistic-relations&mvp22_3=report-categories-v1')
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

}
