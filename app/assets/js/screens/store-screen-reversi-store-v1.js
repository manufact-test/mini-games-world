import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.reversi.mvp19-7.v2');
const INSTALL_KEY = '__mgwReversiStoreV2Installed';
const STYLE_MARK = 'mvp19-7-reversi-store-v2';

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
    upgradeGameSelector(root);
    renameSelector(root);
    upgradeHeader(root);
    upgradeGroups(root);
    upgradeProducts(root);
    upgradePreviews(root);
  });
}

function ensureStyles(){
  const href = new URL('../../css/games/reversi/store-cosmetics-v1.css?v=2&mvp19_7=manual-review-corrective-v2', import.meta.url).href;
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

function upgradeGameSelector(root){
  const selector = root.querySelector('.store-v2-game-selector');
  if (!(selector instanceof HTMLElement)) return;
  selector.dataset.mgwScrollableGames = '1';
  const active = selector.querySelector('.store-v2-game-select.active');
  if (!(active instanceof HTMLElement)) return;
  const gameType = String(active.dataset.storeV2Game || '');
  if (selector.dataset.mgwCenteredGame === gameType) return;
  selector.dataset.mgwCenteredGame = gameType;

  const max = Math.max(0, selector.scrollWidth - selector.clientWidth);
  const wanted = active.offsetLeft - (selector.clientWidth - active.offsetWidth) / 2;
  const left = Math.max(0, Math.min(max, wanted));
  if (Math.abs(selector.scrollLeft - left) < 2) return;

  // A freshly rendered Store selector must land at its remembered active game before paint.
  // Smooth centering here caused the visible center -> side -> center jump after Store refreshes.
  selector.scrollLeft = left;
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
      effect:['Эффекты',''],
    }[layer] || ['Реверси','Игровая косметика'];
    if (title instanceof HTMLElement) title.textContent = copy[0];
    if (subtitle instanceof HTMLElement) {
      if (layer === 'effect') subtitle.remove();
      else subtitle.textContent = copy[1];
    }
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
    const signature = `${layer}:${variant}:v2`;
    if (preview.dataset.mgwReversiPreview === signature) return;
    preview.dataset.mgwReversiPreview = signature;
    preview.innerHTML = previewMarkup(layer, variant);
  });
}

function descriptionFor(layer, variant){
  if (layer === 'theme') {
    return ({
      green:'Спокойное зелёное поле с чёткой контрастной сеткой',
      dark:'Глубокое тёмное поле для спокойной контрастной партии',
      marble:'Светлый камень с мягкой облачной фактурой и спокойной сеткой',
      neon:'Тёмная сетка с ярким неоновым свечением',
    })[variant] || 'Меняет оформление поля Реверси';
  }
  if (layer === 'elements') {
    return ({
      classic:'Турнирные фишки с матовой поверхностью и двойным кантом',
      marble:'Каменные фишки с мягкой минеральной фактурой',
      metal:'Холодный полированный металл с выразительными бликами',
      neon:'Тёмные фишки с яркими неоновыми контурами',
    })[variant] || 'Меняет внешний вид фишек Реверси';
  }
  return ({
    placement:'Световое кольцо появляется вокруг фишки сразу после хода',
    line:'Подсветка последовательно проходит по фишкам, которые переворачиваются по одной линии',
    'mass-flip':'Перевёрнутые фишки по очереди вспыхивают и мягко поднимаются волной',
  })[variant] || 'Добавляет визуальный эффект хода';
}

function previewMarkup(layer, variant){
  if (layer === 'theme') return boardMarkup(`theme-${safeVariant(variant)}`, baseDiscScenario(), 'theme');
  if (layer === 'elements') return boardMarkup(`pieces-${safeVariant(variant)}`, baseDiscScenario(), 'pieces');
  return boardMarkup(`effect-${safeVariant(variant)}`, effectScenario(variant), 'effect');
}

function baseDiscScenario(){
  return new Map([
    [27,{ color:'white' }],
    [28,{ color:'black' }],
    [35,{ color:'black' }],
    [36,{ color:'white' }],
  ]);
}

function effectScenario(variant){
  if (variant === 'placement') {
    return new Map([
      [20,{ color:'black', classes:['placed','fx-placement-target'] }],
      [27,{ color:'white' }],
      [28,{ color:'black' }],
      [35,{ color:'black' }],
      [36,{ color:'white' }],
    ]);
  }
  if (variant === 'line') {
    return new Map([
      [19,{ color:'black', classes:['placed'] }],
      [27,{ color:'white', classes:['fx-line-target'], step:0 }],
      [35,{ color:'white', classes:['fx-line-target'], step:1 }],
      [43,{ color:'white', classes:['fx-line-target'], step:2 }],
      [28,{ color:'black' }],
      [36,{ color:'white' }],
    ]);
  }
  return new Map([
    [28,{ color:'black', classes:['placed'] }],
    [27,{ color:'white', classes:['fx-mass-target'], step:0 }],
    [35,{ color:'white', classes:['fx-mass-target'], step:1 }],
    [36,{ color:'white', classes:['fx-mass-target'], step:2 }],
    [37,{ color:'white', classes:['fx-mass-target'], step:3 }],
    [44,{ color:'white', classes:['fx-mass-target'], step:4 }],
    [20,{ color:'black' }],
    [45,{ color:'black' }],
  ]);
}

function boardMarkup(variantClass, discs, mode){
  const cells = Array.from({ length:64 }, (_, index) => {
    const disc = discs.get(index);
    if (!disc) return '<span></span>';
    const classes = ['mgw-rv-disc', disc.color, ...(disc.classes || [])].join(' ');
    const style = Number.isInteger(disc.step) ? ` style="--fx-step:${disc.step}"` : '';
    return `<span><i class="${classes}"${style}></i></span>`;
  }).join('');
  return `<i class="mgw-reversi-preview ${variantClass} ${mode}" aria-hidden="true"><span class="mgw-rv-board">${cells}</span></i>`;
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}