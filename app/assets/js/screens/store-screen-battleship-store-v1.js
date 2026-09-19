import { api } from '../api/client.js?v=34';

const API_HOOK = Symbol.for('mgw.store.battleship.preview-parity.v7');
const INSTALL_KEY = '__mgwBattleshipStorePreviewParityV7Installed';
const STYLE_MARK = 'mvp19-12-battleship-store-preview-parity-v7';

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
  const href = new URL('../../css/games/battleship/store-cosmetics-v1.css?v=7&mvp19_12=store-preview-parity-v7&header=steel-ship&neon_frame=outer-safe&neon_fleet=tube-v4&preview_geometry=inline-square-v3&hydration=observer-v1&inline_owner=v1&effects=unchanged-v2', import.meta.url).href;
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
    const signature = `${layer}:${variant}:store-preview-parity-v7`;

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

const PREVIEW_BOARD_STYLE = 'position:absolute;left:8%;top:50%;width:84%;height:auto;aspect-ratio:1 / 1;display:grid;grid-template-columns:repeat(10,minmax(0,1fr));grid-template-rows:repeat(10,minmax(0,1fr));gap:2px;transform:translateY(-50%);z-index:2;';
const PREVIEW_CELL_BASE_STYLE = 'position:relative;display:block;width:100%;height:100%;min-width:0;min-height:0;border-radius:50%;box-sizing:border-box;';

function boardPreview(layer, variant){
  const ships = new Set([22,23,24,25,47,57,67,72,73,88]);
  const cells = Array.from({ length:100 }, (_, index) => {
    const ship = ships.has(index);
    return `<span class="${ship ? 'ship' : ''}" style="${previewCellInlineStyle(layer, variant, ship)}"></span>`;
  }).join('');

  const kind = layer === 'theme' ? `map-${variant}` : `fleet-${variant}`;
  return `
    <i class="mgw-battleship-preview ${kind} ${layer}" aria-hidden="true" style="${previewRootInlineStyle(layer, variant)}">
      <span class="mgw-bs-preview-board" style="${PREVIEW_BOARD_STYLE}">${cells}</span>
      <b class="mgw-bs-preview-sweep" style="${previewSweepInlineStyle(layer, variant)}"></b>
    </i>
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

function previewCellInlineStyle(layer, variant, ship){
  let style = PREVIEW_CELL_BASE_STYLE;
  if (layer === 'elements') {
    style += 'background:rgba(13,48,72,.62);border:1px solid rgba(129,190,220,.18);';
    if (!ship) return style;
    return style + ({
      classic:'background:radial-gradient(circle at 34% 28%,#fff7db 0 18%,#f3e2b8 36%,#9f7b42 100%);border:1px solid #e8bf68;box-shadow:0 0 0 1px rgba(74,48,17,.45),inset 0 0 0 1px rgba(255,255,255,.55);',
      modern:'background:linear-gradient(145deg,#9bd7ea 0 18%,#426d82 20% 62%,#173444 64%);border:1px solid #a8e9ff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.25),0 0 7px rgba(76,181,218,.22);',
      armored:'background:radial-gradient(circle at 28% 28%,rgba(255,255,255,.58) 0 4%,transparent 5%),linear-gradient(145deg,#8b959d 0 18%,#343c45 20% 58%,#161b21 60%);border:2px solid #aeb8c0;box-shadow:inset 0 0 0 1px #252c33,0 2px 4px rgba(0,0,0,.45);',
      neon:'background:linear-gradient(145deg,#4d3aaa 0%,#2a205f 58%,#12152e 100%);border:2px solid #55f5ff;box-shadow:0 0 5px rgba(85,245,255,.86),0 0 10px rgba(85,245,255,.42),0 0 15px rgba(255,70,223,.22),inset 0 0 0 2px rgba(255,70,223,.34),inset 0 0 9px rgba(123,92,255,.34);',
    }[variant] || '');
  }

  const water = {
    sea:'background:linear-gradient(145deg,rgba(41,211,205,.52),rgba(5,91,125,.72));border:1px solid rgba(183,255,249,.34);box-shadow:inset 0 0 0 1px rgba(17,113,141,.22);',
    'dark-military':'background:rgba(22,39,31,.92);border:1px solid rgba(158,192,91,.32);box-shadow:inset 0 0 0 1px rgba(72,98,57,.25);',
    storm:'background:linear-gradient(145deg,rgba(112,142,164,.45),rgba(27,44,59,.8));border:1px solid rgba(213,231,244,.21);',
    neon:'background:#081326;border:1px solid rgba(63,234,255,.68);box-shadow:inset 0 0 5px rgba(52,229,255,.12);',
  }[variant] || 'background:rgba(9,29,53,.42);border:1px solid rgba(255,255,255,.12);';
  style += water;
  if (!ship) return style;

  return style + ({
    sea:'background:linear-gradient(145deg,#eef6f1,#a7b9b8);border:1px solid rgba(255,255,255,.48);',
    'dark-military':'background:linear-gradient(145deg,#a8b18a,#5f6c52);border:1px solid rgba(220,233,181,.3);',
    storm:'background:linear-gradient(145deg,#cbd6dd,#657784);border:1px solid rgba(255,255,255,.32);',
    neon:'background:linear-gradient(145deg,#164458,#0b2234);border:1px solid #63f2ff;box-shadow:0 0 5px rgba(61,239,255,.6);',
  }[variant] || '');
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

  return `
    <i class="mgw-battleship-preview effect effect-${safe}" aria-hidden="true">
      <span class="mgw-bs-preview-board" style="${PREVIEW_BOARD_STYLE}">${cells}</span>
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
