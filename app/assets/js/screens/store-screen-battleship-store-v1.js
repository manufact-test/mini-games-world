import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.battleship.corrective.v2');
const INSTALL_KEY = '__mgwBattleshipStoreCorrectiveV2Installed';
const STYLE_MARK = 'mvp19-12-battleship-store-corrective-v2';

export function installBattleshipStorePresentation(){
  ensureStyles();
  installApiHooks();
  if (globalThis[INSTALL_KEY]) return;
  globalThis[INSTALL_KEY] = true;

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (!target.closest('[data-store-v2-tab="games"], [data-store-v2-game], [data-store-v2-buy], [data-store-v2-equip], [data-store-v2-unequip], #storeV2ConfirmBuy')) return;
    scheduleUpgrade();
  });
}

export function upgradeBattleshipStorePresentation(){
  ensureStyles();
  const roots = [
    document.querySelector('[data-store-v2-panel="games"]'),
    document.getElementById('sheet'),
  ];
  roots.forEach(root => {
    if (!(root instanceof HTMLElement)) return;
    renameSelector(root);
    upgradeHeader(root);
    upgradeGroups(root);
    upgradeProducts(root);
    upgradePreviews(root);
  });
}

function ensureStyles(){
  const href = new URL('../../css/games/battleship/store-cosmetics-v1.css?v=2&mvp19_12=manual-corrective-v2&geometry=square&copy=human&effects=distinct', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-battleship-store]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwBattleshipStore = STYLE_MARK;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwBattleshipStore = STYLE_MARK;
  link.href = href;
  document.head.appendChild(link);
}

function installApiHooks(){
  ['cosmeticStoreStatus','cosmeticStorePurchase','cosmeticStoreEquip','cosmeticStoreUnequip'].forEach(methodName => {
    const current = api?.[methodName];
    if (typeof current !== 'function' || current[API_HOOK]) return;
    const wrapped = async (...args) => {
      try {
        return await current.apply(api, args);
      } finally {
        scheduleUpgrade();
      }
    };
    Object.defineProperty(wrapped, API_HOOK, { value:true });
    api[methodName] = wrapped;
  });
}

function scheduleUpgrade(){
  const run = () => upgradeBattleshipStorePresentation();
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
  globalThis.setTimeout(run, 0);
  globalThis.setTimeout(run, 80);
}

function renameSelector(root){
  root.querySelectorAll('[data-store-v2-game="battleship"]').forEach(button => {
    if (button instanceof HTMLElement) button.textContent = 'Морской бой';
  });
}

function upgradeHeader(root){
  const head = root.querySelector('.store-v2-game-head[data-store-game-type="battleship"]');
  if (!(head instanceof HTMLElement)) return;

  const title = head.querySelector('h2');
  if (title instanceof HTMLElement) title.textContent = 'Морской бой';

  const marks = head.querySelector('.store-v2-game-head-marks');
  if (marks instanceof HTMLElement) {
    marks.innerHTML = `
      <b class="mgw-bs-head-vessel" aria-hidden="true">
        <svg viewBox="0 0 92 48" focusable="false">
          <path class="hull" d="M12 29h67l-8 11H25L12 29Z"></path>
          <path class="deck" d="M28 29V18h29v11M39 18V10h9v8"></path>
          <path class="mast" d="M43.5 10V5"></path>
          <circle class="port" cx="34" cy="34" r="2"></circle>
          <circle class="port" cx="45" cy="34" r="2"></circle>
          <circle class="port" cx="56" cy="34" r="2"></circle>
          <path class="wake" d="M18 44c12 2 22 2 34 0 11-2 20-2 28 0"></path>
        </svg>
      </b>
    `;
  }
}

function upgradeGroups(root){
  root.querySelectorAll('.store-v2-game-group').forEach(group => {
    if (!(group instanceof HTMLElement)) return;
    const preview = group.querySelector('.store-v2-game-preview[data-game-type="battleship"]');
    if (!(preview instanceof HTMLElement)) return;

    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const title = group.querySelector('.store-v2-game-title-row h2');
    const subtitle = group.querySelector('.store-v2-game-title-row p');
    const copy = {
      theme:['Карты','Стиль боевой карты'],
      elements:['Флот','Внешний вид кораблей'],
      effect:['Эффекты','Прицел, попадание и потопление'],
    }[layer] || ['Морской бой',''];

    if (title instanceof HTMLElement) title.textContent = copy[0];
    if (subtitle instanceof HTMLElement) {
      if (copy[1]) subtitle.textContent = copy[1];
      else subtitle.remove();
    }
  });
}

