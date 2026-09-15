import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const baseStore = readFileSync(resolve(root, 'app/assets/js/screens/store-screen.js'), 'utf8');
const store = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-store-v1.js'), 'utf8');
const css = readFileSync(resolve(root, 'app/assets/css/games/domino/store-cosmetics-v1.css'), 'utf8');
const correctiveCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-card-fill-live-pips-v5.css'), 'utf8');
const effectCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-effects-scene-v9.css'), 'utf8');
const wrapper = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-checkers-board-source-wrapper.js'), 'utf8');
const selectorOwner = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-reversi-store-v1.js'), 'utf8');
const correctiveLoader = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-card-fill-v5.js'), 'utf8');
const effectLoader = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-effects-v9.js'), 'utf8');
const profile = readFileSync(resolve(root, 'app/assets/js/profile/mgw-profile-domino-parity.js'), 'utf8');
const profileCss = readFileSync(resolve(root, 'app/assets/css/screens/profile-domino-store-parity-v1.css'), 'utf8');
const hardRatio = readFileSync(resolve(root, 'app/assets/js/profile/mgw-profile-domino-hard-ratio-v1.js'), 'utf8');
const manifest = readFileSync(resolve(root, 'app/runtime/client/version-manifest.php'), 'utf8');
const launch = readFileSync(resolve(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(baseStore.includes("dominoPreviewMarkup, dominoHeaderMarksMarkup"), 'Base Store must import the Domino primitive directly.');
expect(baseStore.includes("if (gameType === 'domino')"), 'Base Store must have an explicit Domino presentation branch rather than the TTT fallback.');
expect(baseStore.includes("content = dominoPreviewMarkup(safeLayer, safeVariant);"), 'Every base Store Domino rerender must render the Domino primitive natively.');
expect(baseStore.includes("const label = gameType === 'domino' ? 'Домино'"), 'Base Store selector must normalize the Domino label on every render.');
expect(baseStore.includes('dominoHeaderMarksMarkup()'), 'Base Store must render dedicated Domino header marks directly.');
expect(baseStore.includes("'tictactoe','chess','checkers','domino'"), 'Domino must be a first-class Store catalog type.');

expect(store.includes('headBackMarkup'), 'Domino header must use the dedicated split-back primitive.');
expect(store.includes('[[6,3],[3,5],[5,2]]'), 'Static table/tile previews must retain three readable horizontal tiles.');
expect(store.includes('native:v6'), 'Domino compatibility upgrader must publish the native v6 signature.');
expect(store.includes('domino-uniform-fullfield-v4'), 'Store must retain the accepted full-field static stylesheet.');
expect(!store.includes('queuePostRenderUpgrade'), 'Native Domino rendering must not depend on a post-render microtask repair.');
expect(!store.includes('installApiHooks'), 'Native Domino rendering must not wrap Store API calls.');
expect(!store.includes('MutationObserver'), 'Native Domino rendering must not use MutationObserver.');
expect(!store.includes('requestAnimationFrame'), 'Native Domino rendering must not use frame retries.');
expect(!store.includes('setTimeout'), 'Native Domino rendering must not use timer retries.');
expect(store.includes('mgw-domino-fx-precision'), 'Precision effect must use its dedicated scene primitive.');
expect(store.includes('mgw-domino-fx-stock'), 'Stock effect must use its dedicated scene primitive.');
expect(store.includes('mgw-domino-fx-finale'), 'Finale effect must use its dedicated scene primitive.');
expect(store.includes('mgw-domino-fx-trail'), 'Precision must render a dedicated travelling light wake.');
expect(store.includes('mgw-domino-fx-stock-halo') && store.includes('mgw-domino-fx-stock-particles'), 'Stock must have a local lift halo/particle language distinct from Precision.');
expect(store.includes('mgw-domino-fx-burst') && store.includes('mgw-domino-fx-halo') && store.includes('mgw-domino-fx-sparks'), 'Finale must use the new burst/halo/sparks finish scene.');
expect(!store.includes('mgw-domino-fx-rail'), 'Rejected Finale bottom rail must be removed from markup.');
expect(store.includes('Световой шлейф сопровождает ход и ярко вспыхивает в момент стыковки'), 'Precision customer copy must be simple and human-facing.');
expect(store.includes('При взятии из запаса появляется холодное свечение и короткая россыпь искр'), 'Stock customer copy must be simple and human-facing.');
expect(store.includes('Последний ход запускает золотую вспышку и праздничный поток искр вокруг цепи'), 'Finale customer copy must be simple and human-facing.');

expect(css.includes('aspect-ratio:8 / 5!important'), 'Base Domino preview primitive must retain accepted 8:5 geometry where explicitly used.');
expect(css.includes('aspect-ratio:47 / 24'), 'Static Domino tiles must keep authentic live proportions.');
expect(css.includes('theme-walnut .mgw-domino-preview-table'), 'Walnut table must retain its dedicated full-surface material.');
expect(correctiveCss.includes('height:100%!important'), 'Mobile Domino Store product preview must fill the accepted preview column height.');
expect(correctiveCss.includes('.store-v2-confirm-game .store-v2-game-preview[data-game-type="domino"]'), 'Purchase confirmation must retain its separate Domino preview rule.');
expect(correctiveCss.includes('aspect-ratio:8 / 5!important'), 'Purchase confirmation must remain a wide 8:5 Domino preview.');
expect(correctiveCss.includes('width:3px!important'), 'Static visible Domino pips must retain the accepted small diameter.');
expect(correctiveCss.includes('border-radius:50%!important'), 'Static visible Domino pips must remain circular.');

expect(effectCss.includes('.mgw-domino-fx-piece'), 'Effects must use dedicated whole-piece scene geometry.');
expect(effectCss.includes('aspect-ratio:47 / 24!important'), 'Effect pieces must preserve Domino proportions.');
expect(effectCss.includes('mgw-domino-v12-precision-flight'), 'Precision must use the faster v12 flight.');
expect(effectCss.includes('mgw-domino-v12-precision-trail'), 'Precision must use the travelling v12 light wake.');
expect(effectCss.includes('mgw-domino-v12-dock-flash'), 'Precision must finish with a short docking flash.');
expect(effectCss.includes('mgw-domino-v12-stock-lift'), 'Stock must use a distinct lift-and-release choreography.');
expect(effectCss.includes('mgw-domino-v12-stock-halo') && effectCss.includes('mgw-domino-v12-stock-particles'), 'Stock must localize its light effect around the stock rather than use a generic background show.');
expect(effectCss.includes('mgw-domino-v12-finale-burst') && effectCss.includes('mgw-domino-v12-finale-halo') && effectCss.includes('mgw-domino-v12-finale-sparks'), 'Finale must use the new centered celebratory finish.');
expect(effectCss.includes('mix-blend-mode:screen!important'), 'Precision light wake must keep luminous screen blending.');
expect(effectCss.includes('@media(prefers-reduced-motion:reduce)'), 'Effect scenes must keep a stable reduced-motion fallback.');
expect(!effectCss.includes('.mgw-domino-fx-finale .mgw-domino-fx-rail'), 'Rejected Finale bottom stripe must be absent from CSS.');
expect(!effectCss.includes('mgw-domino-v11-magnetic-glide'), 'Superseded v11 Precision choreography must be removed.');
expect(!effectCss.includes('mgw-domino-v11-spectral-draw'), 'Superseded v11 Stock choreography must be removed.');
expect(!effectCss.includes('mgw-domino-v11-lux-sheen'), 'Superseded v11 Finale choreography must be removed.');
expect(!effectCss.includes('transform-style:preserve-3d'), 'Premium v12 must not depend on staged 3D flips.');
expect(!effectCss.includes('calc(var(--tilt'), 'Finale transforms must stay compatible with Telegram WebView CSS parsing.');

expect(selectorOwner.includes('selector.scrollLeft = left;'), 'Fresh Store game selector must center its active game instantly.');
expect(!selectorOwner.includes("behavior:'smooth'"), 'Store selector must never visibly smooth-scroll through an intermediate position after rerender.');
expect(wrapper.includes("store-screen-reversi-store-v1.js?v=1&mvp19_7=store-only&review=manual-corrective-v2"), 'Store owner must preserve the accepted Reversi import identity.');
expect(manifest.includes("'./assets/js/screens/store-screen-reversi-store-v1.js?v=1&mvp19_7=store-only&review=manual-corrective-v2' => './assets/js/screens/store-screen-reversi-store-v1.js?v=2&mvp19_7=store-only&selector=instant-active-v1'"), 'Import map must cache-bust the no-jump selector corrective behind the accepted import identity.');

expect(correctiveLoader.includes('store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6'), 'Accepted static pip corrective must remain active.');
expect(effectLoader.includes('store-effects-scene-v9.css?v=4&mvp19_9=domino-premium-effects-v12'), 'Effect loader must publish premium scene v12.');
expect(wrapper.includes("from './store-screen-domino-store-v1.js?v=6&mvp19_9=domino-native-render-v6'"), 'Accepted Store wrapper must use the native Domino module.');
expect(wrapper.includes("store-screen-domino-effects-v9.js?v=4&mvp19_9=domino-premium-effects-v12"), 'Accepted Store wrapper must load premium v12 effects.');
expect(!wrapper.includes('installDominoStoreRerenderStabilityV1'), 'Accepted Store owner must not retain observer repair.');

expect(profile.includes("dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=6&mvp19_9=domino-native-render-v6'"), 'Profile must reuse the exact native Store primitive.');
expect(profile.includes('store-effects-scene-v9.css?v=4&mvp19_9=domino-premium-effects-v12'), 'Profile must share Store premium v12 effect styling.');
expect(profile.includes('profile-domino-store-parity-v1.css?v=3&mvp19_9=domino-profile-single-back-v3'), 'Profile must load the simplified Domino tab corrective.');
expect(profile.includes('mgw-domino-profile-tab-mark'), 'Profile must normalize the Domino tab mark every repair.');
expect(profileCss.includes('mgw-domino-profile-tab-mark::before'), 'Profile Domino tab must use the dedicated single-back icon.');
expect(profileCss.includes('width:23px!important'), 'Profile Domino tab back must be larger and readable.');
expect(!profileCss.includes('radial-gradient(circle at 24% 31%'), 'Profile Domino tab must not use the old four-pip icon.');
expect(!hardRatio.includes('getBoundingClientRect'), 'Profile must not have an imperative geometry owner.');
expect(!hardRatio.includes('setTimeout'), 'Profile geometry must not depend on retry timers.');

expect(manifest.includes('domino_preview=native-v10'), 'Active Store owner URL must retain the accepted native Domino preview identity.');
expect(manifest.includes('domino_effects=premium-v12'), 'Active Store owner URL must publish premium v12 effects.');
expect(manifest.includes('selector_center=instant-v1'), 'Active Store owner URL must publish the no-jump selector corrective.');
expect(manifest.includes('domino_base=native-render-v1'), 'Active Store graph must identify the native base-render fix.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1' => './assets/js/screens/store-screen-domino-store-v1.js?v=6&mvp19_9=domino-native-render-v6'"), 'Import map must keep the accepted native Domino source mapping.');
expect(manifest.includes('domino_preview=shared-store-v10'), 'Active Profile owner must retain shared Store v10 primitive identity.');
expect(manifest.includes("'./assets/js/profile/mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1' => './assets/js/profile/mgw-profile-domino-parity.js?v=6&mvp19_9=domino-premium-effects-v12'"), 'Profile import map must publish the v12 shared effect owner.');
expect(manifest.includes('domino_effects=premium-v12'), 'Active Profile owner must publish premium v12 effect parity.');
expect(manifest.includes('domino_icon=single-back-v1'), 'Active Profile owner must publish the simplified Domino icon.');
expect(manifest.includes('ux=ready-only-history-sheet'), 'Unrelated accepted Home cache marker must remain untouched.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1167, 'Telegram entry must publish the Domino v12 polish graph.');

console.log('MVP-19.9 Domino premium Store/Profile visual v12 contract passed.');
