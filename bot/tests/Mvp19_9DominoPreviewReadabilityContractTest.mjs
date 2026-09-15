import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const store = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-store-v1.js'), 'utf8');
const css = readFileSync(resolve(root, 'app/assets/css/games/domino/store-cosmetics-v1.css'), 'utf8');
const correctiveCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-card-fill-live-pips-v5.css'), 'utf8');
const effectCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-effects-scene-v9.css'), 'utf8');
const wrapper = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-checkers-board-source-wrapper.js'), 'utf8');
const correctiveLoader = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-card-fill-v5.js'), 'utf8');
const effectLoader = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-effects-v9.js'), 'utf8');
const profile = readFileSync(resolve(root, 'app/assets/js/profile/mgw-profile-domino-parity.js'), 'utf8');
const hardRatio = readFileSync(resolve(root, 'app/assets/js/profile/mgw-profile-domino-hard-ratio-v1.js'), 'utf8');
const manifest = readFileSync(resolve(root, 'app/runtime/client/version-manifest.php'), 'utf8');
const launch = readFileSync(resolve(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(store.includes('headBackMarkup'), 'Domino header must use the dedicated split-back primitive.');
expect(store.includes('[[6,3],[3,5],[5,2]]'), 'Static table/tile previews must use three larger horizontal tiles.');
expect(store.includes('deterministic:v5'), 'Store preview signature must publish deterministic v5 rendering.');
expect(store.includes('domino-uniform-fullfield-v4'), 'Store must retain the accepted v4 full-field static stylesheet.');
expect(store.includes('цельной древесной игровой поверхностью'), 'Walnut copy must describe a full wood playing surface.');
expect(!store.includes('зелёной игровой вставкой'), 'Walnut preview must not retain the old green-insert concept.');
expect(!store.includes('<b>DOMINO</b>'), 'Preview field must not render a DOMINO label.');
expect(store.includes('visual.classList.contains(expectedClass)'), 'Every rerender must verify actual direct Domino markup, not trust a stale data signature.');
expect(!store.includes('requestAnimationFrame'), 'Domino Store deterministic repair must not use frame retries.');
expect(!store.includes('setTimeout'), 'Domino Store deterministic repair must not use timer retries.');
expect(!store.includes('MutationObserver'), 'Domino Store deterministic repair must not use MutationObserver.');

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
expect(correctiveCss.includes('place-self:center!important'), 'Domino pip placement slots must stay centered in their 3x3 cells.');
expect(correctiveCss.includes('width:4px!important'), 'Domino pip placement slot must remain stable.');
expect(correctiveCss.includes('.mgw-domino-preview-half i.active::after'), 'Visible Domino pips must be drawn as an inner disc.');
expect(correctiveCss.includes('width:3px!important'), 'Visible Domino pips must use the smaller 3px diameter.');
expect(correctiveCss.includes('height:3px!important'), 'Visible Domino pips must use the smaller 3px diameter.');
expect(correctiveCss.includes('border-radius:50%!important'), 'Visible Domino pips must be true circles.');
expect(correctiveCss.includes('transform:translate(-50%,-50%)!important'), 'Visible Domino pips must be centered in their placement slots.');

expect(effectCss.includes('width:27.5%!important'), 'Effect scenes must use readable equal-size Domino wrappers rather than tiny v8 tiles.');
expect(effectCss.includes('aspect-ratio:47 / 24!important'), 'Effect scenes must preserve the accepted tile aspect ratio.');
expect(effectCss.includes('mgw-domino-v9-precision-drop'), 'Precision Drop must have one continuous v9 motion.');
expect(effectCss.includes('mgw-domino-v9-stock-draw'), 'Stock Pulse must draw and flip one tile continuously.');
expect(effectCss.includes('mgw-domino-v9-chain-wave'), 'Chain Finale must use a continuous Domino chain reaction.');
expect(effectCss.includes('@media (prefers-reduced-motion:reduce)'), 'Effect scenes must keep a stable reduced-motion fallback.');
expect(!effectCss.includes('scale(.18'), 'Effect scenes must not crush tile width during flip animation.');

expect(correctiveLoader.includes('store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6'), 'Corrective loader must use the accepted cache-busted pip stylesheet URL.');
expect(effectLoader.includes('store-effects-scene-v9.css?v=1&mvp19_9=domino-store-effects-scene-v9'), 'Effect loader must publish scene v9.');
expect(effectLoader.includes('data-mgw-domino-store-effects-v8'), 'Effect loader must remove stale v8 CSS if it survived in the WebView.');
expect(wrapper.includes("import { installDominoStoreCardFillV5 } from './store-screen-domino-card-fill-v5.js?v=2&mvp19_9=domino-card-fill-live-pips-v6';"), 'Accepted Store owner must retain the pip corrective.');
expect(wrapper.includes("from './store-screen-domino-store-v1.js?v=5&mvp19_9=domino-deterministic-rerender-v5'"), 'Accepted Store owner must use the deterministic Store primitive.');
expect(wrapper.includes('installDominoStoreEffectsV9();'), 'Accepted Store owner must install v9 effect scenes.');
expect(!wrapper.includes('installDominoStoreRerenderStabilityV1'), 'Accepted Store owner must not retain the observer repair.');

expect(profile.includes("dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=5&mvp19_9=domino-deterministic-rerender-v5'"), 'Profile must render the exact same Store primitive.');
expect(profile.includes('store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6'), 'Profile must share Store pip styling.');
expect(profile.includes('store-effects-scene-v9.css?v=1&mvp19_9=domino-store-effects-scene-v9'), 'Profile must share Store effect styling.');
expect(!hardRatio.includes('getBoundingClientRect'), 'Profile must not have an imperative geometry owner.');
expect(!hardRatio.includes('setTimeout'), 'Profile geometry must not depend on retry timers.');

expect(manifest.includes('domino_preview=deterministic-v9'), 'Active Store owner URL must be cache-busted for deterministic v9.');
expect(manifest.includes('domino_effects=scene-v9'), 'Active Store owner URL must publish v9 effects.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1' => './assets/js/screens/store-screen-domino-store-v1.js?v=5&mvp19_9=domino-deterministic-rerender-v5'"), 'Active import map must resolve the Store primitive to deterministic v5.');
expect(manifest.includes('domino_card_runtime=css-8x5-v2'), 'Active Profile URL must publish CSS as the single geometry owner.');
expect(manifest.includes('ux=ready-only-history-sheet'), 'Unrelated accepted Home cache marker must remain untouched.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1164, 'Telegram entry must publish the deterministic Domino v9 graph.');

console.log('MVP-19.9 Domino deterministic Store/Profile readability v9 contract passed.');
