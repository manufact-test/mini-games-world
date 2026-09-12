import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=27';
import { renderBalances } from '../ui.js?v=89';
import { selectWinnerVictoryEffect } from './mgw-victory-effect-selector.js?v=1';

const VICTORY_EFFECT_SLOT = 'profile_victory_effect';
const SPARK_BURST_ID = 'profile-victory-effect-01';
const FIREWORK_SALVO_ID = 'profile-victory-effect-02';
const VICTORY_NOVA_ID = 'profile-victory-effect-03';

const VICTORY_PRESENTATION = Object.freeze({
  [SPARK_BURST_ID]:Object.freeze({
    variant:'spark-burst',
    duration:2200,
    fallbackName:'Искровой залп',
    tier:'Уровень II',
  }),
  [FIREWORK_SALVO_ID]:Object.freeze({
    variant:'firework-salvo',
    duration:2900,
    fallbackName:'Салют победителя',
    tier:'Уровень I',
  }),
  [VICTORY_NOVA_ID]:Object.freeze({
    variant:'victory-nova',
    duration:3500,
    fallbackName:'Победная сверхновая',
    tier:'Уровень III',
  }),
});

let initialized = false;
let scheduled = false;
let refreshPromise = null;
let snapshotAttempted = false;
let equipBusy = false;
let liveHideTimer = 0;
let catalogPreviewTimer = 0;
let activeCatalogPreview = null;
const purchasePending = new Set();
const playedGames = new Set();

export function initMgwProfileVictoryEffects(){
  if (initialized) return;
  initialized = true;
  ensureStylesheet();
  ensureOnDemandPreviewStyles();

  const start = () => {
    document.addEventListener('mgw:cosmetic-inventory-changed', event => {
      scheduleDecorate();
      if (String(event?.detail?.slot || '').trim() === VICTORY_EFFECT_SLOT) scheduleResultProbeAfterPaint();
    });

    document.addEventListener('mgw:screen-changed', event => {
      const next = String(event?.detail?.to || '').trim();
      stopCatalogPreview();
      if (next === 'profile' || next === 'store') {
        scheduleDecorate();
        void ensureSnapshot();
      }
      if (next !== 'game') removeLiveVictoryEffect();
      else scheduleResultProbeAfterPaint();
    });

    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;

      if (target.closest('[data-close-sheet]')) stopCatalogPreview();

      // These accepted Profile actions can synchronously rebuild the Profile root.
      // Re-decorate once after the originating event instead of watching the whole DOM.
      if (target.closest('#screen-profile [data-profile-game-tab],#mgwNicknameSave,#mgwAvatarEquip,#mgwNameColorEquip,#mgwGameCosmeticEquip')) {
        scheduleDecorate();
      }
    });

    document.addEventListener('mgw:game-finished', scheduleResultProbeAfterPaint);
    document.addEventListener('mgw:game-dismissed', () => {
      removeLiveVictoryEffect();
      stopCatalogPreview();
    });

    const active = String(document.querySelector('.screen.active')?.dataset.screen || '').trim();
    if (active === 'profile' || active === 'store') void ensureSnapshot();
    scheduleDecorate();
    scheduleResultProbeAfterPaint();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once:true });
  else start();
}

