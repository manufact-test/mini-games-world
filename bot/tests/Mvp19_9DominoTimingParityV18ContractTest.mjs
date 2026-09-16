import fs from 'node:fs';
import assert from 'node:assert/strict';

const css = fs.readFileSync('app/assets/css/games/domino/store-effects-timing-parity-v18.css', 'utf8');
const alignment = fs.readFileSync('app/assets/css/games/domino/store-effects-precision-alignment-v19.css', 'utf8');
const cardLayout = fs.readFileSync('app/assets/css/games/domino/store-effects-card-fullwidth-v20.css', 'utf8');
const index = fs.readFileSync('app/index.html', 'utf8');
const launch = fs.readFileSync('bot/helpers/WebAppLaunchUrl.php', 'utf8');

assert.match(index, /store-effects-timing-parity-v18\.css\?v=1&mvp19_9=domino-timing-parity-v18/);
assert.match(index, /data-mgw-domino-effects-timing-parity="v18"/);
assert.match(index, /store-effects-precision-alignment-v19\.css\?v=1&mvp19_9=domino-precision-alignment-v19/);
assert.match(index, /data-mgw-domino-effects-precision-alignment="v19"/);
assert.match(index, /store-effects-card-fullwidth-v20\.css\?v=1&mvp19_9=domino-effect-card-fullwidth-v20/);
assert.match(index, /data-mgw-domino-effects-card-fullwidth="v20"/);
assert.match(launch, /\/app\/v110\.php\?v=1176/);

assert.match(css, /store-v2-game-product\[data-store-game-product="domino"\][\s\S]*transform:scale\(\.88\)!important/);
assert.match(css, /store-v2-game-preview\[data-game-type="domino"\]\[data-cosmetic-layer="effect"\][\s\S]*transform:scale\(\.88\)!important/);
assert.ok(!css.includes('scale(.78)!important'), 'v18 must remove the Store-only .78 scene divergence');

assert.match(css, /@keyframes mgw-domino-v18-precision-flight/);
assert.match(css, /54%\{opacity:1;transform:translate3d\(0,-50%,0\)/);
assert.match(css, /mgw-domino-v18-precision-wave 3s/);
assert.match(css, /mgw-domino-v18-precision-spark 3s/);
assert.match(css, /88%\{opacity:1;transform:translate3d\(0,-50%,0\)/);

assert.match(alignment, /data-cosmetic-variant="precision-drop"/);
assert.match(alignment, /mgw-domino-v13-impact-piece/);
assert.match(alignment, /mgw-domino-v13-impact-ring/);
assert.match(alignment, /mgw-domino-v13-impact-sparks/);
assert.match(alignment, /top:58\.2%!important/);
assert.ok(!alignment.includes('@keyframes'), 'v19 alignment must not redesign accepted timing');
assert.ok(!alignment.includes('scale('), 'v19 alignment must not change accepted effect scale');

assert.match(cardLayout, /:has\(> \.store-v2-game-preview\[data-game-type="domino"\]\[data-cosmetic-layer="effect"\]\)/);
assert.match(cardLayout, /@media \(max-width:719px\)/);
assert.match(cardLayout, /grid-template-columns:116px minmax\(0,1fr\)!important/);
assert.match(cardLayout, /grid-template-areas:"preview copy" "preview foot"!important/);
assert.match(cardLayout, /height:100%!important/);
assert.match(cardLayout, /min-height:104px!important/);
assert.match(cardLayout, /aspect-ratio:auto!important/);
assert.match(cardLayout, /grid-template-columns:101px minmax\(0,1fr\)!important/);
assert.ok(!cardLayout.includes('grid-template-areas:"preview" "copy" "foot"'), 'v21 must not keep the stacked v20 Store card');
assert.ok(!cardLayout.includes('@keyframes'), 'v21 is layout-only and must not change accepted animations');
assert.ok(!cardLayout.includes('animation:'), 'v21 is layout-only and must not take animation ownership');

assert.match(css, /@keyframes mgw-domino-v18-stock-flight/);
assert.match(css, /mgw-domino-v18-stock-source-pulse/);
assert.match(css, /mgw-domino-v18-stock-land-glow/);
assert.match(css, /background:radial-gradient\(ellipse,rgba\(147,255,220,.58\)/);

for (let index = 1; index <= 5; index += 1) {
  assert.match(css, new RegExp(`@keyframes mgw-domino-v18-cascade-${index}`));
}
assert.match(css, /animation-delay:0s!important/);
assert.match(css, /border-radius:1px!important/);
assert.match(css, /mgw-domino-v18-finish-dust 3\.5s/);

assert.ok(!css.includes('setTimeout('));
assert.ok(!css.includes('MutationObserver'));
assert.ok(!alignment.includes('setTimeout('));
assert.ok(!alignment.includes('MutationObserver'));
assert.ok(!cardLayout.includes('setTimeout('));
assert.ok(!cardLayout.includes('MutationObserver'));

console.log('MVP-19.9 Domino timing/parity v18 + Precision v19 + compact Store cards v21 contract: OK');
