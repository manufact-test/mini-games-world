import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const baseStore = readFileSync(resolve(root, 'app/assets/js/screens/store-screen.js'), 'utf8');
const store = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-store-v1.js'), 'utf8');
const css = readFileSync(resolve(root, 'app/assets/css/games/domino/store-cosmetics-v1.css'), 'utf8');
const correctiveCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-card-fill-live-pips-v5.css'), 'utf8');
const effectCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-effects-scene-v9.css'), 'utf8');
const liveParityCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-effects-live-parity-v41.css'), 'utf8');
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

expect(baseStore.includes("dominoPreviewMarkup, dominoHeaderMarksMarkup"), 'Base Store must still render Domino natively.');
expect(baseStore.includes("if (gameType === 'domino')"), 'Base Store must keep the Domino presentation branch.');
expect(baseStore.includes("content = dominoPreviewMarkup(safeLayer, safeVariant);"), 'Every base Store Domino rerender must use the shared primitive.');
expect(baseStore.includes("'precision-drop':'Яркий акцент в момент точного хода'"), 'Precision copy must stay short and player-facing.');
expect(baseStore.includes("'stock-pulse':'Эффектный выход костяшки из запаса'"), 'Stock copy must stay short and player-facing.');
expect(baseStore.includes("'chain-finale':'Финал с каскадом падающих костяшек'"), 'Finale copy must stay short and player-facing.');

expect(store.includes('native:v10:live-v41'), 'Domino compatibility signature must publish the live-v41 preview owner.');
expect(store.includes('ensureEffectStyles();') && store.includes('ensureLiveParityStyles();'), 'The Domino markup owner must load base geometry and the live-v41 parity stylesheet itself.');
expect(store.includes('domino-premium-effects-v15-proportions'), 'The markup owner must publish the v15 stylesheet identity.');
expect(store.includes('data-mgw-domino-fx-v14'), 'Existing deterministic inner scene identity must remain available.');
expect(store.includes('sceneReady') && store.includes('data-mgw-domino-preview-live-v41'), 'Compatibility upgrade must reject stale pre-live-v41 inner markup.');
expect(store.includes('mgw-domino-v13-impact') && store.includes('mgw-domino-v13-draw') && store.includes('mgw-domino-v13-cascade'), 'Three dedicated Domino scenes must remain distinct.');
expect(store.includes('data-mgw-domino-preview-particles="8"'), 'Precision preview must expose eight particles like accepted live v41.');
expect(store.includes('data-mgw-domino-preview-particles="12"'), 'Stock preview must expose twelve particles like accepted live v38.');
expect(store.includes('data-mgw-domino-preview-particles="6"'), 'Finale preview must expose six premium sparks like accepted live v31.');
expect(!store.includes('queuePostRenderUpgrade') && !store.includes('MutationObserver') && !store.includes('requestAnimationFrame') && !store.includes('setTimeout'), 'Native Domino Store must remain timer/observer free.');

expect(css.includes('aspect-ratio:8 / 5!important'), 'Accepted static Domino preview geometry must stay intact.');
expect(css.includes('aspect-ratio:47 / 24'), 'Accepted static Domino tile proportions must stay intact.');
expect(correctiveCss.includes('height:100%!important'), 'Mobile static Domino Store cards must retain their accepted fill rule.');
expect(correctiveCss.includes('.store-v2-confirm-game .store-v2-game-preview[data-game-type="domino"]'), 'Purchase confirmation must retain its separate wide frame.');
expect(correctiveCss.includes('width:3px!important') && correctiveCss.includes('border-radius:50%!important'), 'Static pips must remain accepted small circles.');

