import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.four-in-a-row.live-previews-v1');
const INSTALL_KEY = '__mgwFourInARowStoreStaticV1Installed';
const STYLE_MARK = 'four-store-live-previews-v1';

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
    upgradePurchaseCopy(root);
  });
}

function ensureStyles(){
  const href = new URL('../../css/games/four-in-a-row/store-cosmetics-v1.css?v=5&four_store=live-previews-v1', import.meta.url).href;
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
      effect:['Эффекты','Анимации для ваших ходов и побед'],
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
    const title = product.querySelector('.store-v2-game-product-copy > strong');
    const description = product.querySelector('.store-v2-game-product-copy > p');

    if (kind instanceof HTMLElement) {
      kind.textContent = layer === 'theme'
        ? 'Игровое поле'
        : (layer === 'elements'
          ? 'Комплект фишек'
          : (variant === 'victory-wave' ? 'Эффект победы' : 'Эффект хода'));
    }
    if (title instanceof HTMLElement && layer === 'effect') {
      title.textContent = effectDisplayName(variant);
    }
    if (description instanceof HTMLElement) description.textContent = descriptionFor(layer, variant);
  });
}

function upgradePreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="four_in_a_row"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = safeVariant(preview.dataset.cosmeticVariant || 'blue');
    const signature = `${layer}:${variant}:live-preview-v1`;
    if (preview.dataset.mgwFourPreview === signature) return;
    preview.dataset.mgwFourPreview = signature;
    preview.dataset.mgwFourPreviewMode = layer === 'effect' ? 'animated-live-parity' : 'static';
    preview.innerHTML = fourInARowPreviewMarkup(layer, variant);
  });
}

function descriptionFor(layer, variant){
  if (layer === 'theme') {
    return ({
      blue:'Насыщенное фиолетово-сливовое поле с глубоким тоном и мягким объёмом',
      dark:'Глубокое ночное поле с холодной контрастной сеткой',
      metal:'Стальная рама с холодным матовым металлом и объёмными слотами',
      neon:'Тёмное поле с яркой цианово-фиолетовой неоновой рамой',
    })[variant] || 'Меняет оформление игрового поля';
  }
  if (layer === 'elements') {
    return ({
      classic:'Яркая розово-бирюзовая пара с мягкими бликами и аркадным характером',
      '3d':'Глубокие объёмные фишки с мягким светом и выраженной кромкой',
      metal:'Глубокий бордовый металл и тёплая латунь с объёмным бликом без полос',
      neon:'Яркие розовые и лаймовые фишки с насыщенным светящимся ядром и внешним свечением',
    })[variant] || 'Меняет внешний вид игровых фишек';
  }
  return ({
    drop:'Прицел захватывает клетку, сверху бьёт лазер — и ваша фишка эффектно появляется точно в точке хода.',
    four:'Каждый ваш ход запускает новый рисунок молний: разряды перескакивают по клеткам и каждый раз выглядят немного иначе.',
    'victory-wave':'Победная четвёрка загорается по цепочке, соединяется энергетической линией и заканчивается ярким финальным взрывом.',
  })[variant] || 'Добавляет яркую анимацию в нужный момент партии.';
}

export function fourInARowPreviewMarkup(layer, variant){
  if (layer === 'effect') return effectSceneMarkup(variant);

  const modeClass = layer === 'theme' ? `theme-${variant}` : `pieces-${variant}`;
  return boardMarkup(modeClass, layer);
}

function effectDisplayName(variant){
  return ({
    drop:'Лазерное наведение',
    four:'Энергетический импульс',
    'victory-wave':'Победный овердрайв',
  })[variant] || 'Эффект партии';
}

function upgradePurchaseCopy(root){
  const preview = root.querySelector('.store-v2-confirm-game .store-v2-game-preview[data-game-type="four_in_a_row"][data-cosmetic-layer="effect"]');
  if (!(preview instanceof HTMLElement)) return;
  const variant = safeVariant(preview.dataset.cosmeticVariant || 'drop');
  const title = root.querySelector('.store-v2-confirm-copy strong');
  if (title instanceof HTMLElement) title.textContent = effectDisplayName(variant);
}

function effectSceneMarkup(variant){
  const safe = ['drop','four','victory-wave'].includes(String(variant || '')) ? String(variant) : 'drop';
  const occupied = new Map([
    [24,'red'],[25,'yellow'],[26,'red'],[27,'yellow'],
    [30,'yellow'],[31,'red'],[32,'yellow'],[33,'red'],
  ]);
  if (safe === 'four') occupied.set(17,'red');
  if (safe === 'victory-wave') {
    occupied.set(23,'red');
    occupied.set(24,'red');
    occupied.set(25,'red');
    occupied.set(26,'red');
  }
  const cells = Array.from({ length:35 }, (_, index) => {
    const color = occupied.get(index);
    const extra = safe === 'drop' && index === 17
      ? ' fx-drop-target'
      : (safe === 'four' && index === 17
        ? ' fx-pulse-target'
        : (safe === 'victory-wave' && [23,24,25,26].includes(index) ? ' fx-win' : ''));
    return `<span class="mgw-four-fx-cell${extra}">${color ? `<i class="mgw-four-fx-disc ${color}"></i>` : ''}</span>`;
  }).join('');

  const board = `<span class="mgw-four-fx-preview-board">${cells}</span>`;

  if (safe === 'drop') {
    return `<i class="mgw-four-preview mgw-four-effect-preview effect-drop" aria-hidden="true"><span class="mgw-four-fx-stage">${board}<span class="mgw-four-preview-drop-reticle"><b></b><i></i></span><span class="mgw-four-preview-drop-laser"></span><span class="mgw-four-preview-drop-disc"></span></span></i>`;
  }

  if (safe === 'four') {
    return `<i class="mgw-four-preview mgw-four-effect-preview effect-four" aria-hidden="true"><span class="mgw-four-fx-stage">${board}<svg class="mgw-four-preview-pulse-svg" viewBox="0 0 140 100" preserveAspectRatio="none"><path class="b1" d="M70 50 L52 39 L34 51"></path><path class="b2" d="M70 50 L88 36 L108 44"></path><path class="b3" d="M70 50 L74 72 L98 82"></path><path class="b4" d="M70 50 L47 69 L25 80"></path><path class="b5" d="M70 50 L102 59 L126 68"></path></svg><span class="mgw-four-preview-pulse-core"></span></span></i>`;
  }

  return `<i class="mgw-four-preview mgw-four-effect-preview effect-victory-wave" aria-hidden="true"><span class="mgw-four-fx-stage">${board}<span class="mgw-four-preview-victory-rail"><i></i></span><span class="mgw-four-preview-victory-prism"><b></b></span><span class="mgw-four-preview-victory-blade blade-a"></span><span class="mgw-four-preview-victory-blade blade-b"></span><span class="mgw-four-preview-victory-shards">${Array.from({length:10},(_,index)=>`<i class="s${index + 1}"></i>`).join('')}</span></span></i>`;
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
