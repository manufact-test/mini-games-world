const STYLE_ATTR = 'data-mgw-domino-store-effects-v9';
const STYLE_VALUE = 'mvp19-9-domino-premium-effects-v15-proportions';

export function installDominoStoreEffectsV9(){
  document.querySelectorAll('link[data-mgw-domino-store-effects-v7], link[data-mgw-domino-store-effects-v8]').forEach(node => node.remove());
  const href = new URL('../../css/games/domino/store-effects-scene-v9.css?v=7&mvp19_9=domino-premium-effects-v15-proportions', import.meta.url).href;
  const existing = document.querySelector(`link[${STYLE_ATTR}]`);
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.setAttribute(STYLE_ATTR, STYLE_VALUE);
    document.head.appendChild(existing);
    ensurePreviewComponentV44();
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.setAttribute(STYLE_ATTR, STYLE_VALUE);
  document.head.appendChild(link);
  ensurePreviewComponentV44();
}

function ensurePreviewComponentV44(){
  const href = new URL('../../css/games/domino/store-effects-preview-component-v44.css?v=4&mvp19_9=domino-svg-pips-v48', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-preview-component-v44]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoPreviewComponentV44 = 'mvp19-9-domino-svg-pips-v48';
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.dataset.mgwDominoPreviewComponentV44 = 'mvp19-9-domino-svg-pips-v48';
  document.head.appendChild(link);
}