function upgradeProducts(root){
  root.querySelectorAll('.store-v2-game-product[data-store-game-product="battleship"]').forEach(product => {
    if (!(product instanceof HTMLElement)) return;

    const preview = product.querySelector('.store-v2-game-preview[data-game-type="battleship"]');
    if (!(preview instanceof HTMLElement)) return;

    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = safeVariant(preview.dataset.cosmeticVariant || 'sea');
    const kind = product.querySelector('.store-v2-game-product-copy > span');
    const description = product.querySelector('.store-v2-game-product-copy > p');

    if (kind instanceof HTMLElement) {
      kind.textContent = layer === 'theme'
        ? 'Карта'
        : (layer === 'elements' ? 'Флот' : effectKind(variant));
    }
    if (description instanceof HTMLElement) description.textContent = descriptionFor(layer, variant);
  });
}

function upgradePreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="battleship"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = safeVariant(preview.dataset.cosmeticVariant || 'sea');
    const signature = `${layer}:${variant}:manual-corrective-v2`;

    if (preview.dataset.mgwBattleshipPreview === signature) return;
    preview.dataset.mgwBattleshipPreview = signature;
    preview.dataset.mgwBattleshipPreviewMode = layer === 'effect' ? 'animated-concept' : 'static';
    preview.innerHTML = battleshipPreviewMarkup(layer, variant);
  });
}

function effectKind(variant){
  return ({
    shot:'Прицел',
    hit:'Попадание',
    destroy:'Потопление',
  })[variant] || 'Эффект';
}

function descriptionFor(layer, variant){
  if (layer === 'theme') {
    return ({
      sea:'Бирюзовая вода, светлый фарватер и свежий морской стиль.',
      'dark-military':'Тёмный тактический радар с военным характером.',
      storm:'Глубокое море, дождь и холодные штормовые блики.',
      neon:'Цельная тёмная карта с ярким циановым неоновым свечением.',
    })[variant] || 'Новый стиль для боевой карты.';
  }

  if (layer === 'elements') {
    return ({
      classic:'Светлые корпуса с тёплой латунной отделкой.',
      modern:'Графитовые корабли с холодными голубыми панелями.',
      armored:'Тяжёлые бронекорпуса из тёмного металла.',
      neon:'Тёмный флот с ярким цианово-розовым контуром.',
    })[variant] || 'Новый внешний вид вашего флота.';
  }

  return ({
    shot:'Прицел наводится на клетку, затем проходит короткий световой выстрел.',
    hit:'Точное попадание вспыхивает и расходится ударным кольцом.',
    destroy:'Потопленный корабль накрывает большая вспышка и красная ударная волна.',
  })[variant] || 'Яркий эффект для боя.';
}

export function battleshipPreviewMarkup(layer, variant){
  const safeLayer = ['theme','elements','effect'].includes(String(layer)) ? String(layer) : 'theme';
  const safe = safeVariant(variant);
  if (safeLayer === 'effect') return effectPreview(safe);
  return boardPreview(safeLayer, safe);
}

function boardPreview(layer, variant){
  const ships = new Set([22,23,24,25,47,57,67,72,73,88]);
  const cells = Array.from({ length:100 }, (_, index) => {
    const ship = ships.has(index);
    return `<span class="${ship ? 'ship' : ''}"></span>`;
  }).join('');

  const kind = layer === 'theme' ? `map-${variant}` : `fleet-${variant}`;
  return `
    <i class="mgw-battleship-preview ${kind} ${layer}" aria-hidden="true">
      <span class="mgw-bs-preview-board">${cells}</span>
      <b class="mgw-bs-preview-sweep"></b>
    </i>
  `;
}

function effectPreview(variant){
  const safe = ['shot','hit','destroy'].includes(variant) ? variant : 'shot';
  const cells = Array.from({ length:100 }, (_, index) => {
    const target = index === 55 ? ' target' : '';
    let ship = '';
    if (safe === 'hit' && index === 55) ship = ' ship hit-ship';
    if (safe === 'destroy' && [54,55,56].includes(index)) ship = ' ship destroyed-ship';
    return `<span class="${target}${ship}"></span>`;
  }).join('');

  return `
    <i class="mgw-battleship-preview effect effect-${safe}" aria-hidden="true">
      <span class="mgw-bs-preview-board">${cells}</span>
      <b class="mgw-bs-fx-reticle"></b>
      <b class="mgw-bs-fx-tracer"></b>
      <b class="mgw-bs-fx-burst"></b>
      <b class="mgw-bs-fx-shock"></b>
      <b class="mgw-bs-fx-smoke"></b>
      <span class="mgw-bs-fx-shards"><i></i><i></i><i></i><i></i></span>
    </i>
  `;
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}