function ensureStylesheet(){
  if (document.querySelector('link[data-mgw-victory-effects-css]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwVictoryEffectsCss = 'spark-burst-v2';
  link.href = new URL('../../css/production-v109-victory-effects-spark-burst.css?v=2&mvp19_3=spark-burst-visual-parity', import.meta.url).href;
  document.head.appendChild(link);
}

function ensureOnDemandPreviewStyles(){
  if (document.getElementById('mgwVictoryPreviewOnDemandV1')) return;
  const style = document.createElement('style');
  style.id = 'mgwVictoryPreviewOnDemandV1';
  style.textContent = `
    .mgw-victory-effect-preview[data-victory-effect-play]{
      cursor:pointer;
      touch-action:manipulation;
      contain:layout paint style;
    }
    .mgw-victory-preview-poster{
      position:absolute;
      inset:0;
      z-index:6;
      display:grid;
      place-items:center;
      pointer-events:none;
      opacity:1;
      transition:opacity .12s ease;
    }
    .mgw-victory-preview-poster>i{
      position:absolute;
      left:50%;
      top:50%;
      width:42px;
      height:42px;
      transform:translate(-50%,-50%);
      border-radius:50%;
      background:radial-gradient(circle,#fff7c9 0 5%,#ffd45d 8% 16%,rgba(255,171,43,.38) 28%,transparent 58%);
      box-shadow:0 0 13px rgba(255,188,65,.35);
    }
    .mgw-victory-effect-preview[data-victory-effect-variant="spark-burst"] .mgw-victory-preview-poster>i::before{
      content:"";
      position:absolute;
      inset:-8px;
      background:repeating-conic-gradient(from 0deg,rgba(255,225,130,.92) 0 3deg,transparent 3deg 28deg);
      -webkit-mask:radial-gradient(circle,transparent 0 40%,#000 43% 53%,transparent 56%);
      mask:radial-gradient(circle,transparent 0 40%,#000 43% 53%,transparent 56%);
      opacity:.82;
    }
    .mgw-victory-effect-preview[data-victory-effect-variant="firework-salvo"] .mgw-victory-preview-poster>i{
      width:9px;
      height:9px;
      background:#fff8d8;
      box-shadow:-22px 10px 0 1px #75d4ff,22px 10px 0 1px #ff78cf,0 -18px 0 2px #ffd55e,0 0 12px rgba(129,190,255,.72),-22px 10px 12px rgba(86,191,255,.5),22px 10px 12px rgba(255,106,208,.45);
    }
    .mgw-victory-effect-preview[data-victory-effect-variant="victory-nova"] .mgw-victory-preview-poster>i{
      width:48px;
      height:48px;
      background:radial-gradient(circle,#fff 0 5%,#bff8ff 8% 14%,#9c7dff 22%,rgba(123,82,255,.3) 42%,transparent 68%);
      box-shadow:0 0 12px rgba(100,226,255,.58),0 0 24px rgba(148,87,255,.46);
    }
    .mgw-victory-effect-preview[data-victory-effect-variant="victory-nova"] .mgw-victory-preview-poster>i::before{
      content:"";
      position:absolute;
      inset:-8px;
      border:1px solid rgba(133,239,255,.62);
      border-radius:50%;
      box-shadow:0 0 10px rgba(135,99,255,.42);
    }
    .mgw-victory-preview-play{
      position:absolute;
      right:7px;
      bottom:7px;
      width:24px;
      height:24px;
      display:grid;
      place-items:center;
      border-radius:50%;
      border:1px solid rgba(255,255,255,.34);
      background:rgba(8,11,21,.82);
      color:#fff;
      box-shadow:0 4px 12px rgba(0,0,0,.32);
      font:900 10px/1 system-ui,sans-serif;
      padding-left:1px;
    }
    .mgw-victory-catalog-stage{
      position:absolute;
      inset:0;
      z-index:7;
      overflow:hidden;
      pointer-events:none;
      contain:strict;
    }
    .mgw-victory-effect-preview.is-playing .mgw-victory-preview-poster{opacity:0}
    .mgw-victory-effect-preview.is-playing .mgw-victory-catalog-stage *{
      animation-iteration-count:1!important;
    }
    .mgw-victory-effect-preview.is-playing .mgw-victory-catalog-stage .mgw-victory-spark-scene,
    .mgw-victory-effect-preview.is-playing .mgw-victory-catalog-stage .mgw-victory-firework-scene,
    .mgw-victory-effect-preview.is-playing .mgw-victory-catalog-stage .mgw-victory-nova-scene{
      animation-iteration-count:1!important;
    }
    @media(prefers-reduced-motion:reduce){
      .mgw-victory-preview-play{display:none!important}
      .mgw-victory-effect-preview[data-victory-effect-play]{cursor:default}
    }
  `;
  document.head.appendChild(style);
}

function scheduleDecorate(){
  if (scheduled) return;
  scheduled = true;
  queueMicrotask(() => {
    scheduled = false;
    decorateCollectionSurfaces();
  });
}

function decorateCollectionSurfaces(){
  const catalog = victoryEffectCatalog();
  if (!catalog.length) return;
  renderStoreSection(catalog);
  renderProfileCollection(catalog);
}

function ensureSnapshot(){
  if (victoryEffectCatalog().length || snapshotAttempted) return Promise.resolve(state.profileInventory);
  snapshotAttempted = true;
  return refreshSnapshot();
}

function refreshSnapshot(){
  if (refreshPromise) return refreshPromise;
  snapshotAttempted = true;
  refreshPromise = api.profileV2()
    .then(result => {
      if (result?.inventory && typeof result.inventory === 'object') state.profileInventory = result.inventory;
      if (result?.profile && typeof result.profile === 'object') state.mgwProfile = result.profile;
      if (result?.user && state.user && typeof state.user === 'object') {
        const balance = Number(result.user.balance ?? state.user.balance ?? 0);
        state.user = { ...state.user, balance };
        renderBalances(state.user);
      }
      scheduleDecorate();
      return state.profileInventory;
    })
    .catch(() => state.profileInventory)
    .finally(() => { refreshPromise = null; });
  return refreshPromise;
}

function victoryEffectCatalog(){
  const catalog = Array.isArray(state.profileInventory?.catalog) ? state.profileInventory.catalog : [];
  return catalog
    .filter(item => item
      && item.item_type === 'profile'
      && item.item_family === 'victory_effect'
      && item.equip_slot === VICTORY_EFFECT_SLOT
      && String(item.catalog_status || '') === 'active')
    .map(item => ({ ...item, item_id:String(item.item_id || '') }))
    .filter(item => Boolean(VICTORY_PRESENTATION[item.item_id]));
}

function currentVictoryEffectId(){
  const itemId = String(state.profileInventory?.equipped?.[VICTORY_EFFECT_SLOT] || '').trim();
  return VICTORY_PRESENTATION[itemId] ? itemId : '';
}

function meta(item){ return item?.metadata && typeof item.metadata === 'object' ? item.metadata : {}; }
function itemName(item){ return String(meta(item).display_name || VICTORY_PRESENTATION[item?.item_id]?.fallbackName || 'Эффект победы'); }
function itemPrice(item){ return Math.max(0, Number(meta(item).price_coins || 0)); }
function itemOfferId(item){ return String(meta(item).offer_id || String(item?.item_id || '').replace(/^profile-/, '')); }
function itemTier(item){ return String(VICTORY_PRESENTATION[item?.item_id]?.tier || 'Эффект победы'); }
function presentationFor(itemId){ return VICTORY_PRESENTATION[String(itemId || '')] || null; }

function burstMarkup(kind, count, previewDistance, liveDistance, baseDelay){
  const rays = Array.from({ length:count }, (_, index) => {
    const angle = Math.round((360 / count) * index + (kind === 'burst-main' ? 0 : 360 / count / 2));
    const distanceScale = 0.78 + ((index * 7) % 6) * 0.055;
    const pd = Math.round(previewDistance * distanceScale);
    const ld = Math.round(liveDistance * distanceScale);
    const delay = (baseDelay + (index % 5) * 0.014).toFixed(3);
    return `<i style="--a:${angle}deg;--pd:${pd}px;--ld:${ld}px;--delay:${delay}s"></i>`;
  }).join('');
  return `<span class="mgw-victory-burst ${kind}"><i class="mgw-victory-spark-flash"></i><i class="mgw-victory-spark-ring ring-a"></i><i class="mgw-victory-spark-ring ring-b"></i><i class="mgw-victory-spark-ring ring-c"></i><span class="mgw-victory-spark-rays">${rays}</span></span>`;
}

function confettiMarkup(count){
  return `<span class="mgw-victory-spark-confetti">${Array.from({ length:count }, (_, index) => {
    const angle = ((index * 137.5) + 18) * Math.PI / 180;
    const previewRadius = 34 + (index % 5) * 7;
    const liveRadiusX = 170 + (index % 5) * 34;
    const liveRadiusY = 110 + (index % 4) * 29;
    const px = Math.round(Math.cos(angle) * previewRadius);
    const py = Math.round(Math.sin(angle) * previewRadius * 0.72);
    const lx = Math.round(Math.cos(angle) * liveRadiusX);
    const ly = Math.round(Math.sin(angle) * liveRadiusY);
    const rotation = ((index * 83) % 420) - 210;
    const delay = (0.08 + (index % 6) * 0.025).toFixed(3);
    return `<b style="--px:${px}px;--py:${py}px;--lx:${lx}px;--ly:${ly}px;--r:${rotation}deg;--delay:${delay}s"></b>`;
  }).join('')}</span>`;
}

function glitterMarkup(count){
  return `<span class="mgw-victory-spark-glitter">${Array.from({ length:count }, (_, index) => {
    const angle = ((index * 151) + 9) * Math.PI / 180;
    const previewRadius = 24 + (index % 7) * 7;
    const liveRadius = 120 + (index % 7) * 31;
    const px = Math.round(Math.cos(angle) * previewRadius);
    const py = Math.round(Math.sin(angle) * previewRadius);
    const lx = Math.round(Math.cos(angle) * liveRadius);
    const ly = Math.round(Math.sin(angle) * liveRadius * 0.8);
    const delay = (0.16 + (index % 8) * 0.028).toFixed(3);
    return `<em style="--px:${px}px;--py:${py}px;--lx:${lx}px;--ly:${ly}px;--delay:${delay}s"></em>`;
  }).join('')}</span>`;
}

function starMarkup(){
  const stars = [
    [22,28,.12],[76,24,.28],[17,67,.36],[82,70,.18],[36,16,.42],[64,82,.32],[49,72,.52],[70,52,.46],
  ];
  return `<span class="mgw-victory-spark-stars">${stars.map(([x,y,delay]) => `<i style="--x:${x}%;--y:${y}%;--delay:${delay}s"></i>`).join('')}</span>`;
}

function sparkBurstStageMarkup(){
  return `<span class="mgw-victory-spark-scene">${burstMarkup('burst-main',30,58,300,0)}${burstMarkup('burst-left',14,34,150,.18)}${burstMarkup('burst-right',14,34,150,.26)}${confettiMarkup(18)}${glitterMarkup(24)}${starMarkup()}</span>`;
}

function fireworkBurstMarkup(kind, count, previewDistance, liveDistance){
  const rays = Array.from({ length:count }, (_, index) => {
    const angle = Math.round((360 / count) * index + (kind === 'salvo-center' ? 0 : 360 / count / 2));
    const scale = 0.74 + ((index * 5) % 7) * 0.055;
    const pd = Math.round(previewDistance * scale);
    const ld = Math.round(liveDistance * scale);
    return `<i style="--a:${angle}deg;--pd:${pd}px;--ld:${ld}px"></i>`;
  }).join('');
  return `<span class="mgw-victory-firework-burst ${kind}"><i class="mgw-victory-firework-flash"></i><i class="mgw-victory-firework-ring ring-a"></i><i class="mgw-victory-firework-ring ring-b"></i><span class="mgw-victory-firework-rays">${rays}</span></span>`;
}

function fireworkStarfieldMarkup(count){
  return `<span class="mgw-victory-firework-starfield">${Array.from({ length:count }, (_, index) => {
    const x = 8 + ((index * 37) % 85);
    const y = 12 + ((index * 53) % 74);
    const delay = ((index % 7) * .11).toFixed(2);
    return `<i style="--x:${x}%;--y:${y}%;--delay:${delay}s"></i>`;
  }).join('')}</span>`;
}

function fireworkConfettiMarkup(count){
  return `<span class="mgw-victory-firework-confetti">${Array.from({ length:count }, (_, index) => {
    const x = 10 + ((index * 41) % 82);
    const drift = ((index % 9) - 4) * 9;
    const rot = ((index * 67) % 360) - 180;
    const delay = (.72 + (index % 8) * .055).toFixed(3);
    return `<b style="--x:${x}%;--drift:${drift}px;--r:${rot}deg;--delay:${delay}s"></b>`;
  }).join('')}</span>`;
}

function fireworkSalvoStageMarkup(){
  return `<span class="mgw-victory-firework-scene"><span class="mgw-victory-firework-trail trail-left"><i></i><b></b></span><span class="mgw-victory-firework-trail trail-right"><i></i><b></b></span>${fireworkBurstMarkup('salvo-left',22,42,210)}${fireworkBurstMarkup('salvo-right',22,42,210)}${fireworkBurstMarkup('salvo-center',30,55,285)}<i class="mgw-victory-firework-wave"></i>${fireworkStarfieldMarkup(18)}${fireworkConfettiMarkup(24)}</span>`;
}

function novaBurstMarkup(kind, count, previewDistance, liveDistance, baseDelay){
  const rays = Array.from({ length:count }, (_, index) => {
    const angle = Math.round((360 / count) * index + ((index % 2) * (180 / count)));
    const scale = 0.74 + ((index * 11) % 7) * 0.052;
    const pd = Math.round(previewDistance * scale);
    const ld = Math.round(liveDistance * scale);
    const delay = (baseDelay + (index % 6) * 0.012).toFixed(3);
    return `<i style="--a:${angle}deg;--pd:${pd}px;--ld:${ld}px;--delay:${delay}s"></i>`;
  }).join('');
  return `<span class="mgw-victory-nova-burst ${kind}" style="--burst-delay:${baseDelay}s"><i class="mgw-victory-nova-flash"></i><i class="mgw-victory-nova-ring ring-a"></i><i class="mgw-victory-nova-ring ring-b"></i><span class="mgw-victory-nova-rays">${rays}</span></span>`;
}

function novaCometMarkup(){
  const comets = [
    [8,76,64,-42,430,-310,.10],
    [92,72,-66,-44,-450,-330,.18],
    [14,26,72,24,500,160,.38],
    [88,24,-74,26,-520,175,.46],
    [24,88,54,-70,360,-470,.58],
    [77,87,-58,-72,-390,-485,.68],
    [49,94,4,-82,25,-560,.82],
  ];
  return `<span class="mgw-victory-nova-comets">${comets.map(([x,y,pdx,pdy,ldx,ldy,delay], index) => `<i class="comet-${index+1}" style="--x:${x}%;--y:${y}%;--pdx:${pdx}px;--pdy:${pdy}px;--ldx:${ldx}px;--ldy:${ldy}px;--delay:${delay}s"><b></b></i>`).join('')}</span>`;
}

function novaGlitterRainMarkup(count){
  return `<span class="mgw-victory-nova-rain">${Array.from({ length:count }, (_, index) => {
    const x = 4 + ((index * 37) % 93);
    const y = -12 - ((index * 19) % 36);
    const drift = ((index % 9) - 4) * 5;
    const delay = (.72 + (index % 10) * .045).toFixed(3);
    const scale = (0.72 + (index % 4) * .12).toFixed(2);
    return `<i style="--x:${x}%;--y:${y}%;--drift:${drift}px;--delay:${delay}s;--s:${scale}"></i>`;
  }).join('')}</span>`;
}

function novaStarfieldMarkup(count){
  return `<span class="mgw-victory-nova-stars">${Array.from({ length:count }, (_, index) => {
    const x = 6 + ((index * 43) % 89);
    const y = 8 + ((index * 59) % 82);
    const delay = (.18 + (index % 8) * .11).toFixed(2);
    const size = 7 + (index % 4) * 2;
    return `<i style="--x:${x}%;--y:${y}%;--delay:${delay}s;--size:${size}px"></i>`;
  }).join('')}</span>`;
}

function novaCrownMarkup(){
  const spokes = Array.from({ length:14 }, (_, index) => {
    const angle = Math.round((360 / 14) * index);
    const height = 10 + (index % 3) * 4;
    return `<i style="--a:${angle}deg;--h:${height}px"></i>`;
  }).join('');
  return `<span class="mgw-victory-nova-crown"><b></b><em></em><span>${spokes}</span></span>`;
}

function victoryNovaStageMarkup(){
  return `<span class="mgw-victory-nova-scene"><i class="mgw-victory-nova-ignition"></i><i class="mgw-victory-nova-wave wave-a"></i><i class="mgw-victory-nova-wave wave-b"></i><i class="mgw-victory-nova-wave wave-c"></i>${novaStarfieldMarkup(22)}${novaCometMarkup()}${novaBurstMarkup('nova-core',32,68,360,.12)}${novaBurstMarkup('nova-top-left',14,36,185,.42)}${novaBurstMarkup('nova-top-right',14,36,185,.50)}${novaBurstMarkup('nova-bottom-left',14,34,175,.64)}${novaBurstMarkup('nova-bottom-right',14,34,175,.72)}${novaGlitterRainMarkup(34)}${novaCrownMarkup()}</span>`;
}

function victoryStageMarkup(variant){
  if (variant === 'victory-nova') return victoryNovaStageMarkup();
  if (variant === 'firework-salvo') return fireworkSalvoStageMarkup();
  return sparkBurstStageMarkup();
}

function previewMarkup(itemId, selected = false, extraClass = ''){
  const spec = presentationFor(itemId);
  if (!spec) return '';
  return `<span class="mgw-entry-effect-preview mgw-victory-effect-preview ${escapeAttr(extraClass)}" data-victory-effect-item-id="${escapeAttr(itemId)}" data-victory-effect-variant="${escapeAttr(spec.variant)}" data-victory-effect-play aria-label="Показать анимацию"><span class="mgw-victory-preview-poster" aria-hidden="true"><i></i><b class="mgw-victory-preview-play">▶</b></span>${selected ? '<em class="store-v2-selected-check">✓</em>' : ''}</span>`;
}

function bindPreviewPlayback(root){
  if (!(root instanceof Element)) return;
  root.querySelectorAll('[data-victory-effect-play]').forEach(preview => {
    if (!(preview instanceof HTMLElement) || preview.dataset.victoryEffectPlayBound === '1') return;
    preview.dataset.victoryEffectPlayBound = '1';
    preview.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      playCatalogPreview(preview);
    });
  });
}

