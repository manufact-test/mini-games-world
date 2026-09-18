const INSTALL_KEY = '__mgwDominoStoreV1Installed';
const STYLE_MARK = 'mvp19-9-domino-store-v10-live-v41';
const EFFECT_STYLE_ATTR = 'data-mgw-domino-store-effects-v9';
const EFFECT_STYLE_VALUE = 'mvp19-9-domino-premium-effects-v15-proportions';
const LIVE_STYLE_ATTR = 'data-mgw-domino-store-live-parity-v41';
const LIVE_STYLE_VALUE = 'mvp19-9-domino-live-preview-v41';

ensureStyles();
ensureEffectStyles();
ensureLiveParityStyles();

export function installDominoStorePresentation(){
  ensureStyles();
  ensureEffectStyles();
ensureLiveParityStyles();
  if (globalThis[INSTALL_KEY]) return;
  globalThis[INSTALL_KEY] = true;
}

export function upgradeDominoStorePresentation(){
  ensureStyles();
  ensureEffectStyles();
ensureLiveParityStyles();
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

export function dominoPreviewMarkup(layer, variant){
  const normalizedLayer = String(layer || 'theme');
  const normalizedVariant = safeVariant(variant || (normalizedLayer === 'elements' ? 'ivory' : (normalizedLayer === 'effect' ? 'precision-drop' : 'felt')));
  const modeClass = normalizedLayer === 'theme'
    ? `theme-${normalizedVariant}`
    : (normalizedLayer === 'elements' ? `tiles-${normalizedVariant}` : `effect-${normalizedVariant}`);
  return `<i class="mgw-domino-preview ${modeClass}" aria-hidden="true">${tableMarkup(normalizedLayer, normalizedVariant)}</i>`;
}

export function dominoHeaderMarksMarkup(){
  return `<b class="mgw-domino-head-tile light" aria-hidden="true">${headBackMarkup('light')}</b><b class="mgw-domino-head-tile dark" aria-hidden="true">${headBackMarkup('dark')}</b>`;
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

function ensureEffectStyles(){
  const href = new URL('../../css/games/domino/store-effects-scene-v9.css?v=7&mvp19_9=domino-premium-effects-v15-proportions', import.meta.url).href;
  const existing = document.querySelector(`link[${EFFECT_STYLE_ATTR}]`);
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.setAttribute(EFFECT_STYLE_ATTR, EFFECT_STYLE_VALUE);
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.setAttribute(EFFECT_STYLE_ATTR, EFFECT_STYLE_VALUE);
  document.head.appendChild(link);
}

function ensureLiveParityStyles(){
  const href = new URL('../../css/games/domino/store-effects-live-parity-v41.css?v=1&mvp19_9=domino-live-preview-v41', import.meta.url).href;
  const existing = document.querySelector(`link[${LIVE_STYLE_ATTR}]`);
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.setAttribute(LIVE_STYLE_ATTR, LIVE_STYLE_VALUE);
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.setAttribute(LIVE_STYLE_ATTR, LIVE_STYLE_VALUE);
  document.head.appendChild(link);
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
  const marks = head.querySelector('.store-v2-game-head-marks');
  if (marks instanceof HTMLElement && !marks.querySelector('.mgw-domino-head-tile')) marks.innerHTML = dominoHeaderMarksMarkup();
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
    const expectedClass = modeClass(layer, variant);
    const visual = preview.querySelector(':scope > .mgw-domino-preview');
    const sceneReady = layer !== 'effect' || visual?.querySelector(`.mgw-domino-fx-stage[data-mgw-domino-fx-v14="${safeVariant(variant)}"][data-mgw-domino-preview-live-v41="${safeVariant(variant)}"]`);
    if (visual instanceof HTMLElement && visual.classList.contains(expectedClass) && sceneReady) {
      preview.dataset.mgwDominoPreview = `${layer}:${variant}:native:v10:live-v41`;
      return;
    }
    preview.innerHTML = dominoPreviewMarkup(layer, variant);
    preview.dataset.mgwDominoPreview = `${layer}:${variant}:native:v10:live-v41`;
  });
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
    'precision-drop':'Компактная золотая волна и восемь частиц от поставленной костяшки',
    'stock-pulse':'Тонкий импульс из запаса к новой костяшке с разлетающимися частицами',
    'chain-finale':'Финальный проход по цепочке с призмой, аурой и искрами',
  })[variant] || 'Добавляет визуальный эффект партии';
}

