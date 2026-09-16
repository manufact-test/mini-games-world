import fs from 'node:fs';
import assert from 'node:assert/strict';

const css = fs.readFileSync('app/assets/css/games/domino/store-effects-smooth-preview-v17.css', 'utf8');
const index = fs.readFileSync('app/index.html', 'utf8');
const launch = fs.readFileSync('bot/helpers/WebAppLaunchUrl.php', 'utf8');
const v15 = fs.readFileSync('app/assets/css/games/domino/store-effects-scene-v9.css', 'utf8');

assert.match(index, /store-effects-smooth-preview-v17\.css\?v=1&mvp19_9=domino-smooth-preview-v17/);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1172, 'Telegram entry must keep v17 cache-bust or a newer successor');

assert.match(css, /store-v2-game-product\[data-store-game-product="domino"\][\s\S]*transform:scale\(\.78\)!important/);
assert.match(css, /data-cosmetic-variant="chain-finale"[\s\S]*border-radius:2px!important/);

assert.match(css, /@keyframes mgw-domino-v17-precision-flight/);
assert.match(css, /animation:mgw-domino-v17-precision-flight 2\.9s/);
assert.match(css, /16%\{opacity:1;transform:translate3d\(30%,-220%,0\)/);
assert.match(css, /60%\{opacity:1;transform:translate3d\(0,-50%,0\)/);

assert.match(css, /@keyframes mgw-domino-v17-stock-flight/);
assert.match(css, /animation:mgw-domino-v17-stock-flight 3\.15s/);
assert.match(css, /16%\{opacity:1;transform:translate3d\(0,0,0\)/);
assert.match(css, /68%\{opacity:1;transform:translate3d\(155%,88%,0\)/);
assert.match(css, /mgw-domino-v17-stock-back/);
assert.match(css, /mgw-domino-v17-stock-face/);

assert.match(v15, /@keyframes mgw-domino-v15-cascade-tile/);
assert.ok(!css.includes('@keyframes mgw-domino-v17-cascade'), 'v17 must not redesign the accepted cascade concept');
assert.ok(!css.includes('setTimeout('));
assert.ok(!css.includes('MutationObserver'));

console.log('MVP-19.9 Domino smooth preview v17 contract: OK');