function playCatalogPreview(preview){
  if (!(preview instanceof HTMLElement) || window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true) return;
  const itemId = String(preview.dataset.victoryEffectItemId || '');
  const spec = presentationFor(itemId);
  if (!spec) return;

  stopCatalogPreview();
  preview.querySelectorAll('.mgw-victory-catalog-stage').forEach(node => node.remove());
  preview.insertAdjacentHTML('beforeend', `<span class="mgw-victory-catalog-stage" data-victory-effect-variant="${escapeAttr(spec.variant)}" aria-hidden="true">${victoryStageMarkup(spec.variant)}</span>`);
  preview.classList.add('is-playing');
  activeCatalogPreview = preview;

  window.clearTimeout(catalogPreviewTimer);
  catalogPreviewTimer = window.setTimeout(() => stopCatalogPreview(preview), Math.max(900, Number(spec.duration || 2200)) + 120);
}

function stopCatalogPreview(preview = activeCatalogPreview){
  if (!(preview instanceof HTMLElement)) {
    window.clearTimeout(catalogPreviewTimer);
    catalogPreviewTimer = 0;
    activeCatalogPreview = null;
    return;
  }
  preview.querySelectorAll('.mgw-victory-catalog-stage').forEach(node => node.remove());
  preview.classList.remove('is-playing');
  if (activeCatalogPreview === preview) {
    window.clearTimeout(catalogPreviewTimer);
    catalogPreviewTimer = 0;
    activeCatalogPreview = null;
  }
}

