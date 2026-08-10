const NAV_SPRITE = './assets/icons/shield-king/navigation-icons.svg';
const ECONOMY_SPRITE = './assets/icons/shield-king/economy-icons.svg';

export function initShieldKingVisuals(){
  setButtonIcon(document.getElementById('notificationsOpen'), NAV_SPRITE, 'notifications');
  setButtonIcon(document.getElementById('moreMenuOpen'), './assets/icons/shield-king/action-icons.svg', 'more');

  document.querySelectorAll('.game-rules-button').forEach(button => {
    setButtonIcon(button, NAV_SPRITE, 'rules');
  });

  const balances = document.querySelectorAll('.balance-card .balance-label');
  setLabelIcon(balances[0], ECONOMY_SPRITE, 'coins', 'Матч-комната');
  setLabelIcon(balances[1], ECONOMY_SPRITE, 'gold', 'Gold-комната');
}

function setButtonIcon(button, sprite, symbol){
  if (!(button instanceof HTMLElement)) return;
  button.replaceChildren(createIcon(sprite, symbol));
}

function setLabelIcon(label, sprite, symbol, text){
  if (!(label instanceof HTMLElement)) return;
  const icon = createIcon(sprite, symbol);
  icon.classList.add('shield-king-label-icon');
  label.replaceChildren(icon, document.createTextNode(text));
}

function createIcon(sprite, symbol){
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  use.setAttribute('href', `${sprite}#${symbol}`);
  svg.appendChild(use);
  return svg;
}
