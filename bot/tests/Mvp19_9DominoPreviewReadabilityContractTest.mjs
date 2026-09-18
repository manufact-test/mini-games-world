import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const baseStore = readFileSync(resolve(root, 'app/assets/js/screens/store-screen.js'), 'utf8');
const store = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-store-v1.js'), 'utf8');
const css = readFileSync(resolve(root, 'app/assets/css/games/domino/store-cosmetics-v1.css'), 'utf8');
const correctiveCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-card-fill-live-pips-v5.css'), 'utf8');
const effectCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-effects-scene-v9.css'), 'utf8');
const previewComponentCss = readFileSync(resolve(root, 'app/assets/css/games/domino/store-effects-preview-component-v44.css'), 'utf8');
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

expect(store.includes('native:v14:svg-pips-v48'), 'Domino compatibility signature must publish the isolated v44 preview owner.');
expect(store.includes('ensureEffectStyles();') && store.includes('ensureLiveParityStyles();'), 'The Domino markup owner must load base geometry and the isolated v44 component stylesheet itself.');
expect(store.includes('domino-premium-effects-v15-proportions'), 'The markup owner must publish the v15 stylesheet identity.');
expect(store.includes('data-mgw-domino-preview-component="v44"'), 'Every effect scene must publish the deterministic v44 component identity.');
expect(store.includes('sceneReady') && store.includes('data-mgw-domino-preview-component'), 'Compatibility upgrade must reject stale pre-v44 inner markup.');
expect(!store.includes('mgw-domino-v13-impact') && !store.includes('mgw-domino-v13-draw') && !store.includes('mgw-domino-v13-cascade'), 'Effect preview markup must not reuse legacy v13 scene classes.');
expect(store.includes('mgw-domino-live-v44-stage') && store.includes('mgw-domino-v44-precision-sparks') && store.includes('mgw-domino-v44-stock-sparks') && store.includes('mgw-domino-v44-finale-sweep'), 'All three effects must use the isolated v44 preview component.');
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
expect(previewComponentCss.includes('does not reuse the old v13/v15/v18 effect') && previewComponentCss.includes('mgw-domino-live-v44-stage'), 'v44 must be isolated from legacy preview scenes.');
expect(previewComponentCss.includes('height:.62px!important'), 'Stock preview must keep the accepted sub-pixel beam.');
expect(previewComponentCss.includes('@keyframes mgw-domino-v44-precision-shard'), 'Precision preview must own a visible eight-shard animation.');
expect(previewComponentCss.includes('@keyframes mgw-domino-v44-stock-spark'), 'Stock preview must own twelve off-axis particles.');
expect(previewComponentCss.includes('@keyframes mgw-domino-v44-finale-sweep') && previewComponentCss.includes('@keyframes mgw-domino-v44-finale-prism'), 'Finale preview must own sweep/prism motion.');
expect(previewComponentCss.includes('Animated properties intentionally do NOT use !important'), 'Preview CSS must document keyframe ownership of animated properties.');
expect(previewComponentCss.includes('Preview-only pip geometry scales with tile size') && previewComponentCss.includes('width:clamp(1.25px,32%,3px)!important') && previewComponentCss.includes('content:none!important'), 'Effect preview pips must scale with the tile instead of inheriting the fixed 3px pseudo-pip.');
expect(previewComponentCss.includes('Stock beam geometry is pinned to the exact source/target centers') && previewComponentCss.includes('width:60.2%!important') && previewComponentCss.includes('rotate(20deg)'), 'Stock beam/orb trajectory must share the exact source-to-target geometry.');
expect(store.includes('function effectTileMarkup') && store.includes('data-mgw-domino-face="svg-v1"') && store.includes('mgw-domino-v48-face-svg') && store.includes('mgw-domino-v48-pip'), 'All effect tiles must use one canonical SVG pip renderer.');
expect(previewComponentCss.includes('v48 canonical effect-tile face') && previewComponentCss.includes('.mgw-domino-v48-pip') && previewComponentCss.includes('fill:#171a20!important'), 'Canonical SVG pips must own one round dark visual across all effects.');
expect(!previewComponentCss.includes('opacity:0!important'), 'Animated preview opacity must not be locked by !important.');
expect(!previewComponentCss.includes('left:var(--sx)!important') && !previewComponentCss.includes('top:var(--sy)!important'), 'Animated particle positions must not be locked by !important.');
expect(!previewComponentCss.includes('transform:rotate(27deg) scaleX(0)!important') && !previewComponentCss.includes('transform:translateX(-44%) skewX(-4deg)!important'), 'Animated beam/sweep transforms must remain keyframe-owned.');
expect(!profileCss.includes('animation:none!important'), 'Profile CSS must not freeze Domino effect previews.');
expect(baseStore.includes("store-screen-domino-store-v1.js?v=14&mvp19_9=domino-svg-pips-v48"), 'Base Store must directly import the fresh SVG-pip v48 preview source.');
expect(!effectCss.includes('mgw-domino-v12-'), 'Rejected v12 choreography must stay removed.');
expect(!effectCss.includes('repeating-conic-gradient') && !effectCss.includes('mix-blend-mode:screen') && !effectCss.includes('transform-style:preserve-3d'), 'Effects must avoid rejected generic light-show/fragile 3D language.');