function renderStoreSection(catalog){
  const panel = document.querySelector('.store-v2-content[data-store-v2-panel="profile"]');
  if (!(panel instanceof HTMLElement)) return;
  const active = currentVictoryEffectId();
  const signature = catalog.map(item => `${item.item_id}:${item.owned === true ? 1 : 0}`).join('|') + `|${active}`;
  let section = panel.querySelector('[data-profile-victory-effect-store-section]');
  const anchor = panel.querySelector('[data-profile-entry-effect-store-section]')
    || panel.querySelector('[data-profile-reaction-store-section]')
    || panel.querySelector('[data-profile-background-store-section]');

  if (section instanceof HTMLElement && anchor instanceof HTMLElement && section.previousElementSibling !== anchor) anchor.insertAdjacentElement('afterend', section);
  if (section instanceof HTMLElement && section.dataset.profileVictoryEffectSignature === signature) {
    bindStoreActions(section);
    return;
  }

  if (section instanceof HTMLElement && activeCatalogPreview && section.contains(activeCatalogPreview)) stopCatalogPreview();
  const markup = `<section class="store-v2-entry-effect-section store-v2-victory-effect-section" data-profile-victory-effect-store-section data-profile-victory-effect-signature="${escapeAttr(signature)}"><div class="store-v2-title-row"><h2>Эффекты победы</h2></div><div class="store-v2-entry-effect-grid store-v2-victory-effect-grid">${catalog.map(item => storeCard(item, active)).join('')}</div></section>`;

  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (anchor instanceof HTMLElement) anchor.insertAdjacentHTML('afterend', markup);
  else panel.insertAdjacentHTML('beforeend', markup);
  section = panel.querySelector('[data-profile-victory-effect-store-section]');
  bindStoreActions(section);
}

