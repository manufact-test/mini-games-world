const STYLE_ATTR = 'data-mgw-domino-store-effects-v9';
const STYLE_VALUE = 'mvp19-9-domino-premium-effects-v13';

export function installDominoStoreEffectsV9(){
  document.querySelectorAll('link[data-mgw-domino-store-effects-v7], link[data-mgw-domino-store-effects-v8]').forEach(node => node.remove());
  const href = new URL('../../css/games/domino/store-effects-scene-v9.css?v=5&mvp19_9=domino-premium-effects-v13', import.meta.url).href;
  const existing = document.querySelector(`link[${STYLE_ATTR}]`);
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.setAttribute(STYLE_ATTR, STYLE_VALUE);
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.setAttribute(STYLE_ATTR, STYLE_VALUE);
  document.head.appendChild(link);
}
