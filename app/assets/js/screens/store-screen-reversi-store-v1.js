import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.reversi.mvp19-7.v1');
const INSTALL_KEY = '__mgwReversiStoreV1Installed';
const STYLE_MARK = 'mvp19-7-reversi-store-v1';

export function installReversiStorePresentation(){
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

export function upgradeReversiStorePresentation(){
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
  const href = new URL('../../css/games/reversi/store-cosmetics-v1.css?v=1&mvp19_7=store-only', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-reversi-store]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwReversiStore = STYLE_MARK;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwReversiStore = STYLE_MARK;
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
  const run = () => upgradeReversiStorePresentation();
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
  globalThis.setTimeout(run, 0);
  globalThis.setTimeout(run, 80);
}

function renameSelector(root){
  root.querySelectorAll('[data-store-v2-game="reversi"]').forEach(button => {
    if (button instanceof HTMLElement) button.textContent = 'Реверси';
  });
}

function upgradeHeader(root){
  const head = root.querySelector('.store-v2-game-head[data-store-game-type="reversi"]');
  if (!(head instanceof HTMLElement)) return;
  const title = head.querySelector('h2');
  if (title instanceof HTMLElement) title.textContent = 'Реверси';
  const marks = head.querySelectorAll('.store-v2-game-head-marks b');
  marks.forEach((mark, index) => {
    if (!(mark instanceof HTMLElement)) return;
    mark.textContent = '';
    mark.classList.add('mgw-reversi-head-disc', index === 0 ? 'black' : 'white');
    mark.setAttribute('aria-hidden', 'true');
  });
}

function upgradeGroups(root){
  root.querySelectorAll('.store-v2-game-group').forEach(group => {
    if (!(group instanceof HTMLElement)) return;
    const preview = group.querySelector('.store-v2-game-preview[data-game-type="reversi"]');
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const title = group.querySelector('.store-v2-game-title-row h2');
    const subtitle = group.querySelector('.store-v2-game-title-row p');
    const copy = {
      theme:['Поля','Оформление игрового поля Реверси'],
      elements:['Фишки','Внешний вид чёрных и белых фишек'],
      effect:['Эффекты','Один выбранный эффект подчёркивает соответствующее событие хода'],
    }[layer] || ['Реверси','Игровая косметика'];
    if (title instanceof HTMLElement) title.textContent = copy[0];
    if (subtitle instanceof HTMLElement) subtitle.textContent = copy[1];
  });
}

function upgradeProducts(root){
  root.querySelectorAll('.store-v2-game-product[data-store-game-product="reversi"]').forEach(product => {
    if (!(product instanceof HTMLElement)) return;
    const preview = product.querySelector('.store-v2-game-preview[data-game-type="reversi"]');
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = String(preview.dataset.cosmeticVariant || 'green');
    const kind = product.querySelector('.store-v2-game-product-copy > span');
    const description = product.querySelector('.store-v2-game-product-copy > p');
    if (kind instanceof HTMLElement) kind.textContent = layer === 'theme' ? 'Поле Реверси' : (layer === 'elements' ? 'Комплект фишек' : 'Эффект партии');
    if (description instanceof HTMLElement) description.textContent = descriptionFor(layer, variant);
  });
}

function upgradePreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="reversi"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = String(preview.dataset.cosmeticVariant || 'green');
    const signature = `${layer}:${variant}:v1`;
    if (preview.dataset.mgwReversiPreview === signature) return;
    preview.dataset.mgwReversiPreview = signature;
    preview.innerHTML = previewMarkup(layer, variant);
  });
}

function descriptionFor(layer, variant){
  if (layer === 'theme') {
    return ({
      green:'Классическое зелёное поле с чистой контрастной сеткой',
      dark:'Глубокое тёмное поле для спокойной контрастной партии',
      marble:'Светлый мрамор с тонкими каменными прожилками',
      neon:'Тёмная сетка с ярким неоновым свечением',
    })[variant] || 'Меняет оформление поля Реверси';
  }
  if (layer === 'elements') {
    return ({
      classic:'Классические чёрные и белые фишки с объёмной поверхностью',
      marble:'Каменные фишки с мраморной фактурой',
      metal:'Холодный полированный металл с выразительными бликами',
      neon:'Тёмные фишки с яркими неоновыми контурами',
    })[variant] || 'Меняет внешний вид фишек Реверси';
  }
  return ({
    placement:'Импульс расходится от реально установленной фишки',
    line:'Световая линия подчёркивает переворот фишек по направлению хода',
    'mass-flip':'Каскадное свечение охватывает все перевёрнутые фишки хода',
  })[variant] || 'Добавляет визуальный эффект хода';
}

function previewMarkup(layer, variant){
  if (layer === 'theme') return boardMarkup(`theme-${safeVariant(variant)}`, true);
  if (layer === 'elements') return boardMarkup(`pieces-${safeVariant(variant)}`, true, 'pieces');
  return boardMarkup(`effect-${safeVariant(variant)}`, true, 'effect');
}

function boardMarkup(variantClass, withDiscs, mode = 'theme'){
  const center = new Map([[27,'white'],[28,'black'],[35,'black'],[36,'white']]);
  const effectLine = mode === 'effect' ? new Set([19,27,35,43]) : new Set();
  const cells = Array.from({ length:64 }, (_, index) => {
    let disc = center.get(index) || '';
    if (effectLine.has(index)) disc = index === 19 ? 'black placed' : 'white flipping';
    return `<span>${withDiscs && disc ? `<i class="mgw-rv-disc ${disc}"></i>` : ''}</span>`;
  }).join('');
  return `<i class="mgw-reversi-preview ${variantClass} ${mode}" aria-hidden="true"><span class="mgw-rv-board">${cells}</span><b class="mgw-rv-fx-line"></b><em class="mgw-rv-fx-pulse"></em><u class="mgw-rv-fx-burst"></u></i>`;
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}