expect(selectorOwner.includes('selector.scrollLeft = left;') && !selectorOwner.includes("behavior:'smooth'"), 'Accepted no-jump Store selector behavior must remain intact.');
expect(wrapper.includes("store-screen-reversi-store-v1.js?v=1&mvp19_7=store-only&review=manual-corrective-v2"), 'Accepted Reversi Store identity must remain frozen.');
expect(correctiveLoader.includes('store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6'), 'Accepted static Domino corrective must remain active.');
expect(effectLoader.includes('store-effects-scene-v9.css?v=7&mvp19_9=domino-premium-effects-v15-proportions') && effectLoader.includes('store-effects-preview-component-v44.css?v=4&mvp19_9=domino-svg-pips-v48'), 'Effect loader must load old base geometry first and the isolated v44 component last.');
expect(wrapper.includes("from './store-screen-domino-store-v1.js?v=14&mvp19_9=domino-svg-pips-v48'"), 'Store wrapper must load the fresh v44 Domino preview source.');
expect(wrapper.includes("store-screen-domino-effects-v9.js?v=12&mvp19_9=domino-svg-pips-v48"), 'Store wrapper must load the fresh v44 effect loader.');

expect(profile.includes("dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=14&mvp19_9=domino-svg-pips-v48'"), 'Profile must reuse the same v44 Store primitive.');
expect(profile.includes('store-effects-scene-v9.css?v=7&mvp19_9=domino-premium-effects-v15-proportions') && profile.includes('store-effects-preview-component-v44.css?v=4&mvp19_9=domino-svg-pips-v48'), 'Profile must share both base geometry and the exact v44 component stylesheet.');
expect(profileCss.includes('mgw-domino-profile-tab-mark::before'), 'Profile Domino tab icon must remain accepted.');
expect(!hardRatio.includes('getBoundingClientRect') && !hardRatio.includes('setTimeout'), 'Profile must not regain an imperative geometry owner.');

expect(manifest.includes('domino_effects=svg-pips-v48') && manifest.includes('domino_preview=svg-pips-v48') && manifest.includes('domino_preview=shared-svg-pips-v48'), 'Active Store/Profile graph must publish fresh v44 preview identities.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1' => './assets/js/screens/store-screen-domino-store-v1.js?v=14&mvp19_9=domino-svg-pips-v48'"), 'Import map must publish native Domino v9.');
expect(manifest.includes("'./assets/js/screens/store-screen-domino-store-v1.js?v=8&mvp19_9=domino-native-render-v8' => './assets/js/screens/store-screen-domino-store-v1.js?v=14&mvp19_9=domino-svg-pips-v48'"), 'Cached base Store v8 imports must be redirected to v9.');
expect(manifest.includes("'./assets/js/profile/mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1' => './assets/js/profile/mgw-profile-domino-parity.js?v=14&mvp19_9=domino-svg-pips-v48'"), 'Profile import map must cache-bust v15 parity.');
expect(manifest.includes('selector_center=instant-v1') && manifest.includes('ux=ready-only-history-sheet'), 'Unrelated accepted Store/Home markers must stay intact.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1170, 'Telegram entry must publish the Domino v15 graph.');

console.log('MVP-19.9 Domino Store/Purchase/Profile v48 SVG-pip preview contract passed.');
