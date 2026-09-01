import fs from 'node:fs';

const profile = fs.readFileSync('app/assets/js/screens/profile-screen-v110.js', 'utf8');
const profileCss = fs.readFileSync('app/assets/css/screens/profile-corrective.css', 'utf8');
const mainCss = fs.readFileSync('app/assets/css/main.css', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const inventory = fs.readFileSync('bot/catalog/ProductInventoryService.php', 'utf8');
const storeService = fs.readFileSync('bot/catalog/CosmeticStoreService.php', 'utf8');
const endpoint = fs.readFileSync('bot/cosmetic-store.php', 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(profile.includes("{ slot:'game_tictactoe_theme', layer:'theme', title:'Поля' }"), 'Profile must group owned Tic Tac Toe fields');
expect(profile.includes("{ slot:'game_tictactoe_elements', layer:'elements', title:'Знаки' }"), 'Profile must group owned Tic Tac Toe marks');
expect(profile.includes("{ slot:'game_tictactoe_effect', layer:'effect', title:'Эффекты' }"), 'Profile must use the canonical single Tic Tac Toe effect slot');
expect(profile.includes("item.owned === true && item.item_type === 'game'"), 'Profile collection must display owned game items only');
expect(profile.includes("String(item.item_family || '') === 'game_tictactoe'"), 'first Profile game collection must be scoped to Tic Tac Toe');
expect(profile.includes("String(item?.metadata?.game_type || '') === 'tictactoe'"), 'Profile must accept canonical Tic Tac Toe metadata projection');
expect(profile.includes('state.profileInventory.equipped'), 'selected state must come from the canonical inventory snapshot');
expect(profile.includes('api.cosmeticStoreEquip(itemId)'), 'Profile must reuse the existing canonical game-cosmetic equip transport');
expect(profile.includes('api.cosmeticStoreUnequip(slot)'), 'Profile must reuse the existing canonical game-cosmetic unequip transport');
expect(profile.includes('applyProfileResponse(await api.profileV2())'), 'Profile mutation must converge back to authoritative Profile inventory');
expect(profile.includes('state.profileInventory = previousInventory'), 'failed optimistic mutation must restore the previous inventory snapshot');
expect(!profile.includes('cosmeticStorePurchase('), 'Profile collection must not become a purchase owner');
expect(profile.includes('store-v2-game-preview'), 'Profile must reuse the accepted Store/game cosmetic preview artwork classes');
expect(profile.includes('ttt-effect-mark ttt-fx-${safeVariant}'), 'Profile effect preview must reuse canonical effect class identities');
expect(profile.includes("'winning-line':'sparks'"), 'Profile preview must tolerate rollout-era Sparks metadata');
expect(profile.includes("'move-pulse':'wave'"), 'Profile preview must tolerate rollout-era Wave metadata');

expect(profileCss.includes('.profile-v2-game-collection'), 'Profile game collection layout must exist');
expect(profileCss.includes('.profile-v2-game-card.active'), 'equipped game cosmetic must have an explicit selected state');
expect(profileCss.includes('.profile-v2-game-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))'), 'mobile Profile game cosmetics must remain compact');
expect(profileCss.includes('.store-v2-game-preview'), 'Profile layout may size but must not duplicate Store cosmetic artwork');
expect(mainCss.includes('profile-corrective.css?v=5'), 'active CSS graph must publish Profile game cosmetics styles');
expect(manifest.includes('mvp19_3_2=game-cosmetics'), 'active Profile runtime identity must publish game cosmetics collection');
expect(manifest.includes('mvp19_3=profile-game-cosmetics'), 'active main CSS identity must publish Profile game cosmetics collection');

expect(inventory.includes('public function equip(string $mgwId, string $itemId): array'), 'ProductInventoryService must remain the equip owner');
expect(inventory.includes('public function unequip(string $mgwId, string $equipSlot): array'), 'ProductInventoryService must remain the unequip owner');
expect(endpoint.includes('$store->equipGameItem($mgwId'), 'existing game cosmetic endpoint must retain its bounded game-item validation path');
expect(storeService.includes('return $this->inventory->equip($mgwId, $itemId);'), 'game-item validation path must delegate equip to ProductInventoryService');
expect(endpoint.includes('$inventory->unequip($mgwId, $equipSlot)'), 'existing game cosmetic endpoint must delegate unequip to ProductInventoryService');

console.log('MVP-19.3 Profile-owned game cosmetics contract: OK');
