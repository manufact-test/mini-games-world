import fs from 'node:fs';
import { spawnSync } from 'node:child_process';

const css = fs.readFileSync('app/assets/css/games/checkers/store-effects-live-board-v1.css', 'utf8');
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

function ok(value, label){
  if (!value) throw new Error(label);
  console.log(`PASS ${label}`);
}

ok(manifest.includes('store-screen-checkers-board-source-wrapper.js?v=3') && manifest.includes('promotion_preview=destination-parity-v1'), 'active Store route cache-busts the Promotion destination-parity corrective');
ok(wrapper.includes('store-effects-live-board-v1.css?v=2&mvp19_6=effects-live-board-only'), 'accepted Checkers wrapper remains intact beneath the outer cache-bust owner');
ok(sourceWrapper.includes('store-effects-live-board-v1.css?v=3&mvp19_6=promotion-destination-parity-v1'), 'outer Checkers Store owner forces the fresh effect stylesheet after accepted wrapper setup');
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
ok(css.includes('.checkers-store-fx-promotion .from{left:56.25%;top:31.25%}'), 'Promotion moving checker starts on the intended source square');
ok(!css.includes('.checkers-store-fx-promotion .from{left:56.25%!important') && !css.includes('.checkers-store-fx-move .from{left:31.25%!important') && !css.includes('.checkers-store-fx-capture .from{left:18.75%!important'), 'animated source coordinates are not pinned by important declarations that outrank CSS animations');
ok(css.includes('.checkers-store-fx-promotion .checkers-fx-impact{left:68.75%!important;top:18.75%!important;width:12.5%') && css.includes('.checkers-store-fx-promotion .checkers-fx-crown{left:68.75%!important;top:18.75%!important;width:12.5%'), 'Promotion crown and ring share one exact destination center and one-cell outer geometry');
ok(css.includes('font-size:11px') && css.includes('font-size:clamp(14px,4vw,22px)'), 'Promotion crown stays readable without viewport-sized overflow in small Store cards');

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
ok(livePieceCss.includes('0 0 16px rgba(68,221,255,.58)') && livePieceCss.includes('0 0 16px rgba(190,125,255,.58)'), 'animated Neon clone uses settled checker glow instead of a larger handoff halo');
ok(!livePieceCss.includes('0 0 20px rgba(68,221,255,.64)') && !livePieceCss.includes('0 0 20px rgba(190,125,255,.64)'), 'legacy oversized Neon overlay glow is removed');
ok(livePieceCss.includes('.checkers-piece.king > b::before') && livePieceCss.includes('content:"♛"'), 'live king keeps accepted primary crown branding');
ok(livePieceCss.includes('.checkers-piece.king > b::after') && livePieceCss.includes('content:"MG"'), 'live king restores accepted secondary MG monogram');

ok(!boardThemeWrapper.includes('syncExactLiveLanding'), 'board-theme wrapper no longer retargets the paid checker from transient destination-piece geometry');
ok(!boardThemeWrapper.includes('getBoundingClientRect'), 'board-theme wrapper cannot introduce a second DOM geometry owner for landing');
ok(boardThemeWrapper.includes('eight equal') && boardThemeWrapper.includes('optimistic arrival') && boardThemeWrapper.includes('authoritative handoff'), 'landing contract now identifies equal grid rows as the stable geometry owner');
ok(boardThemeWrapper.includes('container.dataset.mgwCheckersPaidEffect'), 'viewer paid-effect state survives frozen selection rerenders on the surface');
ok(boardThemeWrapper.includes('runtime-handoff-mobile-v1.css?v=3') && boardThemeWrapper.includes('landing=stable-row-centers-v1'), 'board-theme owner loads the fresh equal-row handoff corrective stylesheet');

