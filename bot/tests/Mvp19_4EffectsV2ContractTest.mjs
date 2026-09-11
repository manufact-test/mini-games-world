import fs from 'node:fs';

const renderer = fs.readFileSync('app/assets/js/games/tictactoe/renderer.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/tictactoe/cosmetics.css', 'utf8');
const effectsV3 = fs.readFileSync('app/assets/css/games/tictactoe/effects-v3.css', 'utf8');
const migration = fs.readFileSync('bot/database/migrations/20260901_0018_tictactoe_single_effect_slot.php', 'utf8');
const store = fs.readFileSync('app/assets/js/screens/store-screen.js', 'utf8');
const storeEntry = fs.readFileSync('app/assets/js/screens/store-screen-intent-wrapper.js', 'utf8');
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
  expect(renderer.includes(token), `renderer must expose runtime effect token: ${token}`);
}
expect(renderer.includes('ttt-mark-glyph'), 'runtime mark must separate the visible X/O glyph from decorative FX layers');
expect(renderer.includes('function renderMoveEffectLayer(effect)'), 'runtime effects must own explicit FX DOM instead of relying only on pseudo elements');
expect(renderer.includes('ttt-fx-impact-flash') && renderer.includes('ttt-fx-impact-ring'), 'impact must render an explicit flash and ring');
expect((renderer.match(/ttt-fx-spark/g) || []).length >= 9, 'sparks must render a glow plus eight explicit particle nodes');
expect((renderer.match(/ttt-fx-wave-ring/g) || []).length >= 2, 'wave must render two explicit expanding rings');
expect(renderer.includes("viewerMoveEffect ? ' ttt-move-effect-enabled' : ''"), 'runtime board must suppress generic cell press feedback while a move effect is equipped');
expect(!renderer.includes('findWinningCells'), 'renderer must not keep a terminal winning-effect owner');
expect(!renderer.includes('ttt-winning-cell'), 'renderer must not paint an after-match winning effect');
expect(!renderer.includes('ttt-move-pulse'), 'legacy cell-owned move pulse class must be retired');

// C2.4: repeated authoritative polls must not destroy a one-shot move animation.
expect(renderer.includes('const previousVisualSignatures = new Map();'), 'renderer must remember the last visual board signature');
expect(renderer.includes('const canReuseBoardDom = previousBoard === board'), 'unchanged authoritative boards must be eligible for DOM reuse');
expect(renderer.includes("String(container.dataset.tttGameKey || '') === gameKey"), 'DOM reuse must be scoped to the same game');
expect(renderer.includes("container.querySelectorAll('[data-game-cell]').length === board.length"), 'DOM reuse must require a complete rendered board');
expect(renderer.includes('if (canReuseBoardDom) {\n    updateCellInteractivity(container, game, me, board);\n    return;'), 'identical poll snapshots must update controls without rebuilding marks');
expect(renderer.includes('function updateCellInteractivity(container, game, me, board)'), 'reused boards must still synchronize turn availability');
expect(renderer.includes('function visualSignatureFor(boardSize, players)'), 'cosmetic changes must invalidate DOM reuse even when the board is unchanged');

// C2.5 remains the Store preview owner; C2.6 replaces only real-match pseudo FX with cell-native DOM FX.
for (const token of ['tttFxImpact', 'tttFxSparksBurst', 'tttFxWaveRing']) {
  expect(css.includes(token), `missing Store preview keyframe: ${token}`);
}
expect(css.includes('Store loops the same runtime effect'), 'Store preview loop must remain intact during the corrective');
expect(!css.includes('storeTttImpact'), 'Store-only impact animation must remain retired');
expect(!css.includes('storeTttWinningLine'), 'Store-only winning animation must remain retired');
expect(!css.includes('storeTttMovePulse'), 'Store-only wave animation must remain retired');

