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
expect(baseStore.includes("store-screen-domino-store-v1.js?v=8&mvp19_9=domino-native-render-v8"), 'Base Store must cache-bust the native Domino module instead of reusing stale v6 markup.');
expect(baseStore.includes("if (gameType === 'domino')"), 'Base Store must keep Domino as a native presentation branch.');
expect(baseStore.includes("content = dominoPreviewMarkup(safeLayer, safeVariant);"), 'Every base Store Domino rerender must use the native Domino primitive.');
expect(baseStore.includes("'precision-drop':'Яркий акцент в момент точного хода'"), 'Base Store must own the short player-facing Precision copy.');
expect(baseStore.includes("'stock-pulse':'Эффектный выход костяшки из запаса'"), 'Base Store must own the short player-facing Stock copy.');
expect(baseStore.includes("'chain-finale':'Финал с каскадом падающих костяшек'"), 'Base Store must own the short player-facing Finale copy.');

expect(store.includes('native:v8'), 'Domino compatibility signature must publish native v8.');
expect(store.includes('ensureEffectStyles();'), 'The Domino markup owner must also load its own effect stylesheet.');
expect(store.includes('domino-premium-effects-v14-visible'), 'The markup owner must cache-bust the visible v14 stylesheet.');
expect(store.includes('data-mgw-domino-fx-v14'), 'Every effect scene must publish an inner v14 scene signature.');
expect(store.includes('sceneReady'), 'Compatibility upgrade must reject stale inner scene markup even when the outer variant class matches.');
expect(store.includes('mgw-domino-v13-impact'), 'Precision must retain the physical impact scene.');
expect(store.includes('mgw-domino-v13-draw'), 'Stock must retain the draw/reveal scene.');
expect(store.includes('mgw-domino-v13-cascade'), 'Finale must retain the domino cascade scene.');
expect(!store.includes('queuePostRenderUpgrade') && !store.includes('MutationObserver') && !store.includes('requestAnimationFrame') && !store.includes('setTimeout'), 'Native Domino Store must stay deterministic and timer/observer free.');

expect(css.includes('aspect-ratio:8 / 5!important'), 'Accepted static Domino preview geometry must stay intact.');
expect(css.includes('aspect-ratio:47 / 24'), 'Accepted static Domino tile proportions must stay intact.');
expect(correctiveCss.includes('height:100%!important'), 'Mobile static Domino Store cards must retain their accepted fill rule.');
expect(correctiveCss.includes('.store-v2-confirm-game .store-v2-game-preview[data-game-type="domino"]'), 'Purchase confirmation must retain its separate wide frame.');
expect(correctiveCss.includes('width:3px!important') && correctiveCss.includes('border-radius:50%!important'), 'Static pips must remain accepted small circles.');

expect(effectCss.includes('[data-cosmetic-layer="effect"]'), 'Effect CSS must override the tall static-card fill only for effect cards.');
expect(effectCss.includes('aspect-ratio:8 / 5!important'), 'Store effect cards must use the same 8:5 surface as purchase sheets.');
expect(effectCss.includes('.mgw-domino-fx-stage') && effectCss.includes('inset:0!important') && effectCss.includes('height:100%!important'), 'Effect stage must have explicit non-zero WebView geometry.');
expect(effectCss.includes('mgw-domino-v13-impact-flight') && effectCss.includes('mgw-domino-v13-impact-ring'), 'Precision must retain one continuous flight plus contact response.');
expect(effectCss.includes('mgw-domino-v13-draw-flight') && effectCss.includes('mgw-domino-v13-draw-back') && effectCss.includes('mgw-domino-v13-draw-face'), 'Stock must retain a continuous draw and face reveal.');
expect(effectCss.includes('mgw-domino-v13-cascade-tile') && effectCss.includes('mgw-domino-v13-finish-dust'), 'Finale must retain sequential physical toppling.');
expect(effectCss.includes('@media(prefers-reduced-motion:reduce)'), 'Effects must retain a stable reduced-motion fallback.');
expect(!effectCss.includes('mgw-domino-v12-'), 'Rejected v12 choreography must stay removed.');
expect(!effectCss.includes('repeating-conic-gradient'), 'Rejected rainbow/light-wheel language must stay absent.');
expect(!effectCss.includes('mix-blend-mode:screen'), 'Effects must not regain generic screen-blended light-show layers.');
expect(!effectCss.includes('transform-style:preserve-3d'), 'Effects must not depend on staged 3D rendering.');

expect(selectorOwner.includes('selector.scrollLeft = left;') && !selectorOwner.includes("behavior:'smooth'"), 'Accepted no-jump Store selector behavior must remain intact.');
expect(wrapper.includes("store-screen-reversi-store-v1.js?v=1&mvp19_7=store-only&review=manual-corrective-v2"), 'Accepted Reversi Store identity must remain frozen.');
expect(correctiveLoader.includes('store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6'), 'Accepted static Domino corrective must remain active.');
expect(effectLoader.includes('store-effects-scene-v9.css?v=6&mvp19_9=domino-premium-effects-v14-visible'), 'Effect loader must publish visible v14 CSS.');
expect(wrapper.includes("from './store-screen-domino-store-v1.js?v=8&mvp19_9=domino-native-render-v8'"), 'Store wrapper must load native Domino v8.');
expect(wrapper.includes("store-screen-domino-effects-v9.js?v=6&mvp19_9=domino-premium-effects-v14-visible"), 'Store wrapper must load visible v14 effects.');

expect(profile.includes("dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=8&mvp19_9=domino-native-render-v8'"), 'Profile must reuse the exact Store v14 primitive.');
expect(profile.includes('store-effects-scene-v9.css?v=6&mvp19_9=domino-premium-effects-v14-visible'), 'Profile must share the exact visible v14 effect stylesheet.');
expect(profileCss.includes('mgw-domino-profile-tab-mark::before'), 'Profile Domino tab icon must remain accepted.');
expect(!hardRatio.includes('getBoundingClientRect') && !hardRatio.includes('setTimeout'), 'Profile must not regain an imperative geometry owner.');

expect(manifest.includes('domino_effects=premium-v14-visible'), 'Active Store/Profile graph must publish visible v14 effects.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1' => './assets/js/screens/store-screen-domino-store-v1.js?v=8&mvp19_9=domino-native-render-v8'"), 'Import map must cache-bust native Domino v8.');
expect(manifest.includes("store-screen.js?v=48&intent_base=1"), 'Base Store must be cache-busted so silent refresh cannot restore stale v12 copy/markup.');
expect(manifest.includes("'./assets/js/profile/mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1' => './assets/js/profile/mgw-profile-domino-parity.js?v=8&mvp19_9=domino-premium-effects-v14-visible'"), 'Profile import map must cache-bust visible v14 parity.');
expect(manifest.includes('selector_center=instant-v1') && manifest.includes('ux=ready-only-history-sheet'), 'Unrelated accepted Store/Home markers must stay intact.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1169, 'Telegram entry must publish the visible Domino v14 graph.');

console.log('MVP-19.9 Domino Store/Profile visible v14 contract passed.');
