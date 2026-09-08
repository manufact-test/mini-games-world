const STYLE_ID = 'mgw-entry-v8-royal-ascension-style';
const STYLE_HREF = '/app/assets/css/entry-effects/mgw-entry-effects-v8-royal-ascension.css?v=3';
const ASSET_BASE = '/app/assets/media/cosmetics/entry-effects/v8/';
const FALLBACK_BODY = `${ASSET_BASE}entry-02-royal-ascension-body.svg?asset=royal-ascension-v3`;
const GUARDIAN_B64 = `${ASSET_BASE}entry-02-royal-ascension-guardian.webp.b64?asset=royal-ascension-guardian-v1`;
let guardianSrc = FALLBACK_BODY;

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

async function loadEncodedGuardian(){
  const response = await fetch(GUARDIAN_B64, { cache:'force-cache' });
  if (!response.ok) throw new Error(`guardian payload ${response.status}`);
  const encoded = (await response.text()).trim();
  if (!encoded.startsWith('kwfK') && !encoded.startsWith('UklG')) {
    throw new Error('guardian payload is not recognized WebP base64');
  }
  const raw = atob(encoded);
  const bytes = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i += 1) bytes[i] = raw.charCodeAt(i);
  const blob = new Blob([bytes], { type:'image/webp' });
  if (blob.size < 30_000) throw new Error('guardian payload unexpectedly small');
  return URL.createObjectURL(blob);
}

function img(className, src){
  const node = document.createElement('img');
  node.className = className;
  node.src = src;
  node.alt = '';
  node.decoding = 'async';
  node.loading = 'eager';
  node.setAttribute('aria-hidden', 'true');
  return node;
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

  const veil = document.createElement('div');
  veil.className = 'mgw-entry-v8-ra-veil';
  stage.append(veil);

  const beam = document.createElement('div');
  beam.className = 'mgw-entry-v8-ra-beam';
  stage.append(beam);

  const halo = document.createElement('div');
  halo.className = 'mgw-entry-v8-ra-halo';
  stage.append(halo);

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
  for (const side of ['left','right']) {
    const banner = document.createElement('div');
    banner.className = `mgw-entry-v8-ra-banner mgw-entry-v8-ra-banner--${side}`;
    banners.append(banner);
  }
  stage.append(banners);

  const figure = document.createElement('div');
  figure.className = 'mgw-entry-v8-ra-figure';
  figure.append(img('mgw-entry-v8-ra-guardian', guardianSrc));
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

async function arm(){
  try {
    guardianSrc = await loadEncodedGuardian();
  } catch (_) {
    guardianSrc = FALLBACK_BODY;
  }
  const preload = new Image();
  preload.decoding = 'async';
  preload.src = guardianSrc;
  scan(document);
  const observer = new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) if (node instanceof HTMLElement) scan(node);
    }
  });
  observer.observe(document.documentElement, { childList:true, subtree:true });
}

ensureStyle().then(arm).catch(() => {
  // Preserve current v7 Entry 02 if the Royal Ascension presentation cannot load.
});