function storeCard(item, activeId){
  const itemId = String(item.item_id || '');
  const owned = item.owned === true;
  const active = owned && itemId === activeId;
  const stateName = active ? 'selected' : (owned ? 'owned' : 'available');
  return `<article class="store-v2-product store-v2-entry-effect-card store-v2-victory-effect-card mgw-profile-cosmetic-card${owned ? ' owned' : ''}${active ? ' equipped' : ''}" data-mgw-profile-cosmetic-state="${stateName}">${previewMarkup(itemId, active, 'store-v2-victory-effect-preview')}<div class="store-v2-victory-effect-copy"><strong>${escapeHtml(itemName(item))}</strong><small>${escapeHtml(itemTier(item))}</small></div><div class="store-v2-product-foot store-v2-entry-effect-foot store-v2-victory-effect-foot mgw-profile-cosmetic-foot">${owned ? (active ? '<b data-mgw-profile-cosmetic-status>Выбрано</b><button class="store-v2-equip active mgw-profile-cosmetic-action" data-victory-effect-unequip type="button">Снять</button>' : `<b data-mgw-profile-cosmetic-status>В коллекции</b><button class="store-v2-equip mgw-profile-cosmetic-action" data-victory-effect-equip="${escapeAttr(itemId)}" type="button">Выбрать</button>`) : `<b>${formatNumber(itemPrice(item))}</b><button class="store-v2-buy mgw-profile-cosmetic-action" data-victory-effect-buy="${escapeAttr(itemId)}" type="button">Купить</button>`}</div></article>`;
}

