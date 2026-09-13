import {
  renderCheckersSurface as renderAcceptedCheckersSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './renderer-real-flight-cascade-v1.js?v=2&mvp19_6=all-paid-real-flight-v1&parent=single-flight-dom-v2&css=live-effects-v6&move=trail-only-v1';

ensureGraniteStoreParityStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

export function renderCheckersSurface(args){
  ensureGraniteStoreParityStyles();
  return renderAcceptedCheckersSurface(args);
}

function ensureGraniteStoreParityStyles(){
  const href = new URL('../../css/games/checkers/live-pieces-store-parity-v1.css?v=4&mvp19_6=granite-store-parity-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-live-pieces]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwCheckersLivePieces = 'mvp19-6-granite-store-parity-v4';
    document.head.appendChild(existing);
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersLivePieces = 'mvp19-6-granite-store-parity-v4';
  link.href = href;
  document.head.appendChild(link);
}
