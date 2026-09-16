import fs from 'node:fs';
import assert from 'node:assert/strict';

const css = fs.readFileSync('app/assets/css/games/domino/store-effects-timing-parity-v18.css', 'utf8');
const index = fs.readFileSync('app/index.html', 'utf8');
const launch = fs.readFileSync('bot/helpers/WebAppLaunchUrl.php', 'utf8');

assert.match(index, /store-effects-timing-parity-v18\.css\?v=1&mvp19_9=domino-timing-parity-v18/);
assert.match(index, /data-mgw-domino-effects-timing-parity="v18"/);
assert.match(launch, /\/app\/v110\.php\?v=1173/);

assert.match(css, /store-v2-game-product\[data-store-game-product="domino"\][\s\S]*transform:scale\(\.88\)!important/);
assert.match(css, /store-v2-game-preview\[data-game-type="domino"\]\[data-cosmetic-layer="effect"\][\s\S]*transform:scale\(\.88\)!important/);
assert.ok(!css.includes('scale(.78)!important'), 'v18 must remove the Store-only .78 scene divergence');

assert.match(css, /@keyframes mgw-domino-v18-precision-flight/);
assert.match(css, /54%\{opacity:1;transform:translate3d\(0,-50%,0\)/);
assert.match(css, /mgw-domino-v18-precision-wave 3s/);
assert.match(css, /mgw-domino-v18-precision-spark 3s/);
assert.match(css, /88%\{opacity:1;transform:translate3d\(0,-50%,0\)/);

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

console.log('MVP-19.9 Domino timing/parity v18 contract: OK');