function bindStoreActions(section){
  if (!(section instanceof HTMLElement)) return;
  bindPreviewPlayback(section);
  section.querySelectorAll('[data-victory-effect-buy]').forEach(button => {
    if (button.dataset.victoryActionBound === '1') return;
    button.dataset.victoryActionBound = '1';
    button.addEventListener('click', () => openPurchase(String(button.dataset.victoryEffectBuy || '')));
  });
  section.querySelectorAll('[data-victory-effect-equip]').forEach(button => {
    if (button.dataset.victoryActionBound === '1') return;
    button.dataset.victoryActionBound = '1';
    button.addEventListener('click', () => void saveSelection(String(button.dataset.victoryEffectEquip || ''), false));
  });
  section.querySelectorAll('[data-victory-effect-unequip]').forEach(button => {
    if (button.dataset.victoryActionBound === '1') return;
    button.dataset.victoryActionBound = '1';
    button.addEventListener('click', () => void saveSelection(currentVictoryEffectId(), true));
  });
}

function renderProfileCollection(catalog){
  const collection = document.querySelector('#screen-profile .profile-v2-collection-section');
  if (!(collection instanceof HTMLElement)) return;
  const owned = catalog.filter(item => item.owned === true);
  let section = collection.querySelector('[data-profile-victory-effect-collection]');
  if (!owned.length) { section?.remove(); return; }

  const anchor = collection.querySelector('[data-profile-entry-effect-collection]')
    || collection.querySelector('[data-profile-reaction-collection]')
    || collection.querySelector('[data-profile-background-collection]');
  if (section instanceof HTMLElement && anchor instanceof HTMLElement && section.previousElementSibling !== anchor) anchor.insertAdjacentElement('afterend', section);

  const active = currentVictoryEffectId();
  const signature = owned.map(item => item.item_id).join('|') + `|${active}`;
  if (section instanceof HTMLElement && section.dataset.profileVictoryEffectSignature === signature) {
    bindProfileActions(section);
    return;
  }
  if (section instanceof HTMLElement && activeCatalogPreview && section.contains(activeCatalogPreview)) stopCatalogPreview();
  const markup = `<div class="profile-v2-entry-effect-collection profile-v2-victory-effect-collection" data-profile-victory-effect-collection data-profile-victory-effect-signature="${escapeAttr(signature)}" aria-label="Эффекты победы"><div class="profile-v2-collection-title">Эффекты победы</div><div class="profile-v2-entry-effect-grid profile-v2-victory-effect-grid">${owned.map(item => profileCard(item, active)).join('')}</div></div>`;

  if (section instanceof HTMLElement) section.outerHTML = markup;
  else if (anchor instanceof HTMLElement) anchor.insertAdjacentHTML('afterend', markup);
  else collection.insertAdjacentHTML('beforeend', markup);
  section = collection.querySelector('[data-profile-victory-effect-collection]');
  bindProfileActions(section);
}

