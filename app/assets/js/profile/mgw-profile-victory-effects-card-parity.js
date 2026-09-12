import { initMgwProfileVictoryEffects as initBaseVictoryEffects } from './mgw-profile-victory-effects-v4.js?v=1&mvp19_3=victory-nova&preview=on-demand-v1';

let profileTabPreserverInstalled = false;

export function initMgwProfileVictoryEffects(){
  initBaseVictoryEffects();
  ensureCardParityStylesheet();
  ensureVisualRepairStylesheet();
  ensureAvatarGeometryStylesheet();
  ensureFireworkSalvoStylesheet();
  ensureVictoryTierSwapStylesheet();
  ensureVictoryNovaStylesheet();
  preserveVictoryCollectionAcrossProfileGameTabs();
}

function preserveVictoryCollectionAcrossProfileGameTabs(){
  if (profileTabPreserverInstalled) return;
  profileTabPreserverInstalled = true;

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const tab = target?.closest('#screen-profile [data-profile-game-tab]');
    if (!(tab instanceof HTMLElement)) return;

    const section = document.querySelector('#screen-profile [data-profile-victory-effect-collection]');
    if (!(section instanceof HTMLElement)) return;

    queueMicrotask(() => {
      if (section.isConnected) return;
      const collection = document.querySelector('#screen-profile .profile-v2-collection-section');
      if (!(collection instanceof HTMLElement)) return;

      const anchor = collection.querySelector('[data-profile-entry-effect-collection]')
        || collection.querySelector('[data-profile-reaction-collection]')
        || collection.querySelector('[data-profile-background-collection]');
      if (anchor instanceof HTMLElement) anchor.insertAdjacentElement('afterend', section);
      else collection.appendChild(section);
    });
  }, { capture:true });
}

function ensureCardParityStylesheet(){
  if (document.querySelector('link[data-mgw-victory-card-parity-css]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwVictoryCardParityCss = 'v3';
  link.href = new URL('../../css/production-v110-victory-effects-card-parity.css?v=3&mvp19_3=avatar-source-parity-repair', import.meta.url).href;
  document.head.append(link);
}

function ensureVisualRepairStylesheet(){
  if (document.querySelector('link[data-mgw-profile-store-visual-repair-v2]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileStoreVisualRepairV2 = '3';
  link.href = new URL('../../css/production-v110-profile-store-visual-repair-v2.css?v=3&mvp19_3=profile-card-spacing-unify', import.meta.url).href;
  document.head.append(link);
}

function ensureAvatarGeometryStylesheet(){
  if (document.querySelector('link[data-mgw-profile-avatar-geometry]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwProfileAvatarGeometry = '4';
  link.href = new URL('../../css/production-v111-profile-avatar-geometry.css?v=4&mvp19_3=content-flow-sheet-copy-final', import.meta.url).href;
  document.head.append(link);
}

function ensureFireworkSalvoStylesheet(){
  if (document.querySelector('link[data-mgw-victory-firework-salvo-css]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwVictoryFireworkSalvoCss = 'v1';
  link.href = new URL('../../css/production-v112-victory-effects-firework-salvo.css?v=1&mvp19_3=firework-salvo', import.meta.url).href;
  document.head.append(link);
}

function ensureVictoryTierSwapStylesheet(){
  if (document.querySelector('link[data-mgw-victory-tier-swap-css]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwVictoryTierSwapCss = 'v1';
  link.href = new URL('../../css/production-v113-victory-effects-tier-swap.css?v=1&mvp19_3=swap-01-02', import.meta.url).href;
  document.head.append(link);
}

function ensureVictoryNovaStylesheet(){
  if (document.querySelector('link[data-mgw-victory-nova-css]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwVictoryNovaCss = 'v1';
  link.href = new URL('../../css/production-v114-victory-effects-victory-nova.css?v=1&mvp19_3=victory-nova', import.meta.url).href;
  document.head.append(link);
}
