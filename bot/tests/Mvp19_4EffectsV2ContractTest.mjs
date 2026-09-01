import fs from 'node:fs';

const renderer = fs.readFileSync('app/assets/js/games/tictactoe/renderer.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/tictactoe/cosmetics.css', 'utf8');
const migration = fs.readFileSync('bot/database/migrations/20260901_0018_tictactoe_single_effect_slot.php', 'utf8');
const store = fs.readFileSync('app/assets/js/screens/store-screen.js', 'utf8');
const toast = fs.readFileSync('app/assets/js/components/toast.js', 'utf8');
const api = fs.readFileSync('app/assets/js/api/client.js', 'utf8');
const endpoint = fs.readFileSync('bot/cosmetic-store.php', 'utf8');
const mainCss = fs.readFileSync('app/assets/css/main.css', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(migration.includes("private const EFFECT_SLOT = 'game_tictactoe_effect'"), 'all effects must migrate to one canonical equip slot');
expect(migration.includes("'game-ttt-effect-sign'"), 'sign item identity must be preserved');
expect(migration.includes("'game-ttt-effect-winning-line'"), 'historical winning-line item identity must be preserved');
expect(migration.includes("'game-ttt-effect-strike'"), 'historical strike item identity must be preserved');
expect(migration.includes("'display_name' => 'Искры хода'"), 'end-of-game winning effect must become a visible move-time effect');
expect(migration.includes("'variant' => 'impact'"), 'impact variant must be canonical');
expect(migration.includes("'variant' => 'sparks'"), 'sparks variant must be canonical');
expect(migration.includes("'variant' => 'wave'"), 'wave variant must be canonical');
expect(migration.includes("'event' => 'move'"), 'effects must fire during moves, not after the match');
expect(migration.includes('ORDER BY mgw_id ASC, equipped_at_utc DESC'), 'legacy multi-selection collapse must preserve the most recently equipped effect');

expect(renderer.includes('slots.game_tictactoe_effect'), 'renderer must read the canonical single effect slot');
for (const token of ['ttt-effect-mark', 'ttt-fx-impact', 'ttt-fx-sparks', 'ttt-fx-wave']) {
  expect(renderer.includes(token), `renderer must expose shared runtime effect token: ${token}`);
}
expect(renderer.includes('ttt-mark-glyph'), 'runtime mark must separate the visible X/O glyph from decorative FX layers');
expect(renderer.includes("viewerMoveEffect ? ' ttt-move-effect-enabled' : ''"), 'runtime board must suppress generic cell press feedback while a move effect is equipped');
expect(!renderer.includes('findWinningCells'), 'renderer must not keep a terminal winning-effect owner');
expect(!renderer.includes('ttt-winning-cell'), 'renderer must not paint an after-match winning effect');
expect(!renderer.includes('ttt-move-pulse'), 'legacy cell-owned move pulse class must be retired');

// C2.4: the game poller is allowed to call the renderer repeatedly for the same
// board, but an identical snapshot must not destroy/recreate the X/O DOM while
// its one-shot effect animation is still playing.
expect(renderer.includes('const previousVisualSignatures = new Map();'), 'renderer must remember the last visual board signature');
expect(renderer.includes('const canReuseBoardDom = previousBoard === board'), 'unchanged authoritative boards must be eligible for DOM reuse');
expect(renderer.includes("String(container.dataset.tttGameKey || '') === gameKey"), 'DOM reuse must be scoped to the same game');
expect(renderer.includes("container.querySelectorAll('[data-game-cell]').length === board.length"), 'DOM reuse must require a complete rendered board');
expect(renderer.includes('if (canReuseBoardDom) {\n    updateCellInteractivity(container, game, me, board);\n    return;'), 'identical poll snapshots must update controls without rebuilding marks');
expect(renderer.includes('function updateCellInteractivity(container, game, me, board)'), 'reused boards must still synchronize turn availability');
expect(renderer.includes('function visualSignatureFor(boardSize, players)'), 'cosmetic changes must invalidate DOM reuse even when the board is unchanged');

for (const token of ['tttFxImpact', 'tttFxSparksBurst', 'tttFxWaveRing']) {
  expect(css.includes(token), `missing shared runtime/store keyframe: ${token}`);
}
expect(css.includes('.ttt-effect-mark::before,.ttt-effect-mark::after'), 'effect decoration must originate from the mark itself');
expect(css.includes('.ttt-mark-glyph{') && css.includes('z-index:2;'), 'visible X/O glyph must sit above its effect decoration');
expect(css.includes('.ttt-effect-mark::before,.ttt-effect-mark::after{\n  content:"";\n  position:absolute;\n  z-index:1;'), 'runtime FX decoration must sit above the cell surface instead of behind it');
expect(css.includes('#gameBoard[data-game-type="tictactoe"] .cell{overflow:visible}'), 'real game cells must not clip mark-owned sparks or waves');
expect(css.includes('#gameBoard[data-game-type="tictactoe"].ttt-move-effect-enabled .cell:active{transform:none}'), 'generic square press animation must not visually compete with equipped mark effects');
expect(css.includes('Store loops the same runtime effect'), 'Store must continue to use the runtime visual owner');
expect(!css.includes('storeTttImpact'), 'Store-only impact animation must be retired');
expect(!css.includes('storeTttWinningLine'), 'Store-only winning animation must be retired');
expect(!css.includes('storeTttMovePulse'), 'Store-only wave animation must be retired');

expect(store.includes('Один выбранный эффект срабатывает при каждом ходе'), 'Store must explain single-effect move-time behavior');
expect(store.includes('data-store-v2-unequip'), 'selected game cosmetic must expose a remove action');
expect(store.includes('>Снять</button>'), 'selected game cosmetic button must say Снять');
expect(store.includes('ttt-mark ttt-effect-mark ttt-fx-${safeVariant}'), 'Store preview must render the same runtime effect classes');
expect(store.includes("'winning-line':'sparks'"), 'stale cached winning-line metadata must preview as sparks during rollout');
expect(store.includes("'move-pulse':'wave'"), 'stale cached move-pulse metadata must preview as wave during rollout');
expect(store.includes('function isKnownGameCosmeticSlot(slot)'), 'Store must validate unequip against the current game cosmetic catalogue');
expect(!store.includes("slot !== 'game_tictactoe_effect'"), 'Store client must not hardcode unequip to effects only');
expect(store.includes('if (!purchaseBusy && !equipBusy) applyStoreResponse(result);'), 'background Store refresh must not overwrite an active cosmetic mutation');
expect(store.includes('if (!purchaseBusy && !equipBusy) {\n      renderStore();'), 'fresh background Store snapshot must repaint product cards, not only the balance');
expect(toast.includes("'Предмет выбран.'"), 'redundant Store equip acknowledgement must be explicitly silent');
expect(toast.includes("'Оформление снято.'"), 'redundant Store unequip acknowledgement must be explicitly silent');
expect(toast.includes('SILENT_ACKNOWLEDGEMENTS.has(normalized)'), 'toast owner must suppress only the redundant acknowledgements before rendering');
expect(api.includes('cosmeticStoreUnequip'), 'API client must expose cosmetic unequip');
expect(endpoint.includes("if ($action === 'unequip')"), 'Store endpoint must accept unequip action');
expect(endpoint.includes("str_starts_with($equipSlot, 'game_')"), 'Store endpoint must restrict unequip to game slots');
expect(endpoint.includes("(string)($catalogItem['item_type'] ?? '') !== 'game'"), 'Store endpoint must validate the slot against game catalogue items');
expect(endpoint.includes("(string)($catalogItem['catalog_status'] ?? '') !== 'active'"), 'Store endpoint must only accept active catalogue slots');
expect(endpoint.includes("(string)($catalogItem['equip_slot'] ?? '') !== $equipSlot"), 'Store endpoint must match the requested slot to the catalogue');
expect(!endpoint.includes("$equipSlot !== 'game_tictactoe_effect'"), 'Store endpoint must not hardcode unequip to effects only');

expect(mainCss.includes('c2_1=single-slot-parity'), 'active CSS graph must publish C2.1 identity');
expect(mainCss.includes('c2_5=visible-mark-layer'), 'active CSS graph must cache-bust C2.5 visible runtime effect layers');
expect(mainCss.includes('.has-shell-chrome .screen[data-screen="store"] .store-v2-shell{padding-bottom:18px}'), 'Store primary screen must not stack the old 78px tail on top of shell navigation spacing');
expect(manifest.includes('c2_1=single-slot-parity'), 'active runtime manifest must publish C2.1 identity');
expect(manifest.includes('c2_1=single-effect'), 'active runtime manifest must cache-bust Store single-effect UI');
expect(manifest.includes('c2_1=effect-unequip'), 'active runtime manifest must cache-bust the API unequip client');
expect(manifest.includes('c2_2=selection-consistency'), 'active runtime manifest must cache-bust Store selection consistency');
expect(manifest.includes('c2_4=poll-persistent-effects'), 'active runtime manifest must preserve the poll-persistent Tic Tac Toe renderer');
expect(manifest.includes('c2_5=visible-mark-layer'), 'active runtime manifest must publish C2.5 visible mark layering');
expect(manifest.includes("'./assets/js/components/toast.js?v=27' => './assets/js/components/toast.js?v=28&store=quiet-equip'"), 'active runtime manifest must cache-bust quiet Store acknowledgements');
expect(manifest.includes('store=compact-tail'), 'active runtime manifest must cache-bust compact Store bottom spacing');

console.log('MVP-19.4 effects C2.5 visible mark layering contract: OK');
