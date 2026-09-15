import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.domino.mvp19-9.v1');
const INSTALL_KEY = '__mgwDominoStoreV1Installed';
const STYLE_MARK = 'mvp19-9-domino-store-v4';
let storeRenderObserver = null;
let storeRenderRepairQueued = false;

export function installDominoStorePresentation(){
  ensureStyles();
  installApiHooks();
  installStoreRenderObserver();
  if (globalThis[INSTALL_KEY]) return;
  globalThis[INSTALL_KEY] = true;
  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (!target.closest('[data-store-v2-tab="games"], [data-store-v2-game], [data-store-v2-buy], [data-store-v2-equip], [data-store-v2-unequip], #storeV2ConfirmBuy')) return;
    scheduleUpgrade();
  });
}

export function upgradeDominoStorePresentation(){
  ensureStyles();
  installStoreRenderObserver();
  const roots = storeRoots();
  roots.forEach(root => {
    renameSelector(root);
    upgradeHeader(root);
    upgradeGroups(root);
    upgradeProducts(root);
    upgradePreviews(root);
  });
}

export function dominoPreviewMarkup(layer, variant){
  const normalizedLayer = String(layer || 'theme');
  const normalizedVariant = safeVariant(variant || (normalizedLayer === 'elements' ? 'ivory' : (normalizedLayer === 'effect' ? 'precision-drop' : 'felt')));
  const modeClass = dominoModeClass(normalizedLayer, normalizedVariant);
  return `<i class="mgw-domino-preview ${modeClass}" aria-hidden="true">${tableMarkup(normalizedLayer, normalizedVariant)}</i>`;
}

