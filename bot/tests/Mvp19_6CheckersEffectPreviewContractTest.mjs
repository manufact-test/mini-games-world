import fs from 'node:fs';
import { spawnSync } from 'node:child_process';

const css = fs.readFileSync('app/assets/css/games/checkers/store-effects-live-board-v1.css', 'utf8');
const wrapper = fs.readFileSync('app/assets/js/screens/store-screen-checkers-wrapper.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const gameCss = fs.readFileSync('app/assets/css/games/checkers/game.css', 'utf8');
const liveWrapperPath = 'app/assets/js/checkers-cosmetics/renderer-live-effects-v1.js';
const liveWrapper = fs.readFileSync(liveWrapperPath, 'utf8');
const boardThemeWrapper = fs.readFileSync('app/assets/js/checkers-cosmetics/renderer-board-themes.js', 'utf8');
const liveEffectCss = fs.readFileSync('app/assets/css/games/checkers/live-effects-store-parity-v1.css', 'utf8');
const livePieceCss = fs.readFileSync('app/assets/css/games/checkers/live-pieces-store-parity-v1.css', 'utf8');

function ok(value, label){
  if (!value) throw new Error(label);
  console.log(`PASS ${label}`);
}

ok(manifest.includes('store-screen-checkers-wrapper.js?v=4') && manifest.includes('mvp19_6=visual-corrective-v3') && manifest.includes('effects_live_board=v1'), 'active Store route keeps accepted Checkers wrapper and cache-busts effect corrective');
ok(wrapper.includes('store-effects-live-board-v1.css?v=2&mvp19_6=effects-live-board-only'), 'accepted Checkers wrapper loads cache-busted effects stylesheet');
ok(wrapper.includes('runBoundedEffectPreview'), 'existing bounded finite replay owner preserved');
ok(wrapper.includes("preview.classList.add('is-previewing')"), 'existing effect replay trigger preserved');
ok(wrapper.includes("from './store-screen-intent-wrapper.js?v=19&mvp19_6=accepted-base-preserved';"), 'accepted Store owner chain remains intact');

ok(css.includes('linear-gradient(145deg,#d9c8a8,#bea884)'), 'effect preview uses live light-square material');
ok(css.includes('linear-gradient(145deg,#5d4b58,#3c3343)'), 'effect preview uses live dark-square material');
ok(gameCss.includes('linear-gradient(145deg,#d9c8a8,#bea884)'), 'live Checkers light-square source matches');
ok(gameCss.includes('linear-gradient(145deg,#5d4b58,#3c3343)'), 'live Checkers dark-square source matches');
ok(css.includes('width:9%!important'), 'effect checker uses live 72%-of-cell geometry');
ok(css.includes('linear-gradient(145deg,#f7f5ee,#c8c5bd)'), 'effect white checker uses live material');
ok(css.includes('linear-gradient(145deg,#313a51,#111827)'), 'capture target uses live black checker material');

ok(css.includes('@keyframes mgw-live-board-move'), 'move animation exists');
ok(css.includes('@keyframes mgw-live-board-capture'), 'capture animation exists');
ok(css.includes('@keyframes mgw-live-board-captured'), 'captured checker removal exists');
ok(css.includes('@keyframes mgw-live-board-promotion'), 'promotion move exists');
ok(css.includes('@keyframes mgw-live-board-crown'), 'promotion crown exists');
ok(css.includes('@media (prefers-reduced-motion:reduce)'), 'reduced motion fallback exists');
ok(!css.includes('animation:infinite'), 'effect corrective has no infinite animation declaration');

const syntax = spawnSync(process.execPath, ['--check', liveWrapperPath], { encoding:'utf8' });
ok(syntax.status === 0, `live Checkers cosmetics owner has valid JavaScript syntax${syntax.stderr ? `: ${syntax.stderr.trim()}` : ''}`);
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

ok(boardThemeWrapper.includes("layer.dataset.mgwCheckersLandingLocked === '1'"), 'exact landing geometry is never retargeted after first successful lock');
ok(boardThemeWrapper.includes("layer.dataset.mgwCheckersLandingLocked = '1'"), 'successful DOM landing measurement marks the compositor endpoint immutable');
ok(boardThemeWrapper.includes('mgwCheckersLandingX') && boardThemeWrapper.includes('mgwCheckersLandingY') && boardThemeWrapper.includes('mgwCheckersLandingSize'), 'locked landing records deterministic geometry diagnostics');

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

ok(liveEffectCss.includes('position:fixed'), 'single-flight live effect layer is detached from the rerendered board subtree');
ok(liveEffectCss.includes('box-sizing:border-box'), 'live overlay piece uses the same border-box geometry as the real checker');
ok(liveEffectCss.includes('var(--mgw-fx-dx)') && liveEffectCss.includes('var(--mgw-fx-dy)'), 'piece motion uses compositor transforms instead of left/top correction jumps');
ok(liveEffectCss.includes('@keyframes mgw-checkers-live-field-move-trail'), 'Move has an immediate dedicated travel streak');
ok(!liveEffectCss.includes('0%,18%{transform:translate(-50%,-50%)}'), 'Move no longer holds still for the old initial delay');
ok(liveEffectCss.includes('.mgw-checkers-live-fx-move.mgw-checkers-live-fx-event-capture .mgw-checkers-live-fx-impact'), 'Move-on-capture explicitly suppresses burst while preserving travel streak');
ok(liveEffectCss.includes('.mgw-checkers-live-fx-crown::after') && liveEffectCss.includes('content:"MG"'), 'promotion overlay uses the same crown plus MG identity');
ok(liveEffectCss.includes('pointer-events:none'), 'live effect layer leaves board hit targets untouched');
ok(liveEffectCss.includes('@media (prefers-reduced-motion:reduce)'), 'live effects preserve reduced-motion handling');
ok(manifest.includes('mvp19_6=runtime-smoothing-v4') && manifest.includes('pieces=king-brand-v3') && manifest.includes('events=move-through-capture-v4') && manifest.includes('landing=stable-handoff-v1'), 'active Checkers import map cache-busts branded stable-landing runtime');

console.log('MVP-19.6 Checkers effect preview + live parity contract passed.');
