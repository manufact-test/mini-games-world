import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.battleship.preview-parity.v14');
const INSTALL_KEY = '__mgwBattleshipStorePreviewParityV14Installed';
const STYLE_MARK = 'mvp19-12-battleship-store-preview-parity-v14';

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
  const href = new URL('../../css/games/battleship/store-cosmetics-v1.css?v=14&mvp19_12=store-preview-parity-v14&header=steel-ship&neon_frame=outer-safe&neon_fleet=tube-v4&fleet_preview=svg-models-v3&neon_map_ships=white-v1&preview_geometry=svg-circles-v6&hydration=observer-v1&inline_owner=svg-v5&effects=live-parity-destroy-v3', import.meta.url).href;
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
  if (typeof MutationObserver === 'undefined' || hydrationObserver instanceof MutationObserver) return;
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
    const signature = `${layer}:${variant}:store-preview-parity-v14`;

    if (preview.dataset.mgwBattleshipPreview === signature) return;
    preview.dataset.mgwBattleshipPreview = signature;
    preview.dataset.mgwBattleshipPreviewMode = layer === 'effect' ? 'accepted-live-parity' : 'static';
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

const PREVIEW_BOARD_STYLE = 'position:absolute;left:8%;top:50%;width:84%;height:auto;display:grid;grid-template-columns:repeat(10,minmax(0,1fr));grid-template-rows:none;grid-auto-rows:auto;gap:2px;transform:translateY(-50%);z-index:2;';
const PREVIEW_CELL_BASE_STYLE = 'position:relative;display:block;width:100%;height:auto;aspect-ratio:1 / 1;min-width:0;min-height:0;border-radius:999px;box-sizing:border-box;';
const PREVIEW_SVG_STYLE = 'position:absolute;left:6%;top:50%;width:88%;height:88%;transform:translateY(-50%);overflow:visible;z-index:2;';

const PREVIEW_SHIP_RUNS = [
  [22,23,24,25],
  [47,57,67],
  [72,73],
  [88],
];

function boardPreview(layer, variant){
  const kind = layer === 'theme' ? `map-${variant}` : `fleet-${variant}`;
  const svg = layer === 'theme' ? mapSvgPreview(variant) : fleetSvgPreview(variant);
  return `
    <i class="mgw-battleship-preview ${kind} ${layer}" aria-hidden="true" style="${previewRootInlineStyle(layer, variant)}">
      ${svg}
      <b class="mgw-bs-preview-sweep" style="${previewSweepInlineStyle(layer, variant)}"></b>
    </i>
  `;
}

function previewPoint(index){
  const row = Math.floor(index / 10);
  const col = index % 10;
  return { x:10 + col * 11, y:10 + row * 11 };
}

function mapSvgPreview(variant){
  const ships = new Set(PREVIEW_SHIP_RUNS.flat());
  const palette = ({
    sea:{ water:'#0a6d87', waterStroke:'#73d8df', ship:'#dce9e5', shipStroke:'#ffffff' },
    'dark-military':{ water:'#18281f', waterStroke:'#718142', ship:'#a8b18a', shipStroke:'#dce9b5' },
    storm:{ water:'#31485a', waterStroke:'#859bad', ship:'#cbd6dd', shipStroke:'#f1f7fa' },
    neon:{ water:'#081326', waterStroke:'#3feaff', ship:'#f4f7fb', shipStroke:'#ffffff' },
  })[variant] || { water:'#102b42', waterStroke:'#47718c', ship:'#d5e0e6', shipStroke:'#ffffff' };

  const circles = Array.from({ length:100 }, (_, index) => {
    const { x, y } = previewPoint(index);
    const ship = ships.has(index);
    const fill = ship ? palette.ship : palette.water;
    const stroke = ship ? palette.shipStroke : palette.waterStroke;
    const opacity = ship ? '1' : '.88';
    return `<circle cx="${x}" cy="${y}" r="4.15" fill="${fill}" fill-opacity="${opacity}" stroke="${stroke}" stroke-width="1.05"></circle>`;
  }).join('');

  return `<svg class="mgw-bs-preview-svg" viewBox="0 0 120 120" preserveAspectRatio="xMidYMid meet" style="${PREVIEW_SVG_STYLE}">${circles}</svg>`;
}