expect(effectsV3.includes('Runtime effects are sized from the whole cell'), 'C2.6 must document cell-native sizing');
expect(effectsV3.includes('#gameBoard[data-game-type="tictactoe"] .ttt-mark{') && effectsV3.includes('width:100%;') && effectsV3.includes('height:100%;'), 'runtime FX owner must fill the whole cell instead of inheriting the 30px glyph box');
expect(effectsV3.includes('content:none !important;'), 'old real-match pseudo FX must be disabled so they cannot compete with explicit nodes');
expect(effectsV3.includes('.ttt-fx-layer{'), 'runtime must expose an absolute cell-sized FX layer');
expect(effectsV3.includes('.ttt-fx-spark:nth-child(9)'), 'sparks must have eight individually positioned particles');
expect(effectsV3.includes('left:var(--spark-x);top:var(--spark-y)'), 'spark particles must physically travel away from the sign');
expect(effectsV3.includes('.ttt-fx-wave-ring + .ttt-fx-wave-ring{animation-delay:.14s}'), 'wave must visibly separate its two rings in time');
expect(effectsV3.includes('scale(3.72)'), 'wave ring must expand far beyond the X/O glyph');
expect(effectsV3.includes('.game-board-screen[data-game-type="tictactoe"] .board-wrap{overflow:visible}'), 'the real TTT board wrapper must not clip expanding FX');
expect(effectsV3.includes('@media (prefers-reduced-motion:reduce)'), 'new FX must respect reduced-motion preferences');

expect(store.includes('Один выбранный эффект срабатывает при каждом ходе'), 'Store must explain single-effect move-time behavior');
expect(store.includes('data-store-v2-unequip'), 'selected game cosmetic must expose a remove action');
expect(store.includes('>Снять</button>'), 'selected game cosmetic button must say Снять');
expect(store.includes('ttt-mark ttt-effect-mark ttt-fx-${safeVariant}'), 'Store preview must keep the canonical effect class identities');
expect(store.includes("'winning-line':'sparks'"), 'stale cached winning-line metadata must preview as sparks during rollout');
expect(store.includes("'move-pulse':'wave'"), 'stale cached move-pulse metadata must preview as wave during rollout');
expect(store.includes('function isKnownSelectableSlot(slot)'), 'Store must validate unequip against the current selectable cosmetic catalogue');
expect(store.includes("if (itemType === 'game' && normalized.startsWith('game_')) return true;"), 'Store selectable slot validation must preserve all active game cosmetics');
expect(store.includes("return itemType === 'profile' && itemFamily === 'name_color' && normalized === 'profile_name_color';"), 'Store selectable slot validation must explicitly allow Profile name colors');
expect(!store.includes("slot !== 'game_tictactoe_effect'"), 'Store client must not hardcode unequip to effects only');
expect(store.includes('if (!purchaseBusy && !equipBusy) applyStoreResponse(result);'), 'background Store refresh must not overwrite an active cosmetic mutation');
expect(store.includes('if (!purchaseBusy && !equipBusy) {\n      renderStore();'), 'fresh background Store snapshot must repaint product cards, not only the balance');
expect(toast.includes("'Предмет выбран.'"), 'redundant Store equip acknowledgement must be explicitly silent');
expect(toast.includes("'Оформление снято.'"), 'redundant Store unequip acknowledgement must be explicitly silent');
expect(toast.includes('SILENT_ACKNOWLEDGEMENTS.has(normalized)'), 'toast owner must suppress only the redundant acknowledgements before rendering');
expect(api.includes('cosmeticStoreUnequip'), 'API client must expose cosmetic unequip');
expect(endpoint.includes("if ($action === 'unequip')"), 'Store endpoint must accept unequip action');
expect(endpoint.includes("$isGameSlot = (string)($catalogItem['item_type'] ?? '') === 'game'"), 'Store endpoint must retain explicit game-slot classification');
expect(endpoint.includes("str_starts_with($equipSlot, 'game_')"), 'Store endpoint must keep the canonical game-slot prefix guard');
expect(endpoint.includes("mgw_store_profile_name_color($catalogItem)"), 'Store endpoint must explicitly classify Profile name colors');
expect(endpoint.includes("mgw_store_profile_badge($catalogItem)"), 'Store endpoint must explicitly classify Profile badges');
expect(endpoint.includes("mgw_store_profile_frame($catalogItem)"), 'Store endpoint must explicitly classify Profile frames');
const backgroundProfileSupported = endpoint.includes('function mgw_store_profile_background');
if (backgroundProfileSupported) {
  expect(endpoint.includes("mgw_store_profile_background($catalogItem)"), 'Store endpoint must explicitly classify Profile backgrounds when that family is active');
}
const expectedProfileUnequipGuard = backgroundProfileSupported
  ? 'if ($isGameSlot || mgw_store_profile_name_color($catalogItem) || mgw_store_profile_badge($catalogItem) || mgw_store_profile_frame($catalogItem) || mgw_store_profile_background($catalogItem))'
  : 'if ($isGameSlot || mgw_store_profile_name_color($catalogItem) || mgw_store_profile_badge($catalogItem) || mgw_store_profile_frame($catalogItem))';