function ensureStyles(){
  const href = new URL('../../css/games/domino/store-cosmetics-v1.css?v=4&mvp19_9=domino-uniform-fullfield-v4', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-store]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoStore = STYLE_MARK;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoStore = STYLE_MARK;
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

function storeRoots(){
  return [
    document.querySelector('[data-store-v2-panel="games"]'),
    document.getElementById('sheet'),
  ].filter(root => root instanceof HTMLElement);
}

function observerHosts(){
  return [
    document.getElementById('storeTabSurface'),
    document.getElementById('sheet'),
  ].filter(root => root instanceof HTMLElement);
}

function installStoreRenderObserver(){
  if (typeof globalThis.MutationObserver !== 'function') return;
  if (!storeRenderObserver) {
    storeRenderObserver = new globalThis.MutationObserver(() => {
      queueObservedRepair();
    });
  }
  observerHosts().forEach(host => {
    if (host.dataset.mgwDominoStoreObserved === '1') return;
    host.dataset.mgwDominoStoreObserved = '1';
    storeRenderObserver.observe(host, { childList:true, subtree:true });
  });
}

function queueObservedRepair(){
  if (storeRenderRepairQueued || !hasDominoRepairNeed()) return;
  storeRenderRepairQueued = true;
  queueMicrotask(() => {
    storeRenderRepairQueued = false;
    if (hasDominoRepairNeed()) upgradeDominoStorePresentation();
  });
}

function hasDominoRepairNeed(){
  for (const root of storeRoots()) {
    const previews = root.querySelectorAll('.store-v2-game-preview[data-game-type="domino"]');
    for (const preview of previews) {
      if (!(preview instanceof HTMLElement)) continue;
      const layer = String(preview.dataset.cosmeticLayer || 'theme');
      const variant = String(preview.dataset.cosmeticVariant || 'felt');
      const signature = dominoPreviewSignature(layer, variant);
      const visual = preview.querySelector(':scope > .mgw-domino-preview');
      if (preview.dataset.mgwDominoPreview !== signature) return true;
      if (!(visual instanceof HTMLElement) || !visual.classList.contains(dominoModeClass(layer, variant))) return true;
    }
  }
  return false;
}

function scheduleUpgrade(){
  const run = () => upgradeDominoStorePresentation();
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
}

function renameSelector(root){
  root.querySelectorAll('[data-store-v2-game="domino"]').forEach(button => {
    if (button instanceof HTMLElement) button.textContent = 'Домино';
  });
}

function upgradeHeader(root){
  const head = root.querySelector('.store-v2-game-head[data-store-game-type="domino"]');
  if (!(head instanceof HTMLElement)) return;
  const title = head.querySelector('h2');
  if (title instanceof HTMLElement) title.textContent = 'Домино';
  const marks = head.querySelectorAll('.store-v2-game-head-marks b');
  marks.forEach((mark, index) => {
    if (!(mark instanceof HTMLElement)) return;
    mark.textContent = '';
    mark.classList.add('mgw-domino-head-tile', index === 0 ? 'light' : 'dark');
    mark.setAttribute('aria-hidden', 'true');
    mark.innerHTML = headBackMarkup(index === 0 ? 'light' : 'dark');
  });
}

function upgradeGroups(root){
  root.querySelectorAll('.store-v2-game-group').forEach(group => {
    if (!(group instanceof HTMLElement)) return;
    const preview = group.querySelector('.store-v2-game-preview[data-game-type="domino"]');
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const title = group.querySelector('.store-v2-game-title-row h2');
    const subtitle = group.querySelector('.store-v2-game-title-row p');
    const copy = {
      theme:['Столы','Оформление игрового стола'],
      elements:['Костяшки','Комплект костяшек домино'],
      effect:['Эффекты',''],
    }[layer] || ['Домино','Игровая косметика'];
    if (title instanceof HTMLElement) title.textContent = copy[0];
    if (subtitle instanceof HTMLElement) {
      if (layer === 'effect') subtitle.remove();
      else subtitle.textContent = copy[1];
    }
  });
}

function upgradeProducts(root){
  root.querySelectorAll('.store-v2-game-product[data-store-game-product="domino"]').forEach(product => {
    if (!(product instanceof HTMLElement)) return;
    const preview = product.querySelector('.store-v2-game-preview[data-game-type="domino"]');
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = String(preview.dataset.cosmeticVariant || 'felt');
    const kind = product.querySelector('.store-v2-game-product-copy > span');
    const description = product.querySelector('.store-v2-game-product-copy > p');
    if (kind instanceof HTMLElement) kind.textContent = layer === 'theme' ? 'Игровой стол' : (layer === 'elements' ? 'Комплект костяшек' : 'Эффект партии');
    if (description instanceof HTMLElement) description.textContent = descriptionFor(layer, variant);
  });
}

function upgradePreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="domino"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = String(preview.dataset.cosmeticVariant || 'felt');
    const signature = dominoPreviewSignature(layer, variant);
    const visual = preview.querySelector(':scope > .mgw-domino-preview');
    const visualMatches = visual instanceof HTMLElement && visual.classList.contains(dominoModeClass(layer, variant));
    if (preview.dataset.mgwDominoPreview === signature && visualMatches) return;
    preview.dataset.mgwDominoPreview = signature;
    preview.innerHTML = dominoPreviewMarkup(layer, variant);
  });
}

function dominoPreviewSignature(layer, variant){
  return `${String(layer || 'theme')}:${safeVariant(variant || 'felt')}:8x5:v5-stable-rerender`;
}

function dominoModeClass(layer, variant){
  const normalizedLayer = String(layer || 'theme');
  const normalizedVariant = safeVariant(variant || (normalizedLayer === 'elements' ? 'ivory' : (normalizedLayer === 'effect' ? 'precision-drop' : 'felt')));
  return normalizedLayer === 'theme'
    ? `theme-${normalizedVariant}`
    : (normalizedLayer === 'elements' ? `tiles-${normalizedVariant}` : `effect-${normalizedVariant}`);
}

