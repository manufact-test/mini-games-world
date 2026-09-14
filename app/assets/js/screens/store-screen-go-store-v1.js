import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.go.mvp19-8.v2');
const INSTALL_KEY = '__mgwGoStoreV2Installed';
const STYLE_MARK = 'mvp19-8-go-effects-premium-v2';

export function installGoStorePresentation(){
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

export function upgradeGoStorePresentation(){
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
  const href = new URL('../../css/games/go/store-cosmetics-v1.css?v=2&mvp19_8=effects-premium-v2', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-go-store]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwGoStore = STYLE_MARK;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwGoStore = STYLE_MARK;
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
  const run = () => upgradeGoStorePresentation();
  queueMicrotask(run);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
  globalThis.setTimeout(run, 0);
  globalThis.setTimeout(run, 80);
}

function renameSelector(root){
  root.querySelectorAll('[data-store-v2-game="go"]').forEach(button => {
    if (button instanceof HTMLElement) button.textContent = 'Го';
  });
}

function upgradeHeader(root){
  const head = root.querySelector('.store-v2-game-head[data-store-game-type="go"]');
  if (!(head instanceof HTMLElement)) return;
  const title = head.querySelector('h2');
  if (title instanceof HTMLElement) title.textContent = 'Го';
  const marks = head.querySelectorAll('.store-v2-game-head-marks b');
  marks.forEach((mark, index) => {
    if (!(mark instanceof HTMLElement)) return;
    mark.textContent = '';
    mark.classList.add('mgw-go-head-stone', index === 0 ? 'black' : 'white');
    mark.setAttribute('aria-hidden', 'true');
  });
}

function upgradeGroups(root){
  root.querySelectorAll('.store-v2-game-group').forEach(group => {
    if (!(group instanceof HTMLElement)) return;
    const preview = group.querySelector('.store-v2-game-preview[data-game-type="go"]');
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const title = group.querySelector('.store-v2-game-title-row h2');
    const subtitle = group.querySelector('.store-v2-game-title-row p');
    const copy = {
      theme:['Доски','Оформление доски Го'],
      elements:['Камни','Комплект чёрных и белых камней'],
      effect:['Эффекты',''],
    }[layer] || ['Го','Игровая косметика'];
    if (title instanceof HTMLElement) title.textContent = copy[0];
    if (subtitle instanceof HTMLElement) {
      if (layer === 'effect') subtitle.remove();
      else subtitle.textContent = copy[1];
    }
  });
}

function upgradeProducts(root){
  root.querySelectorAll('.store-v2-game-product[data-store-game-product="go"]').forEach(product => {
    if (!(product instanceof HTMLElement)) return;
    const preview = product.querySelector('.store-v2-game-preview[data-game-type="go"]');
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = String(preview.dataset.cosmeticVariant || 'wood');
    const kind = product.querySelector('.store-v2-game-product-copy > span');
    const description = product.querySelector('.store-v2-game-product-copy > p');
    if (kind instanceof HTMLElement) kind.textContent = layer === 'theme' ? 'Доска Го' : (layer === 'elements' ? 'Комплект камней' : 'Эффект партии');
    if (description instanceof HTMLElement) description.textContent = descriptionFor(layer, variant);
  });
}

function upgradePreviews(root){
  root.querySelectorAll('.store-v2-game-preview[data-game-type="go"]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const layer = String(preview.dataset.cosmeticLayer || 'theme');
    const variant = String(preview.dataset.cosmeticVariant || 'wood');
    const signature = `${layer}:${variant}:v2`;
    if (preview.dataset.mgwGoPreview === signature) return;
    preview.dataset.mgwGoPreview = signature;
    preview.innerHTML = previewMarkup(layer, variant);
  });
}

function descriptionFor(layer, variant){
  if (layer === 'theme') {
    return ({
      wood:'Тёплое дерево с мягким рисунком волокон и классической сеткой',
      dark:'Глубокая тёмная доска с контрастными линиями и спокойным блеском',
      stone:'Светлый камень с натуральной минеральной фактурой',
      neon:'Тёмная доска с холодным неоновым свечением линий и хоси',
    })[variant] || 'Меняет оформление доски Го';
  }
  if (layer === 'elements') {
    return ({
      classic:'Классические матовые камни с мягкими бликами и уверенным объёмом',
      marble:'Мраморные камни с живой минеральной фактурой',
      glass:'Полупрозрачное стекло с внутренними бликами и глубиной',
      neon:'Тёмные и светлые камни с яркими неоновыми контурами',
    })[variant] || 'Меняет внешний вид камней Го';
  }
  return ({
    placement:'Камень падает на пересечение и вспыхивает сегментированной энергетической печатью',
    'group-capture':'Захваченная группа схлопывается в доску с вращающейся короной и россыпью частиц',
    'territory-finish':'После партии территория проявляется каскадом меток и радиальным свечением поля',
  })[variant] || 'Добавляет визуальный эффект партии';
}

