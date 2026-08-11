const ICON_ENDPOINT = './assets/shield-king-icon.php?v=bcb098b7&asset=';

const MENU_ICONS = {
  rulesBtn:'ui/actions/rules.webp',
  feedbackBtn:'ui/status/info.webp',
  ideaBtn:'ui/status/success.webp',
  supportBtn:'ui/status/warning.webp',
  balanceHistoryBtn:'ui/navigation/history.webp',
  matchHistoryBtn:'ui/navigation/games.webp',
};

export function initShieldKingVisuals(){
  setIconOnly(document.getElementById('notificationsOpen'), 'ui/navigation/notifications.webp');
  setIconOnly(document.getElementById('moreMenuOpen'), 'ui/actions/more.webp');

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

  Object.entries(MENU_ICONS).forEach(([id, asset]) => {
    const button = root.querySelector?.(`#${id}`);
    if (button) replaceMenuIcon(button, asset);
  });

  root.querySelectorAll?.('[data-account-orders-shortcut]').forEach(button => {
    const icon = button.querySelector('.account-menu-icon');
    if (icon) setIconOnly(icon, 'ui/navigation/store.webp');
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
  return image;
}

function setIconOnly(target, asset){
  if (!(target instanceof HTMLElement)) return;
  const current = target.querySelector(':scope > img[data-sk-asset]');
  if (current?.dataset.skAsset === asset) return;
  const image = createImage(asset);
  image.dataset.skAsset = asset;
  target.replaceChildren(image);
}

function setLabelIcon(label, asset, text){
  if (!(label instanceof HTMLElement)) return;
  const image = createImage(asset, 'shield-king-label-icon');
  image.dataset.skAsset = asset;
  label.replaceChildren(image, document.createTextNode(text));
}

function prependTextIcon(button, asset){
  if (!(button instanceof HTMLElement) || button.querySelector(':scope > .shield-king-button-icon')) return;
  const image = createImage(asset, 'shield-king-button-icon');
  image.dataset.skAsset = asset;
  button.prepend(image);
}

function replaceMenuIcon(button, asset){
  if (!(button instanceof HTMLElement) || button.dataset.skMenuIcon === asset) return;
  const label = String(button.textContent || '').replace(/^[^\p{L}\p{N}]+/u, '').trim();
  const image = createImage(asset, 'shield-king-menu-icon');
  image.dataset.skAsset = asset;
  button.replaceChildren(image, document.createTextNode(label));
  button.dataset.skMenuIcon = asset;
}
