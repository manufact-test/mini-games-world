import fs from 'node:fs';
import { spawnSync } from 'node:child_process';

const css = fs.readFileSync('app/assets/css/games/checkers/store-effects-live-board-v1.css', 'utf8');
const finalCenteringCss = fs.readFileSync('app/assets/css/games/checkers/store-effects-final-centering-v1.css', 'utf8');
const wrapper = fs.readFileSync('app/assets/js/screens/store-screen-checkers-wrapper.js', 'utf8');
const sourceWrapper = fs.readFileSync('app/assets/js/screens/store-screen-checkers-board-source-wrapper.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const gameCss = fs.readFileSync('app/assets/css/games/checkers/game.css', 'utf8');
const liveWrapperPath = 'app/assets/js/checkers-cosmetics/renderer-live-effects-v1.js';
const liveWrapper = fs.readFileSync(liveWrapperPath, 'utf8');
const boardThemeWrapperPath = 'app/assets/js/checkers-cosmetics/renderer-board-themes.js';
const boardThemeWrapper = fs.readFileSync(boardThemeWrapperPath, 'utf8');
const liveEffectCss = fs.readFileSync('app/assets/css/games/checkers/live-effects-store-parity-v1.css', 'utf8');
const livePieceCss = fs.readFileSync('app/assets/css/games/checkers/live-pieces-store-parity-v1.css', 'utf8');
const runtimeCorrectiveCss = fs.readFileSync('app/assets/css/games/checkers/runtime-handoff-mobile-v1.css', 'utf8');
const failedFinalHandoffPath = 'app/assets/js/checkers-cosmetics/renderer-live-effects-final-handoff.js';

function ok(value, label){
  if (!value) throw new Error(label);
  console.log(`PASS ${label}`);
}

ok(manifest.includes('store-screen-checkers-board-source-wrapper.js?v=35') && manifest.includes('store-screen-checkers-wrapper.js?v=5') && manifest.includes('bundle_selector=preserve-v1') && manifest.includes('promotion_preview=king-readable-v2') && manifest.includes('final_centering=king-readable-v2'), 'active Store route preserves accepted Checkers effect preview, selector markup and readable king corrective');
ok(wrapper.includes('store-effects-live-board-v1.css?v=2&mvp19_6=effects-live-board-only'), 'accepted Checkers wrapper remains intact beneath the outer cache-bust owner');
ok(sourceWrapper.includes('store-effects-live-board-v1.css?v=3&mvp19_6=promotion-destination-parity-v1'), 'outer Checkers Store owner keeps the accepted effect board stylesheet');
ok(sourceWrapper.includes('store-effects-final-centering-v1.css?v=2&mvp19_6=king-readable-v2'), 'outer Checkers Store owner keeps the accepted final centering/readability stylesheet');
ok(wrapper.includes('runBoundedEffectPreview'), 'existing bounded finite replay owner preserved');
ok(wrapper.includes("preview.classList.add('is-previewing')"), 'existing effect replay trigger preserved');
ok(wrapper.includes("from './store-screen-intent-wrapper.js?v=19&mvp19_6=accepted-base-preserved';"), 'accepted Store owner chain remains intact');

ok(css.includes('linear-gradient(145deg,#d9c8a8,#bea884)'), 'effect preview uses live light-square material');
ok(css.includes('linear-gradient(145deg,#5d4b58,#3c3343)'), 'effect preview uses live dark-square material');
ok(gameCss.includes('linear-gradient(145deg,#d9c8a8,#bea884)'), 'live Checkers light-square source matches');
ok(gameCss.includes('linear-gradient(145deg,#5d4b58,#3c3343)'), 'live Checkers dark-square source matches');
ok(css.includes('grid-template-rows:repeat(8,minmax(0,1fr))'), 'Store effect preview owns a true equal-row 8x8 grid');
ok(css.includes('width:9%!important'), 'effect checker uses live 72%-of-cell geometry');
ok(css.includes('linear-gradient(145deg,#f7f5ee,#c8c5bd)'), 'effect white checker uses live material');
ok(css.includes('linear-gradient(145deg,#313a51,#111827)'), 'capture target uses live black checker material');
ok(finalCenteringCss.includes('width:calc(14.5% - .5px)!important;') && finalCenteringCss.includes('font-size:clamp(14px,4.2vw,18px)!important;'), 'accepted Store Promotion king badge remains enlarged and readable');
ok(finalCenteringCss.includes('@keyframes mgw-checkers-final-promotion-piece') && finalCenteringCss.includes('@keyframes mgw-checkers-final-promotion-crown'), 'accepted Store Promotion centering animation remains active');

ok(css.includes('@keyframes mgw-live-board-move'), 'move animation exists');
ok(css.includes('@keyframes mgw-live-board-capture'), 'capture animation exists');
ok(css.includes('@keyframes mgw-live-board-captured'), 'captured checker removal exists');
ok(css.includes('@keyframes mgw-live-board-promotion'), 'promotion move exists');
ok(css.includes('@keyframes mgw-live-board-crown'), 'promotion crown exists');
ok(css.includes('@media (prefers-reduced-motion:reduce)'), 'reduced motion fallback exists');
ok(!css.includes('animation:infinite'), 'effect corrective has no infinite animation declaration');

const syntax = spawnSync(process.execPath, ['--check', liveWrapperPath], { encoding:'utf8' });
ok(syntax.status === 0, `live Checkers cosmetics owner has valid JavaScript syntax${syntax.stderr ? `: ${syntax.stderr.trim()}` : ''}`);
const boardThemeSyntax = spawnSync(process.execPath, ['--check', boardThemeWrapperPath], { encoding:'utf8' });
ok(boardThemeSyntax.status === 0, `Checkers board-theme wrapper has valid JavaScript syntax${boardThemeSyntax.stderr ? `: ${boardThemeSyntax.stderr.trim()}` : ''}`);
ok(liveWrapper.includes('game_checkers_elements'), 'live renderer reads the factual Checkers piece-set slot');
ok(liveWrapper.includes('dataset.checkersPieceStyle'), 'live renderer projects piece-set identity onto rendered checkers');
ok(liveWrapper.includes('dataset.checkersWhitePieceStyle') && liveWrapper.includes('dataset.checkersBlackPieceStyle'), 'surface keeps piece identity across frozen base selection rerenders');
for (const variant of ['wood','marble','metal','neon']) {
  ok(livePieceCss.includes(`data-checkers-piece-style="${variant}"`), `live piece CSS preserves ${variant} Store identity`);
}
ok(livePieceCss.includes('data-checkers-white-piece-style="neon"') && livePieceCss.includes('data-checkers-black-piece-style="neon"'), 'neon material survives internal selection rerenders through surface selectors');
ok(livePieceCss.includes('#69efff') && livePieceCss.includes('#e5cbff'), 'neon live material is visibly cyan/violet instead of black-on-dark');
ok(livePieceCss.includes('0 0 16px rgba(68,221,255,.58)') && livePieceCss.includes('0 0 16px rgba(190,125,255,.58)'), 'animated Neon material uses settled checker glow instead of a larger handoff halo');
ok(!livePieceCss.includes('0 0 20px rgba(68,221,255,.64)') && !livePieceCss.includes('0 0 20px rgba(190,125,255,.64)'), 'legacy oversized Neon overlay glow is removed');
ok(livePieceCss.includes('.checkers-piece.king > b::before') && livePieceCss.includes('content:"♛"'), 'live king keeps accepted primary crown branding');
ok(livePieceCss.includes('.checkers-piece.king > b::after') && livePieceCss.includes('content:"MG"'), 'live king restores accepted secondary MG monogram');

ok(boardThemeWrapper.includes('captureRealMoveOrigin'), 'ordinary paid Move captures the real source checker before the frozen renderer replaces the board');
ok(boardThemeWrapper.includes('syncRealMoveDestination'), 'ordinary paid Move binds animation to the real destination checker after render');
ok(boardThemeWrapper.includes('sourcePiece.getBoundingClientRect()') && boardThemeWrapper.includes('destinationPiece.getBoundingClientRect()'), 'real-piece FLIP measures actual source and final destination DOM boxes before animation');
ok(boardThemeWrapper.includes('finalDestinationRect = rectSnapshot(destinationRect)'), 'final untransformed destination box is frozen before the checker receives the FLIP transform');
ok(boardThemeWrapper.includes('renderRevision') && boardThemeWrapper.includes('state.renderRevision !== renderRevision'), 'stale microtask/rAF decoration callbacks cannot retarget a newer board render');
ok(boardThemeWrapper.includes('alignMoveDecorations(layer, liveBoard, state, finalDestinationRect)'), 'trail and ring target the captured final box rather than the transformed in-flight checker box');
ok(boardThemeWrapper.includes("destinationPiece.classList.add('mgw-checkers-live-real-move-piece')"), 'real destination checker becomes the moving checker itself');
ok(boardThemeWrapper.includes('duplicatePiece.remove()'), 'detached live layer removes its duplicate moving checker before paint');
ok(boardThemeWrapper.includes("mgwMovePieceOwner = 'real-board-piece-flip-v2'"), 'trail layer records final-rect real-board-piece ownership for the moving checker');
ok(boardThemeWrapper.includes('stableLegend'), 'accepted stable legend DOM owner remains intact');
ok(boardThemeWrapper.includes('runtime-handoff-mobile-v1.css?v=7') && boardThemeWrapper.includes('mvp19_6=real-piece-flip-v1'), 'board-theme owner loads the real-piece FLIP corrective stylesheet');

ok(runtimeCorrectiveCss.includes('.checkers-surface .checkers-board') && runtimeCorrectiveCss.includes('grid-template-rows:repeat(8,minmax(0,1fr))!important;'), 'live Checkers board keeps eight explicit equal rows');
ok(runtimeCorrectiveCss.includes('.checkers-cell.selected .checkers-piece:not(.mgw-checkers-live-real-move-piece)'), 'selection neutralization cannot override the checker currently owned by FLIP');
ok(runtimeCorrectiveCss.includes('.checkers-piece.mgw-checkers-live-real-move-piece'), 'real destination checker has a dedicated motion owner');
ok(runtimeCorrectiveCss.includes('animation:mgw-checkers-live-real-piece-flip') && runtimeCorrectiveCss.includes('@keyframes mgw-checkers-live-real-piece-flip'), 'real checker owns the move animation from source transform to final layout');
ok(runtimeCorrectiveCss.includes('var(--mgw-real-move-dx,0px)') && runtimeCorrectiveCss.includes('var(--mgw-real-move-dy,0px)'), 'real checker FLIP uses captured source-to-destination delta');
ok(runtimeCorrectiveCss.includes('translate3d(0,0,0) scale(1)'), 'real checker animation finishes at its own unshifted final box');
ok(!runtimeCorrectiveCss.includes('mgw-checkers-live-destination-anchor-move') && !runtimeCorrectiveCss.includes('mgw-checkers-live-direct-box-move'), 'failed detached-checker landing overrides are removed');
ok(runtimeCorrectiveCss.includes('.checkers-surface.mgw-checkers-live-fx-running .checkers-legend'), 'accepted stable legend paint remains intact');
ok(runtimeCorrectiveCss.includes('.checkers-surface .checkers-cell.last-from') && runtimeCorrectiveCss.includes('box-shadow:none!important;'), 'last-from marker cannot visually shrink an emptied board square');
ok(runtimeCorrectiveCss.includes('data-checkers-theme="neon"') && runtimeCorrectiveCss.includes('inset 0 0 0 1px'), 'neon last-from keeps only its normal material seam');
ok(runtimeCorrectiveCss.includes('padding-left:max(12px,env(safe-area-inset-left))!important;') && runtimeCorrectiveCss.includes('padding-right:max(12px,env(safe-area-inset-right))!important;'), 'Checkers mobile content keeps symmetric minimum 12px side insets');

ok(liveWrapper.includes("if (value === null || value === undefined || value === '') return null;"), 'nullable Checkers event cells can never coerce null into board cell zero');
ok(liveWrapper.includes('authoritativeBoards'), 'optimistic effect classification retains the previous authoritative board');
ok(liveWrapper.includes('isFreshPromotion(beforePiece, afterPiece)'), 'pending king moves cannot be misclassified as fresh promotion');
ok(liveWrapper.includes('pendingEffectClaims'), 'optimistic one-shot is claimed and cannot replay on server confirmation');
ok(liveWrapper.includes('consumeMatchingPendingClaim'), 'authoritative confirmation consumes matching optimistic animation');
ok(liveWrapper.includes('function liveEffectKind') && liveWrapper.includes("effectId === 'game-checkers-effect-move'"), 'Move cosmetic owns movement regardless of capture event classification');
ok(liveWrapper.includes("plan.captured ? 'mgw-checkers-live-fx-event-capture'"), 'live layer retains factual capture flag while Move cosmetic stays Move');
ok(liveWrapper.includes('stripBaseTransientAnimations'), 'paid-effect flow consumes frozen base transient animation classes');
ok(liveWrapper.includes("node.classList.remove('move-impact')") && liveWrapper.includes("node.classList.remove('promotion-flash')"), 'post-landing base move/promotion twitch is explicitly removed');
ok(liveWrapper.includes('document.body.appendChild(state.layer)'), 'effect decoration layer survives board innerHTML rerenders');
ok(liveWrapper.includes('state.layer?.isConnected'), 'poll rerenders reuse the same live effect decoration node instead of restarting it');
ok(liveWrapper.includes('function cellPoint') && liveWrapper.includes('cellRect.left - boardRect.left + cellRect.width / 2') && liveWrapper.includes('cellRect.top - boardRect.top + cellRect.height / 2'), 'detached trail/ring decoration still uses actual board cell geometry');

ok(liveEffectCss.includes('position:fixed'), 'single-flight live decoration layer remains detached from the rerendered board subtree');
ok(liveEffectCss.includes('@keyframes mgw-checkers-live-field-move-trail'), 'Move retains its immediate dedicated travel streak');
ok(!liveEffectCss.includes('0%,18%{transform:translate(-50%,-50%)}'), 'Move no longer holds still for the old initial delay');
ok(liveEffectCss.includes('.mgw-checkers-live-fx-move .mgw-checkers-live-fx-impact') && liveEffectCss.includes('display:none;'), 'accepted Move effect suppresses the circular burst while preserving the travel streak');
ok(liveEffectCss.includes('.mgw-checkers-live-fx-crown::after') && liveEffectCss.includes('content:"MG"'), 'promotion overlay uses the same crown plus MG identity');
ok(liveEffectCss.includes('pointer-events:none'), 'live effect layer leaves board hit targets untouched');
ok(liveEffectCss.includes('@media (prefers-reduced-motion:reduce)'), 'live effects preserve reduced-motion handling');

ok(manifest.includes('renderer-board-themes.js?v=12&mvp19_6=equal-grid-rows-v1') && manifest.includes('landing=real-piece-flip-final-rect-v2') && manifest.includes('legend=stable-paint-v1') && manifest.includes('mobile=insets=v1') && manifest.includes('renderer-real-flight-cascade-v1.js?v=2&mvp19_6=all-paid-real-flight-v1') && manifest.includes('parent=single-flight-dom-v2'), 'active Checkers import map routes through accepted final-rect real-piece FLIP plus paid-effect cascade owner');
ok(manifest.includes('all_paid_flight=v1') && manifest.includes('real_flight=cascade-v2'), 'accepted paid Checkers effects remain on the canonical cascade owner');
ok(manifest.includes('mvp19_6=checkers-real-piece-flip-v12'), 'bootstrap cache-bust activates the accepted final-rect real-piece FLIP graph');
ok(!manifest.includes('renderer-live-effects-final-handoff.js') && !fs.existsSync(failedFinalHandoffPath), 'failed detached final-handoff wrapper is fully retired');

console.log('MVP-19.6 Checkers effect preview + live final-rect real-piece FLIP contract passed.');
