const STYLE_ATTR = 'data-mgw-domino-store-effects-v8';
const STYLE_VALUE = 'mvp19-9-domino-store-effects-smooth-v8';

export function installDominoStoreEffectsV8(){
  document.querySelectorAll('link[data-mgw-domino-store-effects-v7]').forEach(node => node.remove());
  const href = new URL('../../css/games/domino/store-effects-smooth-v8.css?v=1&mvp19_9=domino-store-effects-smooth-v8', import.meta.url).href;
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
