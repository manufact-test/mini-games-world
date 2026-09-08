const STYLE_ID = 'mgw-entry-v8-royal-ascension-style';
const STYLE_HREF = '/app/assets/css/entry-effects/mgw-entry-effects-v8-royal-ascension.css?v=4';
const ASSET_BASE = '/app/assets/media/cosmetics/entry-effects/v8/';
const GUARDIAN_SRC = `${ASSET_BASE}entry-02-royal-ascension-guardian.webp?asset=royal-ascension-guardian-v2`;

function ensureStyle(){
  const existing = document.getElementById(STYLE_ID);
  if (existing instanceof HTMLLinkElement) {
    if (existing.sheet) return Promise.resolve();
    return new Promise((resolve, reject) => {
      existing.addEventListener('load', resolve, { once:true });
      existing.addEventListener('error', reject, { once:true });
    });
  }
  return new Promise((resolve, reject) => {
    const link = document.createElement('link');
    link.id = STYLE_ID;
    link.rel = 'stylesheet';
    link.href = STYLE_HREF;
    link.addEventListener('load', resolve, { once:true });
    link.addEventListener('error', reject, { once:true });
    document.head.append(link);
  });
}

function img(className, src){
  const node = document.createElement('img');
  node.className = className;
  node.src = src;
  node.alt = '';
  node.decoding = 'async';
  node.loading = 'eager';
  node.fetchPriority = 'high';
  node.setAttribute('aria-hidden', 'true');
  return node;
}

async function ensureGuardian(){
  const preload = new Image();
  preload.decoding = 'async';
  preload.src = GUARDIAN_SRC;
  if (typeof preload.decode === 'function') await preload.decode();
  else await new Promise((resolve, reject) => {
    preload.addEventListener('load', resolve, { once:true });
    preload.addEventListener('error', reject, { once:true });
  });
  if (preload.naturalWidth < 320 || preload.naturalHeight < 320) {
    throw new Error(`Royal Ascension guardian decode ${preload.naturalWidth}x${preload.naturalHeight}`);
  }
}

function appendMany(parent, className, count){
  for (let i = 0; i < count; i += 1) {
    const el = document.createElement('i');
    el.className = className;
    el.style.setProperty('--i', String(i));
    if (className === 'mgw-entry-v8-ra-rune') {
      el.style.transform = `translate(-50%,-100%) rotate(${i * 45}deg)`;
    }
    parent.append(el);
  }
}

function mountRoyalAscension(layer){
  if (!(layer instanceof HTMLElement) || !layer.classList.contains('mgw-entry-effect-layer')) return;
  const card = layer.querySelector('.mgw-entry-effect-live-card[data-entry-effect-variant="entry-02"]');
  if (!(card instanceof HTMLElement)) return;

  const playerIndex = String(card.dataset.playerIndex || '0');
  const key = `entry-02:${playerIndex}`;
  if (layer.querySelector(`.mgw-entry-v8-royal-ascension[data-entry-v8-key="${key}"]`)) return;

  const scene = document.createElement('div');
  scene.className = 'mgw-entry-v8-royal-ascension';
  scene.dataset.entryV8Key = key;
  scene.setAttribute('aria-hidden', 'true');

  const stage = document.createElement('div');
  stage.className = 'mgw-entry-v8-ra-stage';

  for (const className of ['mgw-entry-v8-ra-veil','mgw-entry-v8-ra-beam','mgw-entry-v8-ra-halo']) {
    const el = document.createElement('div');
    el.className = className;
    if (className === 'mgw-entry-v8-ra-halo') {
      el.style.zIndex = '3';
      el.style.mixBlendMode = 'screen';
      el.style.background = 'radial-gradient(circle,rgba(255,246,203,.20) 0 34%,rgba(238,187,72,.11) 50%,transparent 70%)';
      el.style.boxShadow = '0 0 38px rgba(255,218,116,.34),0 0 74px rgba(201,132,26,.18)';
    }
    stage.append(el);
  }

  const sigil = document.createElement('div');
  sigil.className = 'mgw-entry-v8-ra-sigil';
  for (const kind of ['outer','mid','inner']) {
    const ring = document.createElement('div');
    ring.className = `mgw-entry-v8-ra-ring mgw-entry-v8-ra-ring--${kind}`;
    sigil.append(ring);
  }
  const core = document.createElement('div');
  core.className = 'mgw-entry-v8-ra-core';
  sigil.append(core);
  appendMany(sigil, 'mgw-entry-v8-ra-rune', 8);
  stage.append(sigil);

  const banners = document.createElement('div');
  banners.className = 'mgw-entry-v8-ra-banners';
  banners.style.display = 'none';
  for (const side of ['left','right']) {
    const banner = document.createElement('div');
    banner.className = `mgw-entry-v8-ra-banner mgw-entry-v8-ra-banner--${side}`;
    banners.append(banner);
  }
  stage.append(banners);

  const figure = document.createElement('div');
  figure.className = 'mgw-entry-v8-ra-figure';
  figure.append(img('mgw-entry-v8-ra-guardian', GUARDIAN_SRC));
  const sweep = document.createElement('div');
  sweep.className = 'mgw-entry-v8-ra-armor-sweep';
  const crownFlare = document.createElement('div');
  crownFlare.className = 'mgw-entry-v8-ra-crown-flare';
  figure.append(sweep, crownFlare);
  stage.append(figure);

  const pulse = document.createElement('div');
  pulse.className = 'mgw-entry-v8-ra-pulse';
  stage.append(pulse);

  const rays = document.createElement('div');
  rays.className = 'mgw-entry-v8-ra-rays';
  appendMany(rays, 'mgw-entry-v8-ra-ray', 10);
  stage.append(rays);

  const particles = document.createElement('div');
  particles.className = 'mgw-entry-v8-ra-particles';
  appendMany(particles, 'mgw-entry-v8-ra-particle', 16);
  stage.append(particles);

  scene.append(stage);
  const grid = layer.querySelector('.mgw-entry-effect-live-grid');
  if (grid instanceof HTMLElement) layer.insertBefore(scene, grid);
  else layer.append(scene);
  layer.dataset.entryV8RoyalAscension = '1';
}

function scan(root = document){
  if (root instanceof HTMLElement && root.classList.contains('mgw-entry-effect-layer')) mountRoyalAscension(root);
  root.querySelectorAll?.('.mgw-entry-effect-layer').forEach(mountRoyalAscension);
}

function arm(){
  scan(document);
  const observer = new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) if (node instanceof HTMLElement) scan(node);
    }
  });
  observer.observe(document.documentElement, { childList:true, subtree:true });
}

Promise.all([ensureStyle(), ensureGuardian()]).then(arm).catch(() => {
  // Keep the existing v7 Entry 02 intact if the V8 art or stylesheet cannot load.
});
