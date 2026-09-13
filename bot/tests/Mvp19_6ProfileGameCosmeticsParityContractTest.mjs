import fs from 'node:fs';

const checkers = fs.readFileSync('app/assets/js/profile/mgw-profile-checkers-parity.js', 'utf8');
const layout = fs.readFileSync('app/assets/js/profile/mgw-profile-chess-layout-v2.js', 'utf8');
const chess = fs.readFileSync('app/assets/js/profile/mgw-profile-chess-parity.js', 'utf8');
const api = fs.readFileSync('app/assets/js/api/client.js', 'utf8');
const css = fs.readFileSync('app/assets/css/screens/profile-game-cosmetics-parity-v1.css', 'utf8');
const manualRepairCss = fs.readFileSync('app/assets/css/screens/profile-game-cosmetics-manual-repair-v3.css', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const store = fs.readFileSync('app/assets/js/screens/store-screen-checkers-wrapper.js', 'utf8');
const storeSource = fs.readFileSync('app/assets/js/screens/store-screen-checkers-board-source-wrapper.js', 'utf8');

function expect(condition, message){
  if (!condition) throw new Error(message);
}

// Full Checkers Profile inventory must come from authoritative Profile inventory, not a drifting item list.
expect(checkers.includes('state.profileInventory'), 'Checkers Profile must consume authoritative Profile inventory');
expect(checkers.includes('item.owned === true') && checkers.includes("item.item_type === 'game'"), 'Checkers Profile must render authoritative owned game items');
expect(checkers.includes("checkersGameType(item) === 'checkers'"), 'Checkers Profile must filter canonical Checkers inventory');
expect(checkers.includes("theme:'Доски'") && checkers.includes("elements:'Шашки'") && checkers.includes("effect:'Эффекты'"), 'Checkers Profile must expose boards, checker sets and effects');
expect(!checkers.includes('CHECKERS_PROFILE_ITEMS'), 'Checkers Profile must not restore a manually maintained item catalogue');
expect(!checkers.includes('game-checkers-board-wood') && !checkers.includes('game-checkers-board-neon'), 'Checkers Profile must not hard-code the old four-board subset');
expect(checkers.includes('isCheckersItemEquipped(item)') && checkers.includes('inventory?.equipped'), 'equipped state must come from authoritative inventory');

// Manual-device regression: a generic non-Checkers panel must never survive under an active Checkers tab.
expect(checkers.includes('hasCanonicalCheckersMarkup'), 'active Checkers panel must verify its canonical markup instead of trusting a stale signature');
expect(checkers.includes("panel.removeAttribute('data-mgw-checkers-profile-signature')"), 'leaving Checkers must invalidate the Checkers panel signature');
expect(checkers.includes('data-mgw-checkers-profile-group') && checkers.includes('data-mgw-checkers-profile-empty'), 'Checkers panel must expose canonical ownership markers');

// Store presentation is the visual source of truth for Profile cards and sheet previews.
for (const cssOwner of [
  'cosmetics.css?v=3&mvp19_6=full-store-v1',
  'store-visual-corrective-v3.css?v=1&mvp19_6=manual-review-pass-3',
  'store-boards-pieces-polish-v2.css?v=1&mvp19_6=board-source-parity',
  'store-effects-live-board-v1.css?v=3&mvp19_6=promotion-destination-parity-v1',
  'store-effects-final-centering-v1.css?v=2&mvp19_6=king-readable-v2',
]) expect(checkers.includes(cssOwner), `Profile must reuse accepted Store preview owner ${cssOwner}`);
expect(checkers.includes('store-v2-mini-checkers-board'), 'Profile board cards must use Store board preview structure');
expect(checkers.includes('store-v2-mini-checkers-pieces'), 'Profile checker-set cards must use Store piece preview structure');
expect(checkers.includes('store-v2-mini-checkers-effect'), 'Profile effect cards must use Store effect preview structure');
expect(checkers.includes('mgw-checkers-piece-crown') && checkers.includes('mgw-checkers-piece-mark'), 'Profile king/damka preview must retain accepted crown + MG branding');
expect(checkers.includes('IntersectionObserver') && checkers.includes("classList.toggle('is-previewing', visible)"), 'Profile Checkers effects must remain visibility-owned');
expect(checkers.includes('observedCheckersEffects') && checkers.includes('pruneDisconnectedCheckersEffectPreviews'), 'detached Checkers effect cards must be pruned from the observer');
expect(!checkers.includes('void preview.offsetWidth') && !checkers.includes('1900') && !checkers.includes('260'), 'Profile Checkers effects must not force reflow/restart timers on every animation cycle');
expect(checkers.includes("checkersDisplayName(item)") && checkers.includes("return 'Гранитные шашки'"), 'accepted granite checker presentation must carry into Profile');
expect(store.includes('startPassiveEffectPreview(preview)') && store.includes('runBoundedEffectPreview(preview)'), 'accepted Store passive effect owner must remain intact');
expect(storeSource.includes('store-effects-live-board-v1.css?v=3&mvp19_6=promotion-destination-parity-v1'), 'Profile parity must target the current accepted Store effect CSS identity');

// Profile cards keep the compact landscape shell, but every Checkers primitive must be fully contained by height.
expect(css.includes('.profile-v2-game-card{overflow:visible}'), 'Profile card shell must not clip accepted non-Checkers artwork');
expect(css.includes('store-v2-mini-checkers-board') && css.includes('height:100%!important'), 'base parity must preserve complete Checkers board geometry');
expect(css.includes('store-v2-mini-checkers-effect') && css.includes('background:transparent!important'), 'Checkers effect card media must use the full board without the old black inset frame');
expect(css.includes('mgw-checkers-final-one-cell') && css.includes('infinite both!important'), 'visible Profile effect cards must preserve continuous animation without class teardown');
expect(manualRepairCss.includes(':not(.is-previewing)') && manualRepairCss.includes('animation:none!important'), 'non-visible Profile Checkers effect cards must pause their infinite animations');
expect(manualRepairCss.includes('height:76px!important') && manualRepairCss.includes('aspect-ratio:auto!important'), 'final Profile owner must keep the accepted compact landscape card media height');
expect(manualRepairCss.includes('width:auto!important') && manualRepairCss.includes('height:100%!important') && manualRepairCss.includes('aspect-ratio:1 / 1!important'), 'board and effect primitives must fit the card by height and remain square');
expect(manualRepairCss.includes('store-v2-mini-checkers-pieces') && manualRepairCss.includes('transform:scale(.62)!important'), 'checker-set primitive must be scaled to fit completely inside the compact card preview');
expect(manualRepairCss.includes('@media (max-width:360px)') && manualRepairCss.includes('height:69px!important'), 'small mobile Profile cards must retain the accepted compact height');
expect(layout.includes('profile-game-cosmetics-parity-v1.css?v=3&mvp19_6=profile-card-visual-repair-v3'), 'Profile wrapper must cache-bust the repaired parity stylesheet');
expect(layout.includes('profile-game-cosmetics-manual-repair-v3.css?v=3&mvp19_6=checkers-card-contain-v1'), 'Profile wrapper must load the full-containment Checkers card corrective stylesheet last');

// Store mutations must immediately publish inventory state and then converge through profileV2.
expect(api.includes('function publishCosmeticInventory(result)'), 'Store API must publish authoritative inventory changes');
expect(api.includes('Array.isArray(inventory.items)'), 'Store API must project authoritative owned item IDs when the response supplies them');
expect(api.includes("document.dispatchEvent(new CustomEvent('mgw:cosmetic-inventory-changed'"), 'Store inventory changes must notify the Profile owner');
expect(api.includes('function publishProfileV2(result)') && api.includes('state.profileInventory = result.inventory'), 'profileV2 must publish authoritative inventory state');
expect(checkers.includes("document.addEventListener('mgw:cosmetic-inventory-changed', refreshAuthoritativeCheckersInventory)"), 'Checkers Profile must refresh after Store purchase/equip changes');
expect(checkers.includes('api.profileV2()'), 'Store-to-Profile refresh must converge to profileV2 rather than fake DOM ownership');

// Mobile game selector: real horizontal rail, no full-page jump and no smooth auto-scroll work on selection.
expect(css.includes('overflow-x:auto!important'), 'Profile game tabs must have real horizontal scrolling');
expect(css.includes('flex-wrap:nowrap!important'), 'Profile game tabs must remain one row');
expect(css.includes('touch-action:pan-x'), 'Profile game tabs must support horizontal touch gestures');
expect(css.includes('-webkit-overflow-scrolling:touch'), 'Profile game tabs must support Telegram/iOS momentum scrolling');
expect(checkers.includes("closest('.profile-v2-game-tabs')") && checkers.includes('setPointerCapture'), 'desktop/pointer game-tab drag must be implemented on the rail itself');
expect(checkers.includes('keepProfileGameTabVisible') && checkers.includes('strip.scrollTo'), 'selected tab must be brought into view by rail scroll only');
expect(checkers.includes('keepProfileGameTabVisible(gameTab, false)'), 'tab selection must use immediate rail positioning instead of a delayed smooth scroll');
expect(!checkers.includes('scrollIntoView'), 'Profile tab selection must never reintroduce full-page jump behavior');
expect(manualRepairCss.includes('scroll-behavior:auto!important'), 'final Profile rail owner must disable CSS smooth-scroll lag');

// Tic-Tac-Toe must keep exactly one accepted graphical X/O owner; no text glyph may sit on top of it.
expect(manualRepairCss.includes('flex:0 0 27px!important') && manualRepairCss.includes('width:27px!important'), 'Tic-Tac-Toe tab must restore the accepted optical box');
expect(manualRepairCss.includes('linear-gradient(45deg,transparent 42%,#c79cff 43% 57%,transparent 58%)'), 'Tic-Tac-Toe tab must keep the accepted graphical X');
expect(manualRepairCss.includes('border:2px solid #7ee7ff!important'), 'Tic-Tac-Toe tab must keep the accepted graphical O ring');
expect(manualRepairCss.includes('content:""!important'), 'Tic-Tac-Toe final pseudo owners must clear duplicate text glyph content');
for (const game of ['checkers','chess']) {
  expect(css.includes(`data-profile-game-tab="${game}"`), `${game} tab must retain an explicit normalized icon owner`);
}
expect(css.includes('content:"♞"'), 'Chess tab must use a readable chess identity');

// Chess Profile board artwork should already be decoded and every card primitive must be explicitly centered.
expect(layout.includes('prewarmProfileChessArtwork') && layout.includes("['wood','tournament-dark','marble','neon']"), 'Profile must prewarm the four Chess board preview assets before first tab open');
expect(manualRepairCss.includes('data-profile-game-panel="chess"') && manualRepairCss.includes('place-items:center!important'), 'Chess Profile preview frames must explicitly center their media');
expect(manualRepairCss.includes('store-v2-mini-chess-board') && manualRepairCss.includes('justify-self:center!important'), 'Chess board, piece and effect primitives must be centered inside their Profile frames');

// Runtime graph/caches must point at this exact repair layer while accepted gameplay stays frozen.
expect(layout.includes("mgw-profile-checkers-parity.js?v=3&mvp19_6=checkers-profile-manual-repair-v3"), 'active Profile wrapper must import the repaired Checkers owner');
expect(manifest.includes('mgw-profile-chess-layout-v2.js?v=9') && manifest.includes('profile_card_visual=checkers-contain-preview-v1') && manifest.includes('profile_perf=observer-cycle-v2'), 'manifest must publish the Checkers contained-preview Profile owner identity');
expect(manifest.includes('client.js?v=1136') && manifest.includes('profile_inventory=store-sync-v1'), 'manifest must retain Store/Profile inventory synchronization');
expect(manifest.includes("'./assets/js/games/checkers/renderer.js?v=57' => './assets/js/checkers-cosmetics/renderer-real-flight-cascade-v1.js?v=2&mvp19_6=all-paid-real-flight-v1&parent=single-flight-dom-v2&css=live-effects-v6&move=trail-only-v1'"), 'accepted Checkers gameplay/effect runtime identity must remain frozen');
expect(chess.includes('const CHESS_PROFILE_ITEMS = Object.freeze({'), 'accepted Chess Profile parity owner must remain present');
expect(chess.includes("game-chess-effect-check") && chess.includes('quantum-echo'), 'accepted Chess effect Profile presentation must remain intact');

console.log('MVP-19.6 Profile game-cosmetics parity contract: OK');
