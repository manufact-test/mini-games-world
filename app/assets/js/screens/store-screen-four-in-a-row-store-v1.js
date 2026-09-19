import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.four-in-a-row.live-previews-v3');
const INSTALL_KEY = '__mgwFourInARowStoreStaticV1Installed';
const STYLE_MARK = 'four-store-live-previews-v3';

export function installFourInARowStorePresentation(){
  ensureStyles();
  installApiHooks();
  if (globalThis[INSTALL_KEY]) return;
  globalThis[INSTALL_KEY] = true;

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (!target.closest('[data-store-v2-tab], [data-store-v2-game], [data-store-v2-bundle-game], [data-store-v2-buy], [data-store-v2-equip], [data-store-v2-unequip], #storeV2ConfirmBuy')) return;
    scheduleUpgrade();
  });
}

export function upgradeFourInARowStorePresentation(){
  ensureStyles();
  const roots = [
    document.querySelector('[data-store-v2-panel="games"]'),
    document.querySelector('[data-store-v2-panel="bundles"]'),
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
  const href = new URL('../../css/games/four-in-a-row/store-cosmetics-v1.css?v=7&four_store=live-previews-v3&geometry=7x6&fx=victory-test-exact-v3', import.meta.url).href;
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
    const signature = `${layer}:${variant}:live-preview-v3`;
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
    drop:'Прицел, лазер и эффектное падение фишки.',
    four:'Молнии разлетаются от каждого вашего хода.',
    'victory-wave':'Победная четвёрка вспыхивает мощным финалом.',
  })[variant] || 'Яркий эффект для вашей партии.';
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
    [30,'yellow'],[31,'red'],[32,'yellow'],[33,'red'],[34,'yellow'],
    [24,'red'],[25,'yellow'],[26,'red'],[27,'yellow'],
    [17,'red'],[18,'yellow'],
  ]);

  if (safe === 'drop') occupied.delete(17);
  if (safe === 'victory-wave') {
    [22,23,24,25].forEach(cell => occupied.set(cell,'red'));
  }

  const winCells = new Set([22,23,24,25]);
  const cells = Array.from({ length:42 }, (_, index) => {
    const color = occupied.get(index);
    const extra = safe === 'drop' && index === 17
      ? ' fx-drop-target'
      : (safe === 'four' && index === 17
        ? ' fx-pulse-target'
        : (safe === 'victory-wave' && winCells.has(index) ? ' fx-win' : ''));
    return `<span class="mgw-four-fx-cell${extra}">${color ? `<i class="mgw-four-fx-disc ${color}"></i>` : ''}</span>`;
  }).join('');

  const board = `<span class="mgw-four-fx-preview-board">${cells}</span>`;

  if (safe === 'drop') {
    return `<i class="mgw-four-preview mgw-four-effect-preview effect-drop" aria-hidden="true"><span class="mgw-four-fx-stage"><span class="mgw-four-fx-board-shell">${board}<span class="mgw-four-preview-drop-reticle"><b class="corner tl"></b><b class="corner tr"></b><b class="corner bl"></b><b class="corner br"></b><i class="cross h"></i><i class="cross v"></i><em></em></span><span class="mgw-four-preview-drop-laser"></span><span class="mgw-four-preview-drop-disc"></span></span></span></i>`;
  }

  if (safe === 'four') {
    return `<i class="mgw-four-preview mgw-four-effect-preview effect-four" aria-hidden="true"><span class="mgw-four-fx-stage"><span class="mgw-four-fx-board-shell">${board}<svg class="mgw-four-preview-pulse-svg" viewBox="0 0 7 6" preserveAspectRatio="none"><path class="b1" pathLength="1" d="M3.5 2.5 L2.95 2.28 L2.5 2.5"></path><path class="b2" pathLength="1" d="M3.5 2.5 L4.05 2.18 L4.5 2.5"></path><path class="b3" pathLength="1" d="M3.5 2.5 L3.55 3.02 L3.5 3.5"></path><path class="b4" pathLength="1" d="M3.5 2.5 L2.7 3.22 L1.5 3.5"></path><path class="b5" pathLength="1" d="M3.5 2.5 L4.65 2.7 L5.5 2.5"></path><circle class="node-glow" cx="3.5" cy="2.5" r=".55"></circle><circle class="node-core" cx="3.5" cy="2.5" r=".13"></circle></svg></span></span></i>`;
  }

  return `<i class="mgw-four-preview mgw-four-effect-preview effect-victory-wave" aria-hidden="true"><span class="mgw-four-fx-stage"><span class="mgw-four-fx-board-shell">${board}<span class="mgw-four-preview-victory-shade"></span><svg class="mgw-four-preview-victory-svg" viewBox="0 0 7 6" preserveAspectRatio="none"><path class="rail glow" pathLength="1" d="M1.5 3.5 L2.5 3.5 L3.5 3.5 L4.5 3.5"></path><path class="rail color" pathLength="1" d="M1.5 3.5 L2.5 3.5 L3.5 3.5 L4.5 3.5"></path><path class="rail core" pathLength="1" d="M1.5 3.5 L2.5 3.5 L3.5 3.5 L4.5 3.5"></path><path class="rail scan" pathLength="1" d="M1.5 3.5 L2.5 3.5 L3.5 3.5 L4.5 3.5"></path><rect class="node n1" x="1.355" y="3.355" width=".29" height=".29" rx=".04" transform="rotate(45 1.5 3.5)"></rect><rect class="node n2" x="2.355" y="3.355" width=".29" height=".29" rx=".04" transform="rotate(45 2.5 3.5)"></rect><rect class="node n3" x="3.355" y="3.355" width=".29" height=".29" rx=".04" transform="rotate(45 3.5 3.5)"></rect><rect class="node n4" x="4.355" y="3.355" width=".29" height=".29" rx=".04" transform="rotate(45 4.5 3.5)"></rect></svg><span class="mgw-four-preview-victory-prism"><b class="shell"></b><b class="core"></b><b class="cut cut-a"></b><b class="cut cut-b"></b></span><span class="mgw-four-preview-victory-blade blade-a"></span><span class="mgw-four-preview-victory-blade blade-b"></span><span class="mgw-four-preview-victory-shards">${Array.from({length:12},(_,index)=>`<i class="s${index + 1}"></i>`).join('')}</span></span></span></i>`;
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
