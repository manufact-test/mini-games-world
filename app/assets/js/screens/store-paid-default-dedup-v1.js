const INSTALL_KEY = '__mgwPaidDefaultDedupV2Installed';

export function installPaidDefaultDedupV1(){
  ensureStyles();
  if (globalThis[INSTALL_KEY]) return;
  globalThis[INSTALL_KEY] = true;
}

export function upgradePaidDefaultDedupV1(){
  ensureStyles();
}

function ensureStyles(){
  const href = new URL('../../css/games/paid-default-dedup-v1.css?v=1&paid_default=dedup-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-paid-default-dedup]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwPaidDefaultDedup = 'v2';
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwPaidDefaultDedup = 'v2';
  link.href = href;
  document.head.appendChild(link);
}