ok(runtimeCorrectiveCss.includes('.checkers-surface .checkers-board') && runtimeCorrectiveCss.includes('grid-template-rows:repeat(8,minmax(0,1fr))!important;'), 'live Checkers board has eight explicit equal rows so piece occupancy cannot move cell centers');
ok(runtimeCorrectiveCss.includes('data-mgw-checkers-paid-effect="1"') && runtimeCorrectiveCss.includes('.checkers-cell.selected .checkers-piece'), 'paid Checkers selection no longer changes the checker physical diameter');
ok(runtimeCorrectiveCss.includes('transform:none!important;'), 'corrective neutralizes selected/hidden transform geometry at handoff');
ok(runtimeCorrectiveCss.includes('transition:none!important;'), 'hidden authoritative checker cannot run its own transform transition during reveal');
ok(runtimeCorrectiveCss.includes('transform-box:border-box') && runtimeCorrectiveCss.includes('transform-origin:50% 50%'), 'detached live checker uses symmetric border-box transform geometry');
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
ok(liveWrapper.includes('stripBaseTransientAnimations'), 'paid-effect handoff consumes frozen base transient animation classes');
ok(liveWrapper.includes("node.classList.remove('move-impact')") && liveWrapper.includes("node.classList.remove('promotion-flash')"), 'post-landing base move/promotion twitch is explicitly removed');
ok(liveWrapper.includes('document.body.appendChild(state.layer)'), 'effect layer survives board innerHTML rerenders');
ok(liveWrapper.includes('state.layer?.isConnected'), 'poll rerenders reuse the same live effect node instead of restarting it');
ok(liveWrapper.includes('function cellPoint') && liveWrapper.includes('cellRect.left - boardRect.left + cellRect.width / 2') && liveWrapper.includes('cellRect.top - boardRect.top + cellRect.height / 2'), 'paid checker from/to positions remain owned by actual cell centers');

ok(liveEffectCss.includes('position:fixed'), 'single-flight live effect layer is detached from the rerendered board subtree');
ok(liveEffectCss.includes('box-sizing:border-box'), 'live overlay piece uses the same border-box geometry as the real checker');
ok(liveEffectCss.includes('var(--mgw-fx-dx)') && liveEffectCss.includes('var(--mgw-fx-dy)'), 'piece motion uses compositor transforms instead of left/top correction jumps');
ok(liveEffectCss.includes('@keyframes mgw-checkers-live-field-move-trail'), 'Move has an immediate dedicated travel streak');
ok(!liveEffectCss.includes('0%,18%{transform:translate(-50%,-50%)}'), 'Move no longer holds still for the old initial delay');
ok(liveEffectCss.includes('.mgw-checkers-live-fx-move.mgw-checkers-live-fx-event-capture .mgw-checkers-live-fx-impact'), 'Move-on-capture explicitly suppresses burst while preserving travel streak');
ok(liveEffectCss.includes('.mgw-checkers-live-fx-crown::after') && liveEffectCss.includes('content:"MG"'), 'promotion overlay uses the same crown plus MG identity');
ok(liveEffectCss.includes('pointer-events:none'), 'live effect layer leaves board hit targets untouched');
ok(liveEffectCss.includes('@media (prefers-reduced-motion:reduce)'), 'live effects preserve reduced-motion handling');
ok(manifest.includes('renderer-board-themes.js?v=6&mvp19_6=equal-grid-rows-v1') && manifest.includes('renderer-live-effects-v1.js?v=9&mvp19_6=runtime-smoothing-v9') && manifest.includes('landing=stable-row-centers-v1') && manifest.includes('grid_rows=equal-v1') && manifest.includes('selection=geometry-neutral-v1') && manifest.includes('last_from=flat-v1') && manifest.includes('mobile=insets-v1'), 'active Checkers import map cache-busts equal-row landing geometry and the preserved handoff correctives');
ok(manifest.includes('promotion=authoritative-only-v1'), 'Promotion remains authoritative-only after the temporary QA shortcut removal');

console.log('MVP-19.6 Checkers effect preview + live parity contract passed.');
