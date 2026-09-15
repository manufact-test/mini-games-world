const STYLE_ATTR = 'data-mgw-domino-store-card-fill-v5';
const STYLE_VALUE = 'mvp19-9-domino-card-fill-live-pips-v5';

export function installDominoStoreCardFillV5(){
  const href = new URL('../../css/games/domino/store-card-fill-live-pips-v5.css?v=1&mvp19_9=domino-card-fill-live-pips-v5', import.meta.url).href;
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