function bindProfileActions(section){
  if (!(section instanceof HTMLElement)) return;
  bindPreviewPlayback(section);
  section.querySelectorAll('[data-victory-effect-preview]').forEach(button => {
    if (button.dataset.victoryProfileBound === '1') return;
    button.dataset.victoryProfileBound = '1';
    button.addEventListener('click', () => openPreview(String(button.dataset.victoryEffectPreview || '')));
  });
}

function profileCard(item, activeId){
  const itemId = String(item.item_id || '');
  const active = itemId === activeId;
  return `<button class="profile-v2-entry-effect-card profile-v2-victory-effect-card${active ? ' active' : ''}" type="button" data-victory-effect-preview="${escapeAttr(itemId)}" data-mgw-profile-cosmetic-state="${active ? 'selected' : 'owned'}" aria-pressed="${active ? 'true' : 'false'}">${previewMarkup(itemId, false, 'profile-v2-victory-effect-preview')}<span class="profile-v2-entry-effect-copy profile-v2-victory-effect-copy"><b>${escapeHtml(itemName(item))}</b><small>${escapeHtml(itemTier(item))}</small></span>${active ? '<i class="profile-v2-selected-check" aria-hidden="true">✓</i>' : ''}</button>`;
}

function openPurchase(itemId){
  const item = victoryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned !== true);
  if (!item || purchasePending.has(itemId)) return;
  stopCatalogPreview();
  const price = itemPrice(item);
  const balance = Number(state.user?.balance || 0);
  const missing = Math.max(0, price - balance);
  openSheet(`<div class="sheet-head"><div><h2>Подтвердить покупку</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="store-v2-confirm"><div class="mgw-entry-effect-sheet-preview mgw-victory-effect-sheet-preview">${previewMarkup(itemId, false, 'mgw-victory-effect-sheet-card')}</div><div class="store-v2-confirm-copy"><strong>${escapeHtml(itemName(item))}</strong><small>Эффект победы</small></div><div class="store-v2-confirm-price"><span>К оплате</span><strong>${formatNumber(price)} коинов</strong></div><div class="store-v2-confirm-balance"><span>Останется</span><b>${formatNumber(Math.max(0, balance - price))}</b></div><button class="btn primary full" id="mgwVictoryEffectConfirmBuy" type="button"${missing > 0 ? ' disabled' : ''}>${missing > 0 ? `Не хватает ${formatNumber(missing)}` : `Купить за ${formatNumber(price)}`}</button></div>`);
  bindPreviewPlayback(document.getElementById('sheet'));
  document.getElementById('mgwVictoryEffectConfirmBuy')?.addEventListener('click', () => void purchase(item));
}

async function purchase(item){
  const itemId = String(item?.item_id || '');
  if (!itemId || purchasePending.has(itemId)) return;
  const previous = cloneObject(state.profileInventory);
  purchasePending.add(itemId);
  applyOptimisticPurchase(itemId);
  stopCatalogPreview();
  closeSheet();
  scheduleDecorate();
  try {
    const result = await api.cosmeticStorePurchase(itemOfferId(item), purchaseToken());
    const balance = Number(result?.store?.balance);
    if (Number.isFinite(balance) && state.user && typeof state.user === 'object') {
      state.user = { ...state.user, balance };
      renderBalances(state.user);
    }
    await refreshSnapshot();
    toast('Эффект победы добавлен в коллекцию.');
  } catch (error) {
    state.profileInventory = previous;
    scheduleDecorate();
    toast(error?.message || 'Не удалось купить эффект победы.');
  } finally {
    purchasePending.delete(itemId);
  }
}

function applyOptimisticPurchase(itemId){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  if (Array.isArray(inventory.catalog)) inventory.catalog = inventory.catalog.map(item => String(item?.item_id || '') === itemId ? { ...item, owned:true } : item);
  state.profileInventory = inventory;
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ family:'victory_effect', item_id:itemId, reason:'purchase-optimistic' } }));
}

function openPreview(itemId){
  const item = victoryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  if (!item) return;
  stopCatalogPreview();
  const active = itemId === currentVictoryEffectId();
  openSheet(`<div class="sheet-head"><div><h2>${escapeHtml(itemName(item))}</h2></div><button class="close" data-close-sheet type="button">×</button></div><div class="mgw-entry-effect-sheet-preview mgw-victory-effect-sheet-preview">${previewMarkup(itemId, false, 'mgw-victory-effect-sheet-card')}</div><div class="profile-v2-entry-effect-preview-meta"><strong>Эффект победы</strong></div><div class="mgw-profile-cosmetic-sheet-status" data-mgw-profile-cosmetic-sheet-status>${active ? 'Выбрано' : 'В коллекции'}</div><button class="btn ${active ? 'ghost' : 'primary'} full mgw-profile-cosmetic-sheet-action" id="mgwVictoryEffectEquip" type="button">${active ? 'Снять' : 'Выбрать'}</button>`);
  bindPreviewPlayback(document.getElementById('sheet'));
  document.getElementById('mgwVictoryEffectEquip')?.addEventListener('click', () => void saveSelection(itemId, active));
}

