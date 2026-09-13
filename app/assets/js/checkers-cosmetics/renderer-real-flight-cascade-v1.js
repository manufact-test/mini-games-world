import {
  renderCheckersSurface as renderSingleFlightSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './renderer-single-flight-v1.js?v=2&mvp19_6=single-flight-dom-v1&cascade=real-flight-v1';

ensureLiveEffectCascadeStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

export function renderCheckersSurface(args){
  return renderSingleFlightSurface(args);
}

/* Asset-delivery shim only. The actual root-cause correction lives in
   live-effects-store-parity-v1.css: the base move-impact suppression no longer
   matches the real FLIP checker. This wrapper gives Telegram a fresh stylesheet
   URL without changing rules, geometry, timers, settlement or the single-flight
   renderer owner. */
function ensureLiveEffectCascadeStyles(){
  const href = new URL('../../css/games/checkers/live-effects-store-parity-v1.css?v=5&mvp19_6=real-piece-flight-cascade-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-live-effects]');

  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwCheckersLiveEffects = 'mvp19-6-real-piece-flight-cascade-v5';
    document.head.appendChild(existing);
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersLiveEffects = 'mvp19-6-real-piece-flight-cascade-v5';
  link.href = href;
  document.head.appendChild(link);
}