function fleetSvgPreview(variant){
  const palette = ({
    classic:{ hull:'#e7c56e', rim:'#ffe6a6', core:'#8a6228', glow:'rgba(231,197,110,.38)' },
    modern:{ hull:'#4f7f96', rim:'#9ee9ff', core:'#1f4659', glow:'rgba(90,198,235,.34)' },
    armored:{ hull:'#4b535b', rim:'#aab4bc', core:'#22282e', glow:'rgba(170,180,188,.22)' },
    neon:{ hull:'#4937a5', rim:'#59f6ff', core:'#ff44de', glow:'rgba(89,246,255,.62)' },
  })[variant] || { hull:'#718291', rim:'#d1e0ea', core:'#2f3c46', glow:'rgba(180,210,230,.22)' };

  const water = Array.from({ length:100 }, (_, index) => {
    const { x, y } = previewPoint(index);
    return `<circle cx="${x}" cy="${y}" r="3.55" fill="#0b2134" fill-opacity=".42" stroke="#31536a" stroke-opacity=".78" stroke-width=".85"></circle>`;
  }).join('');

  const backbones = PREVIEW_SHIP_RUNS.map(run => {
    if (run.length < 2) return '';
    const a = previewPoint(run[0]);
    const b = previewPoint(run[run.length - 1]);
    const extra = variant === 'neon'
      ? `<line x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}" stroke="#ff44de" stroke-width="7.4" stroke-linecap="round" stroke-opacity=".30"></line>`
      : '';
    return `${extra}<line x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}" stroke="${palette.hull}" stroke-width="6.4" stroke-linecap="round" stroke-opacity=".96"></line>`;
  }).join('');

  const shipCells = PREVIEW_SHIP_RUNS.flat().map(index => {
    const { x, y } = previewPoint(index);
    if (variant === 'neon') {
      return `
        <circle cx="${x}" cy="${y}" r="5.0" fill="${palette.hull}" stroke="${palette.rim}" stroke-width="1.15" style="filter:drop-shadow(0 0 2.2px ${palette.glow})"></circle>
        <circle cx="${x}" cy="${y}" r="3.45" fill="none" stroke="${palette.core}" stroke-width=".85" stroke-opacity=".92"></circle>
      `;
    }
    return `
      <circle cx="${x}" cy="${y}" r="4.9" fill="${palette.hull}" stroke="${palette.rim}" stroke-width="1.05" style="filter:drop-shadow(0 0 1.2px ${palette.glow})"></circle>
      <circle cx="${x}" cy="${y}" r="2.5" fill="${palette.core}" fill-opacity=".28"></circle>
    `;
  }).join('');

  return `
    <svg class="mgw-bs-preview-svg mgw-bs-preview-fleet-svg" viewBox="0 0 120 120" preserveAspectRatio="xMidYMid meet" style="${PREVIEW_SVG_STYLE}">
      ${water}
      ${backbones}
      ${shipCells}
    </svg>
  `;
}

function previewRootInlineStyle(layer, variant){
  const base = 'position:absolute;inset:0;display:block;overflow:hidden;border-radius:inherit;';
  if (layer === 'elements') return base + 'background:linear-gradient(145deg,#0b2740,#071827);';
  return base + ({
    sea:'background:linear-gradient(145deg,#0b8ea8 0%,#075e78 55%,#063754 100%);',
    'dark-military':'background:linear-gradient(145deg,#1c281f,#0c1411 74%);',
    storm:'background:linear-gradient(150deg,#536c80 0%,#2c4254 42%,#141f2c 100%);',
    neon:'background:linear-gradient(145deg,#050914 0%,#0a0f20 58%,#111225 100%);box-shadow:inset 0 0 22px rgba(48,236,255,.13),inset 0 0 0 1px rgba(61,235,255,.18);',
  }[variant] || 'background:#071525;');
}