expect(endpoint.includes(expectedProfileUnequipGuard), 'Store endpoint must restrict unequip to game cosmetics or explicitly supported Profile cosmetic families');
expect(endpoint.includes("(string)($catalogItem['catalog_status'] ?? '') !== 'active'"), 'Store endpoint must only accept active catalogue slots');
expect(endpoint.includes("(string)($catalogItem['equip_slot'] ?? '') !== $equipSlot"), 'Store endpoint must match the requested slot to the catalogue');
expect(!endpoint.includes("$equipSlot !== 'game_tictactoe_effect'"), 'Store endpoint must not hardcode unequip to effects only');

expect(mainCss.includes('c2_1=single-slot-parity'), 'active CSS graph must publish C2.1 identity');
expect(mainCss.includes('c2_5=visible-mark-layer'), 'active CSS graph must preserve C2.5 visible runtime layer identity');
expect(mainCss.includes("./games/tictactoe/effects-v3.css?v=1&c2_6=cell-native-dom-fx"), 'active CSS graph must load C2.6 cell-native FX after cosmetics');
expect(mainCss.includes('.has-shell-chrome .screen[data-screen="store"] .store-v2-shell{padding-bottom:18px}'), 'Store primary screen must not stack the old 78px tail on top of shell navigation spacing');
expect(manifest.includes('c2_1=single-slot-parity'), 'active runtime manifest must publish C2.1 identity');
expect(manifest.includes('store-screen-intent-wrapper.js?v=1') && manifest.includes('mobile=intent-only'), 'active runtime manifest must publish the mobile intent-only Store entry');
expect(storeEntry.includes("./store-screen.js?v=44&intent_base=1"), 'Store entry must delegate to the accepted versioned Store owner');
expect(store.includes('Один выбранный эффект срабатывает при каждом ходе') && store.includes('data-store-v2-unequip'), 'delegated Store owner must preserve C2.1 single-effect selection UI');
expect(manifest.includes('c2_1=effect-unequip'), 'active runtime manifest must cache-bust the API unequip client');
expect(store.includes('if (!purchaseBusy && !equipBusy) applyStoreResponse(result);') && store.includes('if (!purchaseBusy && !equipBusy) {\n      renderStore();'), 'delegated Store owner must preserve C2.2 selection consistency under background refresh');
expect(manifest.includes('c2_4=poll-persistent-effects'), 'active runtime manifest must preserve the poll-persistent Tic Tac Toe renderer');
expect(manifest.includes('c2_5=visible-mark-layer'), 'active runtime manifest must preserve C2.5 visible mark layering');
expect(manifest.includes('c2_6=cell-native-dom-fx'), 'active runtime manifest must publish C2.6 cell-native DOM FX');
expect(manifest.includes("renderer.js?v=58"), 'active renderer identity must preserve C2.6');
expect(/main\.css\?v=\d+/.test(manifest), 'active main CSS identity must remain versioned after later bounded UI work');
expect(manifest.includes("'./assets/js/components/toast.js?v=27' => './assets/js/components/toast.js?v=29&store=quiet-cosmetic-equip-all'"), 'active runtime manifest must cache-bust all redundant cosmetic equip/unequip acknowledgements');
expect(manifest.includes('store=compact-tail'), 'active runtime manifest must cache-bust compact Store bottom spacing');

console.log('MVP-19.4 effects C2.6 cell-native DOM FX contract: OK');