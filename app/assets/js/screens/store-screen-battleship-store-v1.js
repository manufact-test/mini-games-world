import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.battleship.phase1.v1');
const INSTALL_KEY = '__mgwBattleshipStorePhase1V1Installed';
const STYLE_MARK = 'mvp19-12-battleship-store-phase1-v1';

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
  const href = new URL('../../css/games/battleship/store-cosmetics-v1.css?v=1&mvp19_12=store-phase1&paid_default=distinct-v1', import.meta.url).href;
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
    marks.innerHTML = '<b class="mgw-bs-head-radar" aria-hidden="true"></b><b class="mgw-bs-head-ship" aria-hidden="true"><i></i><i></i><i></i></b>';
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
      theme:['Карты','Оформление боевой карты и воды'],
      elements:['Флот','Внешний вид ваших открытых кораблей'],
      effect:['Эффекты','Визуальные эффекты выстрела, попадания и потопления'],
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
        ? 'Карта боя'
        : (layer === 'elements' ? 'Комплект флота' : effectKind(variant));
    }
    if (description instanceof HTMLElement) description.textContent = descriptionFor(layer, variant);
  });
}

function upgradePreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="battleship"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = safeVariant(preview.dataset.cosmeticVariant || 'sea');
    const signature = `${layer}:${variant}:store-phase1-v1`;
    if (preview.dataset.mgwBattleshipPreview === signature) return;
    preview.dataset.mgwBattleshipPreview = signature;
    preview.dataset.mgwBattleshipPreviewMode = layer === 'effect' ? 'static-concept' : 'static';
    preview.innerHTML = battleshipPreviewMarkup(layer, variant);
  });
}

function effectKind(variant){
  return ({ shot:'Эффект выстрела', hit:'Эффект попадания', destroy:'Эффект потопления' })[variant] || 'Эффект боя';
}

function descriptionFor(layer, variant){
  if (layer === 'theme') {
    return ({
      sea:'Бирюзовая морская карта с ярким фарватером и светлой координатной сеткой — не повторяет бесплатное синее поле',
      'dark-military':'Тёмный тактический радар с оливковыми линиями и военной разметкой',
      storm:'Грозовая карта с холодной сталью, дождевыми бликами и глубокими волнами',
      neon:'Чёрный сектор с цианово-фиолетовой неоновой координатной сеткой',
    })[variant] || 'Меняет оформление карты Морского боя';
  }
  if (layer === 'elements') {
    return ({
      classic:'Светлый адмиральский флот с латунной окантовкой — отдельный платный образ, не серые стандартные корабли',
      modern:'Графитовый современный флот с холодными голубыми панелями',
      armored:'Тяжёлый тёмный металл, бронепластины и яркие стальные кромки',
      neon:'Тёмные корпуса с ярким цианово-розовым свечением',
    })[variant] || 'Меняет внешний вид вашего флота';
  }
  return ({
    shot:'Прицел и световой трассер показывают направление вашего выстрела.',
    hit:'Яркая ударная вспышка подчёркивает точное попадание.',
    destroy:'Потопленный корабль отмечается мощной финальной вспышкой и разломом.',
  })[variant] || 'Добавляет визуальный эффект боя.';
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
  return `<i class="mgw-battleship-preview ${kind} ${layer}" aria-hidden="true"><span class="mgw-bs-preview-board">${cells}</span><b class="mgw-bs-preview-sweep"></b></i>`;
}

function effectPreview(variant){
  const cells = Array.from({ length:100 }, (_, index) => {
    const target = index === 55 ? ' target' : '';
    const ship = (variant === 'destroy' && [54,55,56].includes(index)) ? ' ship' : '';
    return `<span class="${target}${ship}"></span>`;
  }).join('');
  const safe = ['shot','hit','destroy'].includes(variant) ? variant : 'shot';
  return `<i class="mgw-battleship-preview effect effect-${safe}" aria-hidden="true"><span class="mgw-bs-preview-board">${cells}</span><b class="mgw-bs-fx-reticle"></b><b class="mgw-bs-fx-tracer"></b><b class="mgw-bs-fx-burst"></b><b class="mgw-bs-fx-shock"></b></i>`;
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}