function previewSweepInlineStyle(layer, variant){
  const base = 'position:absolute;inset:0;z-index:1;pointer-events:none;';
  if (layer !== 'theme') return base;
  return base + ({
    sea:'background:repeating-linear-gradient(165deg,transparent 0 10px,rgba(153,255,246,.09) 11px 12px,transparent 13px 22px);',
    'dark-military':'background:linear-gradient(90deg,transparent 49.7%,rgba(171,209,103,.14) 50%,transparent 50.3%),linear-gradient(0deg,transparent 49.7%,rgba(171,209,103,.14) 50%,transparent 50.3%),radial-gradient(circle at 50% 50%,transparent 0 27%,rgba(171,209,103,.08) 27.5% 28.2%,transparent 28.8% 44%,rgba(171,209,103,.06) 44.5% 45.2%,transparent 45.8%);',
    storm:'background:repeating-linear-gradient(115deg,transparent 0 13px,rgba(224,241,255,.11) 14px 15px,transparent 16px 27px);',
    neon:'inset:2%;border:1px solid rgba(229,62,255,.42);border-radius:8px;box-shadow:0 0 8px rgba(229,62,255,.17),inset 0 0 8px rgba(43,231,255,.06);',
  }[variant] || '');
}

function effectPreview(variant){
  const safe = ['shot','hit','destroy'].includes(variant) ? variant : 'shot';
  const cells = Array.from({ length:100 }, (_, index) => {
    const target = index === 55 ? ' target' : '';
    let ship = '';
    if (safe === 'hit' && index === 55) ship = ' ship hit-ship';
    if (safe === 'destroy' && [54,55,56].includes(index)) ship = ' ship destroyed-ship';
    return `<span class="${target}${ship}" style="${PREVIEW_CELL_BASE_STYLE}"></span>`;
  }).join('');

  const shotFx = `
    <span class="mgw-bs-preview-shot-fx">
      <b class="mgw-bs-preview-shot-reticle"></b>
      <b class="mgw-bs-preview-shot-ping"></b>
      <b class="mgw-bs-preview-shot-tracer"><i class="mgw-bs-preview-shot-bolt"></i></b>
    </span>`;

  const hitSparks = Array.from({ length:6 }, () => '<i></i>').join('');
  const hitFx = `
    <span class="mgw-bs-preview-hit-fx">
      <b class="mgw-bs-preview-hit-core"></b>
      <b class="mgw-bs-preview-hit-ring"></b>
      <b class="mgw-bs-preview-hit-flare"></b>
      <span class="mgw-bs-preview-hit-sparks">${hitSparks}</span>
    </span>`;

  const destroyShards = Array.from({ length:10 }, () => '<i></i>').join('');
  const destroyFx = `
    <span class="mgw-bs-preview-destroy-fx">
      <b class="mgw-bs-preview-destroy-flash"></b>
      <b class="mgw-bs-preview-destroy-ring"></b>
      <b class="mgw-bs-preview-destroy-ring secondary"></b>
      <b class="mgw-bs-preview-destroy-wreck"></b>
      <span class="mgw-bs-preview-destroy-smoke"><i></i><i></i><i></i></span>
      <span class="mgw-bs-preview-destroy-shards">${destroyShards}</span>
    </span>`;

  const fx = safe === 'shot' ? shotFx : (safe === 'hit' ? hitFx : destroyFx);

  return `
    <i class="mgw-battleship-preview effect effect-${safe}" data-battleship-effect-preview="accepted-live-parity-destroy-v3" aria-hidden="true">
      <span class="mgw-bs-preview-board" style="${PREVIEW_BOARD_STYLE}">${cells}</span>
      ${fx}
    </i>
  `;
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'base';
}
