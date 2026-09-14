import {
  renderReversiSurface,
  reversiMeta,
  reversiPlayerMark,
  reversiStatus,
} from './renderer-cosmetics-v1.js?v=3&mvp19_7=live-parity-store-motion-v3&pieces=viewer-complete-set-v1&effects=store-phased-real-cadence-v3&footer=fullwidth-scroll-v2';

ensurePremiumReversiEffectStyles();

export { renderReversiSurface, reversiMeta, reversiPlayerMark, reversiStatus };

function ensurePremiumReversiEffectStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/reversi/live-effects-premium-v4.css?v=2&mvp19_7=line-mass-timing-smooth-v5', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-reversi-live-effects-premium]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwReversiLiveEffectsPremium = 'mvp19-7-premium-v5';
  link.href = href;
  document.head.appendChild(link);
}