function tableMarkup(layer, variant){
  if (layer === 'effect') return effectSceneMarkup(variant);
  const chain = [[6,3],[3,5],[5,2]].map(pair => `<span class="mgw-domino-preview-slot">${tileMarkup(pair[0], pair[1])}</span>`).join('');
  const stock = '<span class="mgw-domino-preview-top"><span class="mgw-domino-stock"><i></i><i></i></span></span>';
  return `<span class="mgw-domino-preview-table">${stock}<span class="mgw-domino-preview-chain">${chain}</span><span class="mgw-domino-table-glow"></span></span>`;
}

function effectSceneMarkup(variant){
  if (variant === 'stock-pulse') {
    return `<span class="mgw-domino-preview-table"><span class="mgw-domino-fx-stage mgw-domino-v13-draw mgw-domino-live-preview-v41" data-mgw-domino-fx-v14="stock-pulse" data-mgw-domino-preview-live-v41="stock-pulse" data-mgw-domino-preview-particles="12"><span class="mgw-domino-v13-stock"><i></i><i></i><i></i></span><span class="mgw-domino-v13-draw-piece"><i class="back"></i><i class="face"></i></span><span class="mgw-domino-v13-draw-shadow"></span><span class="mgw-domino-v41-stock-line"></span><span class="mgw-domino-v41-stock-orb"></span><span class="mgw-domino-v41-stock-sparks">${Array.from({length:12},(_,index)=>`<i class="p${index + 1}"></i>`).join('')}</span></span></span>`;
  }
  if (variant === 'chain-finale') {
    return `<span class="mgw-domino-preview-table"><span class="mgw-domino-fx-stage mgw-domino-v13-cascade mgw-domino-live-preview-v41" data-mgw-domino-fx-v14="chain-finale" data-mgw-domino-preview-live-v41="chain-finale" data-mgw-domino-preview-particles="6"><span class="mgw-domino-v13-floor"></span><span class="mgw-domino-v13-cascade-row"><i></i><i></i><i></i><i></i><i></i></span><span class="mgw-domino-v13-finish-dust"></span><span class="mgw-domino-v41-finale-sweep"></span><span class="mgw-domino-v41-finale-aura"></span><span class="mgw-domino-v41-finale-prism"></span><span class="mgw-domino-v41-finale-sparks">${Array.from({length:6},(_,index)=>`<i class="s${index + 1}"></i>`).join('')}</span></span></span>`;
  }
  return `<span class="mgw-domino-preview-table"><span class="mgw-domino-fx-stage mgw-domino-v13-impact mgw-domino-live-preview-v41" data-mgw-domino-fx-v14="precision-drop" data-mgw-domino-preview-live-v41="precision-drop" data-mgw-domino-preview-particles="8"><span class="mgw-domino-v13-chain"><i></i><i></i></span><span class="mgw-domino-v13-impact-piece"></span><span class="mgw-domino-v13-impact-ring"></span><span class="mgw-domino-v13-impact-sparks">${Array.from({length:8},(_,index)=>`<i class="s${index + 1}"></i>`).join('')}</span></span></span>`;
}

function modeClass(layer, variant){
  const normalizedLayer = String(layer || 'theme');
  const normalizedVariant = safeVariant(variant || (normalizedLayer === 'elements' ? 'ivory' : (normalizedLayer === 'effect' ? 'precision-drop' : 'felt')));
  return normalizedLayer === 'theme'
    ? `theme-${normalizedVariant}`
    : (normalizedLayer === 'elements' ? `tiles-${normalizedVariant}` : `effect-${normalizedVariant}`);
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
