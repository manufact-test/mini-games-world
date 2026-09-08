const STYLE_ID = 'mgw-entry-v8-legendary-strike-style';
const STYLE_HREF = '/app/assets/css/entry-effects/mgw-entry-effects-v8-legendary-strike.css?v=2';
const ASSET_BASE = '/app/assets/media/cosmetics/entry-effects/v8/';
const ASSETS = Object.freeze({
  arena: `${ASSET_BASE}entry-03-legendary-strike-arena.webp?asset=entry-v8-ls-1`,
  sword: `${ASSET_BASE}entry-03-legendary-strike-sword.svg?asset=entry-v8-ls-1`,
  cracks: `${ASSET_BASE}entry-03-legendary-strike-cracks.svg?asset=entry-v8-ls-1`,
});

const DEBRIS = Object.freeze([
  [-132,-84,-48,.86,17,13,'18% 5%,92% 22%,74% 91%,9% 72%'],
  [-98,-128,34,.72,13,18,'12% 18%,80% 4%,95% 70%,43% 96%,3% 68%'],
  [-54,-112,-76,.62,12,11,'28% 0,100% 31%,78% 100%,0 72%'],
  [62,-124,62,.76,15,12,'4% 29%,70% 0,100% 69%,38% 100%'],
  [108,-96,112,.92,19,14,'0 20%,88% 3%,100% 67%,57% 100%,9% 78%'],
  [142,-58,-95,.66,12,17,'15% 0,94% 23%,72% 100%,0 64%'],
  [-156,-26,88,.58,11,10,'0 15%,73% 0,100% 74%,34% 100%'],
  [164,-18,49,.64,14,10,'9% 0,100% 37%,73% 100%,0 71%'],
  [-118,-54,136,.48,9,9,'14% 0,100% 18%,79% 100%,0 62%'],
  [124,-44,-132,.52,10,9,'0 22%,85% 0,100% 68%,31% 100%'],
  [-78,-72,44,.45,8,12,'24% 0,100% 44%,62% 100%,0 69%'],
  [82,-78,-56,.46,9,12,'0 38%,69% 0,100% 76%,41% 100%'],
]);

const SPARKS = Object.freeze([
  [-148,-70,1.05],[-118,-114,.82],[-82,-92,1.2],[-42,-142,.72],
  [36,-146,.94],[78,-118,1.1],[116,-98,.78],[152,-62,1.18],
  [-172,-28,.68],[174,-20,.84],[-104,-42,.96],[96,-48,.74],
]);

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

function preload(){
  Object.values(ASSETS).forEach(src => {
    const image = new Image();
    image.decoding = 'async';
    image.src = src;
  });
}

function image(className, src){
  const node = document.createElement('img');
  node.className = className;
  node.src = src;
  node.alt = '';
  node.decoding = 'async';
  node.loading = 'eager';
  node.setAttribute('aria-hidden', 'true');
  return node;
}

function buildDebris(container){
  DEBRIS.forEach(([dx,dy,rot,scale,w,h,clip], index) => {
    const shard = document.createElement('i');
    shard.className = 'mgw-entry-v8-debris';
    shard.style.setProperty('--dx', `${dx}px`);
    shard.style.setProperty('--dy', `${dy}px`);
    shard.style.setProperty('--rot', `${rot}deg`);
    shard.style.setProperty('--scale', String(scale));
    shard.style.setProperty('--w', `${w}px`);
    shard.style.setProperty('--h', `${h}px`);
    shard.style.setProperty('--clip', `polygon(${clip})`);
    shard.style.setProperty('--delay', `${(index % 4) * 18}ms`);
    container.append(shard);
  });
}

function buildSparks(container){
  SPARKS.forEach(([dx,dy,scale], index) => {
    const spark = document.createElement('b');
    spark.className = 'mgw-entry-v8-spark';
    spark.style.setProperty('--sx', `${dx}px`);
    spark.style.setProperty('--sy', `${dy}px`);
    spark.style.setProperty('--ss', String(scale));
    spark.style.setProperty('--sd', `${index * 22}ms`);
    container.append(spark);
  });
}

// The V8 strike scene was visually rejected as the premium Entry 03 treatment.
// Preserve the implementation, but deliberately reassign it to Entry 01 as the
// base/cheapest tier while Entry 02 and Entry 03 remain on their existing v7
// fallback presentation until new storyboards are approved.
function mountLegendaryStrike(layer){
  if (!(layer instanceof HTMLElement) || !layer.classList.contains('mgw-entry-effect-layer')) return;
  const card = layer.querySelector('.mgw-entry-effect-live-card[data-entry-effect-variant="entry-01"]');
  if (!(card instanceof HTMLElement)) return;

  const playerIndex = String(card.dataset.playerIndex || '0');
  const key = `entry-01:${playerIndex}`;
  if (layer.querySelector(`.mgw-entry-v8-legendary-strike[data-entry-v8-key="${key}"]`)) return;

  const scene = document.createElement('div');
  scene.className = 'mgw-entry-v8-legendary-strike';
  scene.dataset.entryV8Key = key;
  scene.setAttribute('aria-hidden', 'true');

  const stage = document.createElement('div');
  stage.className = 'mgw-entry-v8-stage';
  stage.append(image('mgw-entry-v8-arena', ASSETS.arena));

  const shade = document.createElement('div');
  shade.className = 'mgw-entry-v8-shade';
  stage.append(shade);

  const cracks = image('mgw-entry-v8-cracks', ASSETS.cracks);
  stage.append(cracks);

  const shockwave = document.createElement('div');
  shockwave.className = 'mgw-entry-v8-shockwave';
  stage.append(shockwave);

  const dust = document.createElement('div');
  dust.className = 'mgw-entry-v8-dust';
  stage.append(dust);

  const debris = document.createElement('div');
  debris.className = 'mgw-entry-v8-debris-field';
  buildDebris(debris);
  stage.append(debris);

  const sword = image('mgw-entry-v8-sword', ASSETS.sword);
  stage.append(sword);

  const impact = document.createElement('div');
  impact.className = 'mgw-entry-v8-impact';
  stage.append(impact);

  const sparks = document.createElement('div');
  sparks.className = 'mgw-entry-v8-sparks';
  buildSparks(sparks);
  stage.append(sparks);

  scene.append(stage);
  const grid = layer.querySelector('.mgw-entry-effect-live-grid');
  if (grid instanceof HTMLElement) layer.insertBefore(scene, grid);
  else layer.append(scene);
  layer.dataset.entryV8LegendaryStrike = '1';
}

function scan(root = document){
  if (root instanceof HTMLElement && root.classList.contains('mgw-entry-effect-layer')) mountLegendaryStrike(root);
  root.querySelectorAll?.('.mgw-entry-effect-layer').forEach(mountLegendaryStrike);
}

function arm(){
  preload();
  scan(document);
  const observer = new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (node instanceof HTMLElement) scan(node);
      }
    }
  });
  observer.observe(document.documentElement, { childList:true, subtree:true });
}

ensureStyle().then(arm).catch(() => {
  // Keep the v7 fallback visible if the transferred V8 presentation stylesheet fails.
});
