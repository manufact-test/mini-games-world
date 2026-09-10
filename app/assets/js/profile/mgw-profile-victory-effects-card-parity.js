import { initMgwProfileVictoryEffects as initBaseVictoryEffects } from './mgw-profile-victory-effects.js?v=2&mvp19_3=spark-burst-visual-parity';

export function initMgwProfileVictoryEffects(){
  initBaseVictoryEffects();
  ensureCardParityStylesheet();
}

function ensureCardParityStylesheet(){
  if (document.querySelector('link[data-mgw-victory-card-parity-css]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwVictoryCardParityCss = 'v3';
  link.href = new URL('../../css/production-v110-victory-effects-card-parity.css?v=3&mvp19_3=avatar-source-parity-repair', import.meta.url).href;
  document.head.append(link);
}
