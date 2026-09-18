import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.four-in-a-row.store-static-v3');
const INSTALL_KEY = '__mgwFourInARowStoreStaticV1Installed';
const STYLE_MARK = 'four-store-static-v3';

const EFFECT_ASSETS = Object.freeze({
  drop:new URL('../../media/cosmetics/four-in-a-row/effects/drop-v1.svg', import.meta.url).href,
  four:new URL('../../media/cosmetics/four-in-a-row/effects/four-v1.svg', import.meta.url).href,
  'victory-wave':new URL('../../media/cosmetics/four-in-a-row/effects/victory-wave-v1.svg', import.meta.url).href,
});

export function installFourInARowStorePresentation(){
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

export function upgradeFourInARowStorePresentation(){
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
  const href = new URL('../../css/games/four-in-a-row/store-cosmetics-v1.css?v=1&four_store=static-v3', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-four-store]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwFourStore = STYLE_MARK;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwFourStore = STYLE_MARK;
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
  const run = () => upgradeFourInARowStorePresentation();
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
  globalThis.setTimeout(run, 0);
  globalThis.setTimeout(run, 80);
}

function renameSelector(root){
  root.querySelectorAll('[data-store-v2-game="four_in_a_row"]').forEach(button => {
    if (button instanceof HTMLElement) button.textContent = '4 в ряд';
  });
}

function upgradeHeader(root){
  const head = root.querySelector('.store-v2-game-head[data-store-game-type="four_in_a_row"]');
  if (!(head instanceof HTMLElement)) return;

  const title = head.querySelector('h2');
  if (title instanceof HTMLElement) title.textContent = '4 в ряд';

  const marks = head.querySelector('.store-v2-game-head-marks');
  if (marks instanceof HTMLElement) {
    marks.innerHTML = '<b class="mgw-four-head-disc red" aria-hidden="true"></b><b class="mgw-four-head-disc yellow" aria-hidden="true"></b>';
  }
}

function upgradeGroups(root){
  root.querySelectorAll('.store-v2-game-group').forEach(group => {
    if (!(group instanceof HTMLElement)) return;
    const preview = group.querySelector('.store-v2-game-preview[data-game-type="four_in_a_row"]');
    if (!(preview instanceof HTMLElement)) return;

    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const title = group.querySelector('.store-v2-game-title-row h2');
    const subtitle = group.querySelector('.store-v2-game-title-row p');
    const copy = {
      theme:['Поля','Оформление игрового поля'],
      elements:['Фишки','Внешний вид красных и жёлтых фишек'],
      effect:['Эффекты',''],
    }[layer] || ['4 в ряд',''];

    if (title instanceof HTMLElement) title.textContent = copy[0];
    if (subtitle instanceof HTMLElement) {
      if (copy[1]) subtitle.textContent = copy[1];
      else subtitle.remove();
    }
  });
}

function upgradeProducts(root){
  root.querySelectorAll('.store-v2-game-product[data-store-game-product="four_in_a_row"]').forEach(product => {
    if (!(product instanceof HTMLElement)) return;
    const preview = product.querySelector('.store-v2-game-preview[data-game-type="four_in_a_row"]');
    if (!(preview instanceof HTMLElement)) return;

    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = String(preview.dataset.cosmeticVariant || 'blue');
    const kind = product.querySelector('.store-v2-game-product-copy > span');
    const description = product.querySelector('.store-v2-game-product-copy > p');

    if (kind instanceof HTMLElement) {
      kind.textContent = layer === 'theme' ? 'Игровое поле' : (layer === 'elements' ? 'Комплект фишек' : 'Эффект партии');
    }
    if (description instanceof HTMLElement) description.textContent = descriptionFor(layer, variant);
  });
}

function upgradePreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="four_in_a_row"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = safeVariant(preview.dataset.cosmeticVariant || 'blue');
    const signature = `${layer}:${variant}:static-v3`;
    if (preview.dataset.mgwFourPreview === signature) return;
    preview.dataset.mgwFourPreview = signature;
    preview.dataset.mgwFourPreviewMode = layer === 'effect' ? 'static-concept' : 'static';
    preview.innerHTML = previewMarkup(layer, variant);
  });
}

function descriptionFor(layer, variant){
  if (layer === 'theme') {
    return ({
      blue:'Насыщенное фиолетово-сливовое поле вместо стандартной синей рамы',
      dark:'Глубокое ночное поле с холодной контрастной сеткой',
      metal:'Стальная рама с холодным матовым металлом и объёмными слотами',
      neon:'Тёмное поле с яркой цианово-фиолетовой неоновой рамой',
    })[variant] || 'Меняет оформление игрового поля';
  }
  if (layer === 'elements') {
    return ({
      classic:'Яркая розово-бирюзовая пара вместо стандартных красных и жёлтых фишек',
      '3d':'Глубокие объёмные фишки с мягким светом и выраженной кромкой',
      metal:'Глубокий бордовый металл и тёплая латунь с объёмным бликом без полос',
      neon:'Яркие розовые и лаймовые фишки с насыщенным светящимся ядром и внешним свечением',
    })[variant] || 'Меняет внешний вид игровых фишек';
  }
  return ({
    drop:'При падении фишка оставит короткий световой след, а в точке посадки разойдётся компактное ударное кольцо',
    four:'Четыре победные фишки последовательно зажгутся и соединятся одной яркой энергетической линией',
    'victory-wave':'От собранной четвёрки по всему полю разойдутся две широкие победные волны с финальным световым акцентом',
  })[variant] || 'Будущий визуальный эффект партии';
}

function previewMarkup(layer, variant){
  if (layer === 'effect') {
    const src = EFFECT_ASSETS[variant] || EFFECT_ASSETS.drop;
    return `<i class="mgw-four-preview mgw-four-effect-static effect-${variant}" aria-hidden="true"><img src="${escapeAttr(src)}" alt="" loading="eager" decoding="async" draggable="false"></i>`;
  }

  const modeClass = layer === 'theme' ? `theme-${variant}` : `pieces-${variant}`;
  return boardMarkup(modeClass, layer);
}

function boardMarkup(modeClass, layer){
  const occupied = new Map([
    [34,'yellow'],[35,'red'],[36,'yellow'],[37,'red'],[38,'yellow'],
    [28,'red'],[29,'yellow'],[30,'red'],[31,'yellow'],
    [23,'yellow'],[24,'red'],[25,'yellow'],
    [17,'red'],[18,'yellow'],
  ]);
  const cells = Array.from({ length:42 }, (_, index) => {
    const color = occupied.get(index);
    return `<span class="mgw-four-cell">${color ? `<i class="mgw-four-disc ${color} ${layer === 'elements' ? modeClass : 'pieces-classic'}"></i>` : ''}</span>`;
  }).join('');
  return `<i class="mgw-four-preview mgw-four-board-preview ${modeClass}" aria-hidden="true"><span class="mgw-four-board">${cells}</span></i>`;
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}

function escapeAttr(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('"','&quot;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;');
}
