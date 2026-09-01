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

expect(profile.includes("{ layer:'theme', title:'Поля' }"), 'Profile must group owned game fields');
expect(profile.includes("{ layer:'elements', title:'Знаки' }"), 'Profile must group owned game marks');
expect(profile.includes("{ layer:'effect', title:'Эффекты' }"), 'Profile must group owned game effects');
expect(profile.includes("item.owned === true && item.item_type === 'game'"), 'Profile collection must display owned game items only');
expect(profile.includes('function ownedGameCosmeticGames()'), 'Profile must group owned cosmetics by game before rendering');
expect(profile.includes("const explicit = String(metadata.game_type || '').trim();"), 'Profile game grouping must prefer canonical metadata game_type');
expect(profile.includes("return family.startsWith('game_') ? family.slice(5) : '';"), 'Profile game grouping must retain a family fallback for rollout compatibility');
expect(profile.includes('data-profile-game-tab'), 'Profile must expose horizontal per-game collection tabs');
expect(profile.includes("activeCollectionGame = 'tictactoe'"), 'Profile must keep a deterministic first game selection');
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
expect(!profile.includes('Игровая косметика'), 'unclear game-cosmetics wording must not be visible in Profile');

const openProfileStart = profile.indexOf('export async function openProfile()');
const freshHydration = profile.indexOf('applyProfileResponse(await api.profileV2());', openProfileStart);
const visibleProfile = profile.indexOf("showScreen('profile');", freshHydration);
expect(openProfileStart >= 0 && freshHydration > openProfileStart && visibleProfile > freshHydration, 'Profile must hydrate the authoritative inventory before painting a newly opened Profile');

expect(profileCss.includes('.profile-v2-game-collection'), 'Profile game collection layout must exist');
expect(profileCss.includes('.profile-v2-game-tabs{display:flex'), 'Profile must keep games in one horizontal selector row');
expect(profileCss.includes('overflow-x:auto'), 'future game tabs must scroll horizontally instead of stacking vertically');
expect(profileCss.includes('.profile-v2-game-tab.active'), 'active game tab must have an explicit selected state');
expect(profileCss.includes('.profile-v2-game-card.active'), 'equipped game item must have an explicit selected state');
expect(profileCss.includes('.profile-v2-game-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))'), 'mobile Profile game items must remain compact');
expect(profileCss.includes('.store-v2-game-preview'), 'Profile layout may size but must not duplicate Store cosmetic artwork');
expect(mainCss.includes('profile-corrective.css?v=7&mvp19=profile-collection&mvp19_3=game-tabs&fresh-selection&ttt-mark=css'), 'active CSS graph must publish compact game tabs and stable Tic Tac Toe mark geometry');
expect(manifest.includes('mvp19_3_3=game-tabs-fresh'), 'active Profile runtime identity must publish fresh game-tab collection');
expect(manifest.includes('mvp19_3=profile-game-tabs-fresh'), 'active main CSS identity must publish Profile game-tab polish');

expect(inventory.includes('public function equip(string $mgwId, string $itemId): array'), 'ProductInventoryService must remain the equip owner');
expect(inventory.includes('public function unequip(string $mgwId, string $equipSlot): array'), 'ProductInventoryService must remain the unequip owner');
expect(endpoint.includes('$store->equipGameItem($mgwId'), 'existing game cosmetic endpoint must retain its bounded game-item validation path');
expect(storeService.includes('return $this->inventory->equip($mgwId, $itemId);'), 'game-item validation path must delegate equip to ProductInventoryService');
expect(endpoint.includes('$inventory->unequip($mgwId, $equipSlot)'), 'existing game cosmetic endpoint must delegate unequip to ProductInventoryService');

console.log('MVP-19.3 Profile-owned game cosmetics polish contract: OK');