expect(effectCss.includes('[data-cosmetic-layer="effect"]'), 'Effect CSS must override the tall static-card fill only for effects.');
expect(effectCss.includes('aspect-ratio:8 / 5!important'), 'Store effect cards and purchase sheets must share one 8:5 surface.');
expect(effectCss.includes('.mgw-domino-fx-stage') && effectCss.includes('inset:0!important') && effectCss.includes('height:100%!important'), 'Effect stage must keep explicit WebView-safe geometry.');
expect(effectCss.includes('width:29.5%!important') && effectCss.includes('height:23.6%!important'), 'Precision horizontal dominoes must keep a true 2:1 body on the 8:5 stage.');
expect(effectCss.includes('width:31%!important') && effectCss.includes('height:24.8%!important'), 'Stock and flying dominoes must keep a true 2:1 body.');
expect(effectCss.includes('width:13.5%!important') && effectCss.includes('height:43.2%!important'), 'Finale standing dominoes must keep a true 1:2 body.');
expect(effectCss.includes('mgw-domino-v15-precision-flight') && effectCss.includes('mgw-domino-v15-precision-wave'), 'Precision must use the rebuilt large-tile strike motion.');
expect(effectCss.includes('mgw-domino-v15-stock-flight') && effectCss.includes('mgw-domino-v15-stock-back') && effectCss.includes('mgw-domino-v15-stock-face'), 'Stock must visibly lift, flip and land a full-size tile.');
expect(effectCss.includes('mgw-domino-v15-cascade-tile') && effectCss.includes('mgw-domino-v15-finish-dust'), 'Finale must retain the accepted sequential cascade with corrected geometry.');
expect(effectCss.includes('.store-v2-game-preview[data-game-type="domino"] .mgw-domino-v13-draw .mgw-domino-v13-draw-piece > i.face'), 'Stock face must explicitly fill the moving tile instead of inheriting nested percentage sizing.');
expect(effectCss.includes('@media(prefers-reduced-motion:reduce)'), 'Effects must retain the accepted base reduced-motion fallback.');
expect(liveParityCss.includes('41-48px shard travel') && liveParityCss.includes('twelve off-axis particles') && liveParityCss.includes('premium-v31'), 'Live-parity stylesheet must document the accepted live sources.');
expect(liveParityCss.includes('height:.62px!important'), 'Stock preview must keep the accepted sub-pixel beam.');
expect(liveParityCss.includes('@keyframes mgw-domino-v41-precision-shard'), 'Precision preview must own a compact eight-shard animation.');
expect(liveParityCss.includes('@keyframes mgw-domino-v41-stock-spark'), 'Stock preview must own off-axis spark animation.');
expect(liveParityCss.includes('@keyframes mgw-domino-v41-finale-sweep') && liveParityCss.includes('@keyframes mgw-domino-v41-finale-prism'), 'Finale preview must mirror premium sweep/prism language instead of falling dominoes.');
expect(!effectCss.includes('mgw-domino-v12-'), 'Rejected v12 choreography must stay removed.');
expect(!effectCss.includes('repeating-conic-gradient') && !effectCss.includes('mix-blend-mode:screen') && !effectCss.includes('transform-style:preserve-3d'), 'Effects must avoid rejected generic light-show/fragile 3D language.');

expect(selectorOwner.includes('selector.scrollLeft = left;') && !selectorOwner.includes("behavior:'smooth'"), 'Accepted no-jump Store selector behavior must remain intact.');
expect(wrapper.includes("store-screen-reversi-store-v1.js?v=1&mvp19_7=store-only&review=manual-corrective-v2"), 'Accepted Reversi Store identity must remain frozen.');
expect(correctiveLoader.includes('store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6'), 'Accepted static Domino corrective must remain active.');
expect(effectLoader.includes('store-effects-scene-v9.css?v=7&mvp19_9=domino-premium-effects-v15-proportions') && effectLoader.includes('store-effects-live-parity-v41.css?v=1&mvp19_9=domino-live-preview-v41'), 'Effect loader must load old base geometry first and live-v41 parity last.');
expect(wrapper.includes("from './store-screen-domino-store-v1.js?v=10&mvp19_9=domino-live-preview-v41'"), 'Store wrapper must load the fresh live-v41 Domino preview source.');
expect(wrapper.includes("store-screen-domino-effects-v9.js?v=7&mvp19_9=domino-premium-effects-v15-proportions"), 'Store wrapper must load v15 effects.');

expect(profile.includes("dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=10&mvp19_9=domino-live-preview-v41'"), 'Profile must reuse the same fresh Store live-v41 primitive.');
expect(profile.includes('store-effects-scene-v9.css?v=7&mvp19_9=domino-premium-effects-v15-proportions') && profile.includes('store-effects-live-parity-v41.css?v=1&mvp19_9=domino-live-preview-v41'), 'Profile must share both base geometry and the exact live-v41 parity stylesheet.');
expect(profileCss.includes('mgw-domino-profile-tab-mark::before'), 'Profile Domino tab icon must remain accepted.');
expect(!hardRatio.includes('getBoundingClientRect') && !hardRatio.includes('setTimeout'), 'Profile must not regain an imperative geometry owner.');

expect(manifest.includes('domino_effects=live-v41') && manifest.includes('domino_preview=live-v41') && manifest.includes('domino_preview=shared-live-v41'), 'Active Store/Profile graph must publish fresh live-v41 preview identities.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1' => './assets/js/screens/store-screen-domino-store-v1.js?v=10&mvp19_9=domino-live-preview-v41'"), 'Import map must publish native Domino v9.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=8&mvp19_9=domino-native-render-v8' => './assets/js/screens/store-screen-domino-store-v1.js?v=10&mvp19_9=domino-live-preview-v41'"), 'Cached base Store v8 imports must be redirected to v9.');
expect(manifest.includes("'./assets/js/profile/mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1' => './assets/js/profile/mgw-profile-domino-parity.js?v=10&mvp19_9=domino-live-preview-v41'"), 'Profile import map must cache-bust v15 parity.');
expect(manifest.includes('selector_center=instant-v1') && manifest.includes('ux=ready-only-history-sheet'), 'Unrelated accepted Store/Home markers must stay intact.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1170, 'Telegram entry must publish the Domino v15 graph.');

console.log('MVP-19.9 Domino Store/Purchase/Profile live-v41 preview parity contract passed.');
