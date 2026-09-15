import {
  dominoPreviewMarkup,
  upgradeDominoStorePresentation,
} from './store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1';

const OBSERVED_ATTR = 'data-mgw-domino-rerender-stability-v1';
let observer = null;
let repairQueued = false;

export function installDominoStoreRerenderStabilityV1(){
  ensureObserver();
  repairDominoPreviews();
}

function ensureObserver(){
  if (typeof globalThis.MutationObserver !== 'function') return;
  if (!observer) {
    observer = new globalThis.MutationObserver(() => {
      queueRepairIfNeeded();
    });
  }

  observerHosts().forEach(host => {
    if (host.getAttribute(OBSERVED_ATTR) === '1') return;
    host.setAttribute(OBSERVED_ATTR, '1');
    observer.observe(host, { childList:true, subtree:true });
  });
}

function observerHosts(){
  return [
    document.getElementById('storeTabSurface'),
    document.getElementById('sheet'),
  ].filter(host => host instanceof HTMLElement);
}

function queueRepairIfNeeded(){
  if (repairQueued || !needsRepair()) return;
  repairQueued = true;
  queueMicrotask(() => {
    repairQueued = false;
    if (needsRepair()) repairDominoPreviews();
  });
}

function repairDominoPreviews(){
  let changed = false;
  storeRoots().forEach(root => {
    root.querySelectorAll('.store-v2-game-preview[data-game-type="domino"]').forEach(preview => {
      if (!(preview instanceof HTMLElement)) return;
      const layer = String(preview.dataset.cosmeticLayer || 'theme');
      const variant = String(preview.dataset.cosmeticVariant || 'felt');
      const visual = preview.querySelector(':scope > .mgw-domino-preview');
      const expectedClass = modeClass(layer, variant);
      if (visual instanceof HTMLElement && visual.classList.contains(expectedClass)) return;

      preview.dataset.mgwDominoPreview = `${layer}:${variant}:8x5:v4`;
      preview.innerHTML = dominoPreviewMarkup(layer, variant);
      changed = true;
    });
  });

  if (changed) upgradeDominoStorePresentation();
}

function needsRepair(){
  for (const root of storeRoots()) {
    const previews = root.querySelectorAll('.store-v2-game-preview[data-game-type="domino"]');
    for (const preview of previews) {
      if (!(preview instanceof HTMLElement)) continue;
      const layer = String(preview.dataset.cosmeticLayer || 'theme');
      const variant = String(preview.dataset.cosmeticVariant || 'felt');
      const visual = preview.querySelector(':scope > .mgw-domino-preview');
      if (!(visual instanceof HTMLElement) || !visual.classList.contains(modeClass(layer, variant))) return true;
    }
  }
  return false;
}

function storeRoots(){
  return [
    document.querySelector('[data-store-v2-panel="games"]'),
    document.getElementById('sheet'),
  ].filter(root => root instanceof HTMLElement);
}

function modeClass(layer, variant){
  const normalizedLayer = String(layer || 'theme');
  const normalizedVariant = safeVariant(variant || (normalizedLayer === 'elements' ? 'ivory' : (normalizedLayer === 'effect' ? 'precision-drop' : 'felt')));
  return normalizedLayer === 'theme'
    ? `theme-${normalizedVariant}`
    : (normalizedLayer === 'elements' ? `tiles-${normalizedVariant}` : `effect-${normalizedVariant}`);
}

function safeVariant(value){
  return String(value || '').replace(/[^a-z0-9-]/gi, '').toLowerCase() || 'felt';
}