function descriptionFor(layer, variant){
  if (layer === 'theme') {
    return ({
      felt:'Классический зелёный суконный стол с мягкой глубиной и тёплой кромкой',
      midnight:'Тёмно-синий стол с холодной подсветкой и спокойным клубным настроением',
      walnut:'Тёплый ореховый стол с цельной древесной игровой поверхностью и живой фактурой',
      neon:'Глубокий тёмный стол с цианово-фиолетовой неоновой кромкой',
    })[variant] || 'Меняет оформление игрового стола';
  }
  if (layer === 'elements') {
    return ({
      ivory:'Светлые костяшки классической игровой формы с глубокими контрастными точками',
      ebony:'Чёрные матовые костяшки классической формы со светлыми точками',
      marble:'Мраморные костяшки с натуральной минеральной фактурой и чёткими точками',
      neon:'Тёмные костяшки с яркими неоновыми точками и тонким контуром',
    })[variant] || 'Меняет внешний вид костяшек';
  }
  return ({
    'precision-drop':'Костяшка переворачивается в полёте и точно защёлкивается к подходящему концу цепи коротким световым щелчком',
    'stock-pulse':'Запас быстро перетасовывается, после чего одна костяшка выскальзывает из стопки и переворачивается к столу',
    'chain-finale':'По всей цепочке проходит последовательная волна падения: костяшки одна за другой наклоняются и вспыхивают точками',
  })[variant] || 'Добавляет визуальный эффект партии';
}

function tableMarkup(layer, variant){
  const chain = effectChain(layer, variant);
  const showStock = layer === 'effect' && variant === 'stock-pulse';
  const stock = showStock
    ? '<span class="mgw-domino-preview-top"><span class="mgw-domino-stock fx-stock"><i></i><i></i><i></i></span></span>'
    : '<span class="mgw-domino-preview-top"><span class="mgw-domino-stock"><i></i><i></i></span></span>';
  const drawGhost = showStock
    ? `<span class="mgw-domino-draw-ghost">${tileMarkup(2,5,'ghost')}</span>`
    : '';
  const snapMarks = layer === 'effect' && variant === 'precision-drop'
    ? '<span class="mgw-domino-snap-marks"><i></i><i></i><i></i></span>'
    : '';
  const finaleBars = layer === 'effect' && variant === 'chain-finale'
    ? '<span class="mgw-domino-finale-bars"><i></i><i></i><i></i><i></i></span>'
    : '';
  return `<span class="mgw-domino-preview-table">${stock}<span class="mgw-domino-preview-chain">${chain}</span>${drawGhost}${snapMarks}${finaleBars}<span class="mgw-domino-table-glow"></span></span>`;
}

function effectChain(layer, variant){
  const values = layer === 'effect'
    ? [[6,3],[3,4],[4,4],[4,1]]
    : [[6,3],[3,5],[5,2]];
  return values.map((pair, index) => {
    const classes = [];
    if (layer === 'effect' && pair[0] === pair[1]) classes.push('turn', 'is-double');
    if (layer === 'effect' && variant === 'precision-drop' && index === values.length - 1) classes.push('fx-precision-target');
    if (layer === 'effect' && variant === 'chain-finale') classes.push('fx-finale');
    const step = layer === 'effect' && variant === 'chain-finale' ? ` style="--fx-step:${index}"` : '';
    return `<span class="mgw-domino-preview-slot ${classes.join(' ')}"${step}>${tileMarkup(pair[0], pair[1])}</span>`;
  }).join('');
}

function headBackMarkup(tone){
  return `<span class="mgw-domino-head-back ${safeVariant(tone)}"><i></i><i></i></span>`;
}

function tileMarkup(a, b, extraClass = ''){
  return `<span class="mgw-domino-preview-tile${extraClass ? ` ${extraClass}` : ''}">${halfMarkup(a)}${halfMarkup(b)}</span>`;
}

function halfMarkup(value){
  const active = new Set(pipPositions(value));
  return `<span class="mgw-domino-preview-half">${Array.from({length:9}, (_, index) => `<i class="${active.has(index + 1) ? 'active' : ''}"></i>`).join('')}</span>`;
}

function pipPositions(value){
  return ({0:[],1:[5],2:[1,9],3:[1,5,9],4:[1,3,7,9],5:[1,3,5,7,9],6:[1,3,4,6,7,9]})[Number(value)] || [];
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'felt';
}
