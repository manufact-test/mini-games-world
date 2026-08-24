const ICON_ENDPOINT = './assets/shield-king-icon.php?v=c1efd5af&asset=';

const MENU_ICONS = {
  settingsBtn:'ui/navigation/settings.webp',
  rulesBtn:'ui/actions/rules.webp',
  feedbackBtn:'ui/status/info.webp',
  ideaBtn:'ui/status/success.webp',
  supportBtn:'ui/status/warning.webp',
  balanceHistoryBtn:'ui/navigation/history.webp',
  matchHistoryBtn:'ui/navigation/games.webp',
};

const SHELL_NAV_ICONS = {
  home:'ui/navigation/home.webp',
  tournaments:'ui/navigation/ranking.webp',
  store:'ui/navigation/store.webp',
  profile:'ui/navigation/profile.webp',
};

export function initShieldKingVisuals(){
  setIconOnly(document.getElementById('notificationsOpen'), 'ui/navigation/notifications.webp');
  setIconOnly(document.getElementById('moreMenuOpen'), 'ui/actions/more.webp');
  setIconOnly(document.getElementById('topbarBalanceIcon'), 'ui/economy/coins.webp');

  document.querySelectorAll('[data-shell-nav]').forEach(button => {
    const asset = SHELL_NAV_ICONS[String(button.dataset.shellNav || '')];
    const icon = button.querySelector('.app-bottom-nav-icon');
    if (asset && icon) setIconOnly(icon, asset);
  });

  document.querySelectorAll('.game-rules-button').forEach(button => {
    setIconOnly(button, 'ui/actions/rules.webp');
  });

  const balances = document.querySelectorAll('.balance-card .balance-label');
  setLabelIcon(balances[0], 'ui/economy/coins.webp', 'Матч-комната');
  setLabelIcon(balances[1], 'ui/economy/premium-currency.webp', 'Gold-комната');

  applyDynamicIcons(document);

  const sheet = document.getElementById('sheet');
  if (sheet && typeof MutationObserver === 'function') {
    const observer = new MutationObserver(() => applyDynamicIcons(sheet));
    observer.observe(sheet, { childList:true, subtree:true });
  }
}

function applyDynamicIcons(root){
  root.querySelectorAll?.('.game-rules-button').forEach(button => setIconOnly(button, 'ui/actions/rules.webp'));
  root.querySelectorAll?.('.close').forEach(button => setIconOnly(button, 'ui/actions/close.webp'));
  root.querySelectorAll?.('[data-invite-friend]').forEach(button => prependTextIcon(button, 'ui/actions/invite.webp'));

  root.querySelectorAll?.('[data-shell-nav]').forEach(button => {
    const asset = SHELL_NAV_ICONS[String(button.dataset.shellNav || '')];
    const icon = button.querySelector('.app-bottom-nav-icon');
    if (asset && icon) setIconOnly(icon, asset);
  });

  Object.entries(MENU_ICONS).forEach(([id, asset]) => {
    const button = root.querySelector?.(`#${id}`);
    if (button) replaceMenuIcon(button, asset);
  });

  root.querySelectorAll?.('[data-account-orders-shortcut]').forEach(button => {
    const icon = button.querySelector('.account-menu-icon');
    if (icon) setIconOnly(icon, 'ui/navigation/store.webp');
  });

  root.querySelectorAll?.('[data-account-friends-shortcut]').forEach(button => {
    const icon = button.querySelector('.account-menu-icon');
    if (icon) setIconOnly(icon, 'ui/navigation/friends.webp');
  });
}

function assetUrl(asset){
  return `${ICON_ENDPOINT}${encodeURIComponent(asset)}`;
}

function createImage(asset, className = 'shield-king-metal-icon'){
  const image = document.createElement('img');
  image.src = assetUrl(asset);
  image.alt = '';
  image.decoding = 'async';
  image.setAttribute('aria-hidden', 'true');
  image.className = className;
  image.dataset.skAsset = asset;
  return image;
}

function setIconOnly(target, asset){
  if (!(target instanceof HTMLElement)) return;
  const current = target.querySelector(':scope > img[data-sk-asset]');
  if (current?.dataset.skAsset === asset) return;
  target.replaceChildren(createImage(asset));
}

function setLabelIcon(label, asset, text){
  if (!(label instanceof HTMLElement)) return;
  label.replaceChildren(createImage(asset, 'shield-king-label-icon'), document.createTextNode(text));
}

function prependTextIcon(button, asset){
  if (!(button instanceof HTMLElement) || button.querySelector(':scope > .shield-king-button-icon')) return;
  button.prepend(createImage(asset, 'shield-king-button-icon'));
}

function replaceMenuIcon(button, asset){
  if (!(button instanceof HTMLElement) || button.dataset.skMenuIcon === asset) return;
  const label = String(button.textContent || '').replace(/^[^\p{L}\p{N}]+/u, '').trim();
  button.replaceChildren(createImage(asset, 'shield-king-menu-icon'), document.createTextNode(label));
  button.dataset.skMenuIcon = asset;
}
