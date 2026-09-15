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
expect(baseStore.includes("if (gameType === 'domino')"), 'Base Store must keep Domino as a native presentation branch.');
expect(baseStore.includes("content = dominoPreviewMarkup(safeLayer, safeVariant);"), 'Every base Store Domino rerender must use the native Domino primitive.');
expect(baseStore.includes("const label = gameType === 'domino' ? 'Домино'"), 'Base Store selector must keep the Domino label native.');

expect(store.includes('native:v7'), 'Domino compatibility signature must publish native v7.');
expect(store.includes('mgw-domino-fx-stage'), 'Every effect must render through the shared fixed-ratio stage.');
expect(store.includes('mgw-domino-v13-impact'), 'Precision must use the v13 impact scene.');
expect(store.includes('mgw-domino-v13-draw'), 'Stock must use the v13 draw/reveal scene.');
expect(store.includes('mgw-domino-v13-cascade'), 'Finale must use the v13 domino cascade scene.');
expect(store.includes('mgw-domino-v13-impact-ring') && store.includes('mgw-domino-v13-impact-sparks'), 'Precision must reserve its visual accent for the contact point.');
expect(store.includes('mgw-domino-v13-stock') && store.includes('mgw-domino-v13-draw-piece'), 'Stock must visibly originate from a face-down stock.');
expect(store.includes('mgw-domino-v13-cascade-row') && store.includes('mgw-domino-v13-finish-dust'), 'Finale must be a physical domino cascade, not an abstract light effect.');
expect(store.includes('Костяшка точно защёлкивается в цепь'), 'Precision copy must describe the player-visible effect.');
expect(store.includes('Костяшка поднимается из запаса, переворачивается лицом вверх'), 'Stock copy must describe the player-visible effect.');
expect(store.includes('падают настоящим домино-каскадом'), 'Finale copy must describe the player-visible effect.');
expect(!store.includes('mgw-domino-fx-trail'), 'Rejected v12 travelling-light scene must be removed.');
expect(!store.includes('mgw-domino-fx-burst'), 'Rejected v12 abstract Finale burst must be removed.');
expect(!store.includes('mgw-domino-fx-halo'), 'Rejected v12 generic halo language must be removed.');
expect(!store.includes('queuePostRenderUpgrade') && !store.includes('MutationObserver') && !store.includes('requestAnimationFrame') && !store.includes('setTimeout'), 'Native Domino Store must stay deterministic and timer/observer free.');

expect(css.includes('aspect-ratio:8 / 5!important'), 'Accepted static Domino preview geometry must stay intact.');
expect(css.includes('aspect-ratio:47 / 24'), 'Accepted static Domino tile proportions must stay intact.');
expect(correctiveCss.includes('height:100%!important'), 'Mobile static Domino Store cards must retain their accepted fill rule.');
expect(correctiveCss.includes('.store-v2-confirm-game .store-v2-game-preview[data-game-type="domino"]'), 'Purchase confirmation must retain its separate wide frame.');
expect(correctiveCss.includes('width:3px!important') && correctiveCss.includes('border-radius:50%!important'), 'Static pips must remain accepted small circles.');

expect(effectCss.includes('.mgw-domino-fx-stage'), 'Effect CSS must own the shared stage.');
expect(effectCss.includes('aspect-ratio:8 / 5!important'), 'Effect stage must use the exact same 8:5 coordinate system in cards and purchase sheets.');
expect(effectCss.includes('mgw-domino-v13-impact-flight') && effectCss.includes('mgw-domino-v13-impact-ring'), 'Precision must have one continuous flight plus contact response.');
expect(effectCss.includes('mgw-domino-v13-draw-flight') && effectCss.includes('mgw-domino-v13-draw-back') && effectCss.includes('mgw-domino-v13-draw-face'), 'Stock must have a continuous draw and face reveal.');
expect(effectCss.includes('mgw-domino-v13-cascade-tile') && effectCss.includes('mgw-domino-v13-finish-dust'), 'Finale must use sequential physical toppling.');
expect(effectCss.includes('@media(prefers-reduced-motion:reduce)'), 'v13 must retain a stable reduced-motion fallback.');
expect(!effectCss.includes('mgw-domino-v12-'), 'Rejected v12 choreography must be gone completely.');
expect(!effectCss.includes('repeating-conic-gradient'), 'Rejected rainbow/light-wheel language must be absent.');
expect(!effectCss.includes('mix-blend-mode:screen'), 'v13 must not rely on generic screen-blended light-show layers.');
expect(!effectCss.includes('transform-style:preserve-3d'), 'v13 must not depend on staged 3D rendering.');

expect(selectorOwner.includes('selector.scrollLeft = left;') && !selectorOwner.includes("behavior:'smooth'"), 'Accepted no-jump Store selector behavior must remain intact.');
expect(wrapper.includes("store-screen-reversi-store-v1.js?v=1&mvp19_7=store-only&review=manual-corrective-v2"), 'Accepted Reversi Store identity must remain frozen.');
expect(correctiveLoader.includes('store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6'), 'Accepted static Domino corrective must remain active.');
expect(effectLoader.includes('store-effects-scene-v9.css?v=5&mvp19_9=domino-premium-effects-v13'), 'Effect loader must publish v13 CSS.');
expect(wrapper.includes("from './store-screen-domino-store-v1.js?v=7&mvp19_9=domino-native-render-v7'"), 'Store wrapper must load native Domino v7.');
expect(wrapper.includes("store-screen-domino-effects-v9.js?v=5&mvp19_9=domino-premium-effects-v13"), 'Store wrapper must load premium v13 effects.');

expect(profile.includes("dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=7&mvp19_9=domino-native-render-v7'"), 'Profile must reuse the exact Store v13 primitive.');
expect(profile.includes('store-effects-scene-v9.css?v=5&mvp19_9=domino-premium-effects-v13'), 'Profile must share the exact v13 effect stylesheet.');
expect(profileCss.includes('mgw-domino-profile-tab-mark::before'), 'Profile Domino tab icon must remain accepted.');
expect(!hardRatio.includes('getBoundingClientRect') && !hardRatio.includes('setTimeout'), 'Profile must not regain an imperative geometry owner.');

expect(manifest.includes('domino_preview=native-v11'), 'Active Store URL must publish native v11 preview identity.');
expect(manifest.includes('domino_effects=premium-v13'), 'Active Store/Profile graph must publish premium v13.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1' => './assets/js/screens/store-screen-domino-store-v1.js?v=7&mvp19_9=domino-native-render-v7'"), 'Import map must cache-bust the native Domino v7 module.');
expect(manifest.includes('domino_preview=shared-store-v11'), 'Active Profile URL must publish shared Store v11 primitive identity.');
expect(manifest.includes("'./assets/js/profile/mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1' => './assets/js/profile/mgw-profile-domino-parity.js?v=7&mvp19_9=domino-premium-effects-v13'"), 'Profile import map must cache-bust v13 parity.');
expect(manifest.includes('selector_center=instant-v1') && manifest.includes('ux=ready-only-history-sheet'), 'Unrelated accepted Store/Home markers must stay intact.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1168, 'Telegram entry must publish the Domino v13 graph.');

console.log('MVP-19.9 Domino Store/Profile visual v13 redesign contract passed.');