async function saveSelection(itemId, remove){
  if (equipBusy) return;
  const item = victoryEffectCatalog().find(candidate => candidate.item_id === itemId && candidate.owned === true);
  const active = itemId === currentVictoryEffectId();
  if (!item || (remove && !active) || (!remove && active)) return;

  const previous = cloneObject(state.profileInventory);
  equipBusy = true;
  applyOptimisticSelection(itemId, !remove);
  stopCatalogPreview();
  closeSheet();
  scheduleDecorate();
  try {
    if (remove) await api.cosmeticStoreUnequip(VICTORY_EFFECT_SLOT);
    else await api.cosmeticStoreEquip(itemId);
    await refreshSnapshot();
  } catch (error) {
    state.profileInventory = previous;
    scheduleDecorate();
    toast(error?.message || (remove ? 'Не удалось снять эффект победы.' : 'Не удалось выбрать эффект победы.'));
  } finally {
    equipBusy = false;
  }
}

function applyOptimisticSelection(itemId, equipped){
  const inventory = cloneObject(state.profileInventory) || { catalog:[], owned:[], equipped:{} };
  inventory.equipped = { ...(inventory.equipped || {}) };
  if (equipped) inventory.equipped[VICTORY_EFFECT_SLOT] = itemId;
  else delete inventory.equipped[VICTORY_EFFECT_SLOT];
  if (Array.isArray(inventory.catalog)) inventory.catalog = inventory.catalog.map(item => String(item?.equip_slot || '') === VICTORY_EFFECT_SLOT ? { ...item, equipped:equipped && String(item.item_id || '') === itemId } : item);
  state.profileInventory = inventory;
  document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed', { detail:{ slot:VICTORY_EFFECT_SLOT } }));
}

function scheduleResultProbe(){ queueMicrotask(playVictoryEffectIfReady); }

function scheduleResultProbeAfterPaint(){
  scheduleResultProbe();
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(playVictoryEffectIfReady);
}

function playVictoryEffectIfReady(){
  const summary = document.querySelector('#resultSummary[data-result-game-id]');
  if (!(summary instanceof HTMLElement)) {
    removeLiveVictoryEffect();
    return;
  }

  const game = state.activeGame;
  const gameId = String(game?.id || '').trim();
  if (!gameId || String(summary.dataset.resultGameId || '') !== gameId) return;
  if (String(game?.status || '') !== 'finished' || playedGames.has(gameId)) return;
  if (document.querySelector('#sheet [aria-busy="true"],#sheet button:disabled')) return;

  const selection = selectWinnerVictoryEffect(game);
  const spec = presentationFor(selection?.itemId || '');
  if (!selection || !spec) return;

  playedGames.add(gameId);
  while (playedGames.size > 80) playedGames.delete(playedGames.values().next().value);
  removeLiveVictoryEffect();

  const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
  const duration = reduced ? 2000 : Math.min(4000, Math.max(2000, Number(spec.duration || 2200)));
  const layer = document.createElement('div');
  layer.className = `mgw-victory-effect-layer${reduced ? ' reduced-motion' : ''}`;
  layer.dataset.victoryEffectGameId = gameId;
  layer.dataset.victoryEffectItemId = selection.itemId;
  layer.dataset.victoryEffectVariant = spec.variant;
  layer.innerHTML = `<button class="mgw-victory-effect-skip" type="button" aria-label="Пропустить эффект победы">Пропустить</button><div class="mgw-victory-effect-live-stage" data-victory-effect-variant="${escapeAttr(spec.variant)}" aria-hidden="true">${victoryStageMarkup(spec.variant)}</div>`;
  document.body.append(layer);

  layer.querySelector('.mgw-victory-effect-skip')?.addEventListener('click', removeLiveVictoryEffect, { once:true });
  window.clearTimeout(liveHideTimer);
  liveHideTimer = window.setTimeout(removeLiveVictoryEffect, duration);
}

function removeLiveVictoryEffect(){
  window.clearTimeout(liveHideTimer);
  liveHideTimer = 0;
  document.querySelectorAll('.mgw-victory-effect-layer').forEach(node => node.remove());
}

function purchaseToken(){
  if (globalThis.crypto?.randomUUID) return `store:${globalThis.crypto.randomUUID()}`;
  return `store:${Date.now().toString(36)}:${Math.random().toString(36).slice(2,14)}`;
}
function cloneObject(value){ return value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : value; }
function formatNumber(value){ return Number(value || 0).toLocaleString('ru-RU'); }
function escapeHtml(value){ return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
function escapeAttr(value){ return escapeHtml(value); }
