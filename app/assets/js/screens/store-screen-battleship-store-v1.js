import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.battleship.preview-parity.v6');
const INSTALL_KEY = '__mgwBattleshipStorePreviewParityV6Installed';
const STYLE_MARK = 'mvp19-12-battleship-store-preview-parity-v6';

export function installBattleshipStorePresentation(){
  ensureStyles();
  installApiHooks();
  installHydrationRepair();
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
  const href = new URL('../../css/games/battleship/store-cosmetics-v1.css?v=6&mvp19_12=store-preview-parity-v6&header=steel-ship&neon_frame=outer-safe&neon_fleet=tube-v4&preview_geometry=square-grid-v2&hydration=observer-v1&effects=unchanged-v2', import.meta.url).href;
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

let hydrationObserver = null;
let hydrationUpgradeQueued = false;

function installHydrationRepair(){
  if (hydrationObserver instanceof MutationObserver || typeof MutationObserver === 'undefined') return;
  const root = document.documentElement;
  if (!(root instanceof HTMLElement)) return;

  hydrationObserver = new MutationObserver(records => {
    let relevant = false;
    for (const record of records) {
      const target = record.target instanceof Element ? record.target : null;
      if (target?.closest?.('.store-v2-game-preview[data-game-type="battleship"]')) {
        relevant = true;
        break;
      }
      for (const node of record.addedNodes) {
        if (!(node instanceof Element)) continue;
        if (
          node.matches?.('.store-v2-game-preview[data-game-type="battleship"], [data-store-v2-game-product="battleship"], [data-store-v2-game="battleship"]')
          || node.querySelector?.('.store-v2-game-preview[data-game-type="battleship"], [data-store-v2-game-product="battleship"], [data-store-v2-game="battleship"]')
        ) {
          relevant = true;
          break;
        }
      }
      if (relevant) break;
    }
    if (!relevant || hydrationUpgradeQueued) return;
    hydrationUpgradeQueued = true;
    queueMicrotask(() => {
      hydrationUpgradeQueued = false;
      upgradeBattleshipStorePresentation();
    });
  });
  hydrationObserver.observe(root, { childList:true, subtree:true });
}

function scheduleUpgrade(){
  const run = () => upgradeBattleshipStorePresentation();
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
  globalThis.setTimeout(run, 0);
  globalThis.setTimeout(run, 80);
  globalThis.setTimeout(run, 240);
  globalThis.setTimeout(run, 700);
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
          <defs>
            <linearGradient id="mgwBsHullSteel" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stop-color="#d7dde5"></stop>
              <stop offset=".48" stop-color="#84909e"></stop>
              <stop offset="1" stop-color="#3b4551"></stop>
            </linearGradient>
            <linearGradient id="mgwBsCabinSteel" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#f0f3f6"></stop>
              <stop offset=".58" stop-color="#98a3ae"></stop>
              <stop offset="1" stop-color="#58636f"></stop>
            </linearGradient>
          </defs>
          <path class="hull" d="M14 29h64l-9 10H26L14 29Z"></path>
          <path class="hull-highlight" d="M20 29h52"></path>
          <rect class="cabin" x="31" y="18" width="28" height="10" rx="2.5"></rect>
          <rect class="bridge" x="40" y="12" width="11" height="6" rx="1.5"></rect>
          <path class="mast" d="M45.5 12V7"></path>
          <circle class="port" cx="35" cy="23" r="1.4"></circle>
          <circle class="port" cx="42" cy="23" r="1.4"></circle>
          <circle class="port" cx="49" cy="23" r="1.4"></circle>
          <path class="wake" d="M22 42c10 1.5 18 1.5 28 0 9-1.4 17-1.4 24 0"></path>
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
    const signature = `${layer}:${variant}:store-preview-parity-v6`;

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