function previewMarkup(layer, variant){
  if (layer === 'theme') return boardMarkup(`theme-${safeVariant(variant)}`, baseScenario(), 'theme');
  if (layer === 'elements') return boardMarkup(`stones-${safeVariant(variant)}`, baseScenario(), 'stones');
  return boardMarkup(`effect-${safeVariant(variant)}`, effectScenario(variant), 'effect');
}

function baseScenario(){
  return new Map([
    [20,{ color:'black' }], [21,{ color:'black' }], [29,{ color:'black' }],
    [31,{ color:'white' }], [39,{ color:'white' }], [40,{ color:'white' }],
    [49,{ color:'black' }], [50,{ color:'white' }],
  ]);
}

function effectScenario(variant){
  if (variant === 'placement') {
    const stones = baseScenario();
    stones.set(30,{ color:'black', classes:['fx-placement-target'] });
    return stones;
  }
  if (variant === 'group-capture') {
    return new Map([
      [19,{ color:'black' }], [20,{ color:'black' }], [21,{ color:'black' }],
      [28,{ color:'black' }], [30,{ color:'black' }], [37,{ color:'black' }], [38,{ color:'black' }], [39,{ color:'black' }],
      [29,{ color:'white', classes:['fx-capture-target'], step:0 }],
      [31,{ color:'white', classes:['fx-capture-target'], step:1 }],
      [40,{ color:'white', classes:['fx-capture-target'], step:2 }],
    ]);
  }
  return new Map([
    [11,{ color:'black' }], [12,{ color:'black' }], [13,{ color:'black' }], [20,{ color:'black' }], [29,{ color:'black' }],
    [51,{ color:'white' }], [52,{ color:'white' }], [53,{ color:'white' }], [44,{ color:'white' }], [35,{ color:'white' }],
    [21,{ marker:'black', step:0 }], [22,{ marker:'black', step:1 }], [30,{ marker:'black', step:2 }],
    [43,{ marker:'white', step:3 }], [42,{ marker:'white', step:4 }], [34,{ marker:'white', step:5 }],
  ]);
}

function boardMarkup(variantClass, points, mode){
  const stoneMarkup = [];
  const markerMarkup = [];
  points.forEach((point, cell) => {
    const pos = pointPosition(cell);
    if (point.color) {
      const classes = ['mgw-go-stone', point.color, ...(point.classes || [])].join(' ');
      const style = Number.isInteger(point.step) ? `${pos};--fx-step:${point.step}` : pos;
      stoneMarkup.push(`<i class="${classes}" style="${style}"></i>`);
    }
    if (point.marker) {
      const style = Number.isInteger(point.step) ? `${pos};--fx-step:${point.step}` : pos;
      markerMarkup.push(`<em class="mgw-go-territory ${point.marker}" style="${style}"></em>`);
    }
  });
  return `<i class="mgw-go-preview ${variantClass} ${mode}" aria-hidden="true"><span class="mgw-go-board">${gridSvg()}${starMarkup()}${stoneMarkup.join('')}${markerMarkup.join('')}</span></i>`;
}

function pointPosition(cell){
  const row = Math.floor(cell / 9);
  const col = cell % 9;
  const inset = 7;
  const span = 86;
  const x = inset + (col / 8) * span;
  const y = inset + (row / 8) * span;
  return `--go-x:${x}%;--go-y:${y}%`;
}

function gridSvg(){
  const inset = 7;
  const span = 86;
  const lines = [];
  for (let index = 0; index < 9; index += 1) {
    const position = inset + (index / 8) * span;
    lines.push(`<line x1="${inset}" y1="${position}" x2="${100 - inset}" y2="${position}"></line>`);
    lines.push(`<line x1="${position}" y1="${inset}" x2="${position}" y2="${100 - inset}"></line>`);
  }
  return `<svg class="mgw-go-grid" viewBox="0 0 100 100" preserveAspectRatio="none">${lines.join('')}</svg>`;
}

function starMarkup(){
  return [2,4,6].flatMap(row => [2,4,6].map(col => {
    const cell = row * 9 + col;
    return `<b class="mgw-go-star" style="${pointPosition(cell)}"></b>`;
  })).join('');
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}