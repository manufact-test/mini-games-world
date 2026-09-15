import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const store = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-store-v1.js'), 'utf8');
const css = readFileSync(resolve(root, 'app/assets/css/games/domino/store-cosmetics-v1.css'), 'utf8');
const correctiveCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-card-fill-live-pips-v5.css'), 'utf8');
const wrapper = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-checkers-board-source-wrapper.js'), 'utf8');
const correctiveLoader = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-card-fill-v5.js'), 'utf8');
const manifest = readFileSync(resolve(root, 'app/runtime/client/version-manifest.php'), 'utf8');
const launch = readFileSync(resolve(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(store.includes('headBackMarkup'), 'Domino header must use the dedicated split-back primitive.');
expect(store.includes('[[6,3],[3,5],[5,2]]'), 'Static table/tile previews must use three larger horizontal tiles.');
expect(store.includes('8x5:v4'), 'Store preview signature must publish uniform readability v4.');
expect(store.includes('domino-uniform-fullfield-v4'), 'Store must load the v4 full-field Domino stylesheet.');
expect(store.includes('цельной древесной игровой поверхностью'), 'Walnut copy must describe a full wood playing surface.');
expect(!store.includes('зелёной игровой вставкой'), 'Walnut preview must not retain the old green-insert concept.');
expect(!store.includes('<b>DOMINO</b>'), 'Preview field must not render a DOMINO label.');

expect(css.includes('aspect-ratio:8 / 5!important'), 'Base Domino preview primitive must retain 8:5 geometry where explicitly used.');
expect(css.includes('aspect-ratio:47 / 24'), 'Domino tiles must keep authentic live proportions.');
expect(css.includes('border-radius:3px'), 'Domino tile corners must stay restrained rather than capsule-like.');
expect(css.includes('inset:1px'), 'Table surface must fill the primitive with only a minimal outer rim.');
expect(css.includes('grid-template-columns:repeat(3,minmax(0,1fr))'), 'Static Domino tiles must be three exactly equal grid columns.');
expect(css.includes('.mgw-domino-stock{position:relative;display:block;width:31.5%;height:auto;aspect-ratio:47 / 24'), 'Face-down stock tiles must use the same 47:24 geometry as face-up tiles.');
expect(css.includes('theme-walnut .mgw-domino-preview-table'), 'Walnut table must have a dedicated full-surface material.');
expect(css.includes('mgw-domino-head-back>i:first-child'), 'Header backs must visibly split through the centre.');
expect(css.includes('mgw-domino-head-back>i::after{display:none!important;content:none!important}'), 'Header backs must not contain decorative circles/insets.');

expect(correctiveCss.includes('height:100%!important'), 'Mobile Domino Store product preview must fill the complete preview column height.');
expect(correctiveCss.includes('aspect-ratio:auto!important'), 'Mobile Domino Store product preview must not be constrained to 8:5.');
expect(correctiveCss.includes('.store-v2-confirm-game .store-v2-game-preview[data-game-type="domino"]'), 'Purchase confirmation must own a separate Domino preview rule.');
expect(correctiveCss.includes('aspect-ratio:8 / 5!important'), 'Purchase confirmation must remain a wide 8:5 Domino preview.');
expect(correctiveCss.includes('width:4px!important'), 'Domino pips must use a stable visible diameter.');
expect(correctiveCss.includes('height:4px!important'), 'Domino pips must use a stable visible diameter.');
expect(correctiveCss.includes('border-radius:999px!important'), 'Domino pips must be true circles.');
expect(correctiveCss.includes('transform:none!important'), 'Domino pips must not be distorted by transforms.');
expect(correctiveLoader.includes('store-card-fill-live-pips-v5.css?v=1&mvp19_9=domino-card-fill-live-pips-v5'), 'Corrective loader must use a unique v5 stylesheet URL.');
expect(wrapper.includes("import { installDominoStoreCardFillV5 } from './store-screen-domino-card-fill-v5.js?v=1&mvp19_9=domino-card-fill-live-pips-v5';"), 'Accepted Store owner must import the Domino v5 corrective.');
expect(wrapper.includes('installDominoStoreCardFillV5();'), 'Accepted Store owner must install the Domino v5 corrective.');

expect(manifest.includes('domino_preview=card-fill-live-pips-v5'), 'Active Store owner URL must be cache-busted for Domino v5.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1' => './assets/js/screens/store-screen-domino-store-v1.js?v=4&mvp19_9=domino-uniform-fullfield-v4'"), 'Active import map must continue to resolve the accepted Domino primitive to v4.');
expect(!manifest.includes('domino_preview=expanded-readable-v3'), 'Active Store owner must not regress to stale Domino v3.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1160, 'Telegram entry must publish the Domino v5 cache bump.');

console.log('MVP-19.9 Domino Store card-fill and round-pip v5 contract passed.');
