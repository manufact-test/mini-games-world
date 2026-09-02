import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const client = fs.readFileSync(path.join(root, 'app/assets/js/api/client.js'), 'utf8');
const manifest = fs.readFileSync(path.join(root, 'app/runtime/client/version-manifest.php'), 'utf8');

let assertions = 0;
function assert(condition, message) {
  assertions += 1;
  if (!condition) throw new Error(message);
}

assert(client.includes('const INITIAL_BOOTSTRAP_GRACE_MS = 250;'), 'Startup hydration lane must keep a bounded bootstrap-start grace window');
assert(client.includes('let backgroundHydrationLane = Promise.resolve();'), 'Startup hydration lane must serialize noncritical reads');
assert(client.includes('function requestBootstrap(){'), 'Bootstrap must have an explicit single-flight owner');
assert(client.includes("bootstrap: () => requestBootstrap()"), 'Public bootstrap API must use the bootstrap owner');
assert(client.includes("profileV2ReadPromise = enqueueBackgroundHydration(() => requestUrl(PROFILE_V2_URL))"), 'Read-only Profile v2 hydration must use the background lane');
assert(client.includes("historyFast: () => enqueueBackgroundHydration(() => request('history'))"), 'Eager history cache hydration must use the background lane');
assert(client.includes("cosmeticStoreStatus: () => enqueueBackgroundHydration(() => requestCosmeticStore({ action:'status' }))"), 'Store status hydration must use the background lane');
assert(client.includes("cosmeticStorePurchase: (offerId, requestToken) => requestCosmeticStore({ action:'purchase'"), 'Store purchase must bypass the background lane');
assert(client.includes("cosmeticStoreEquip: itemId => requestCosmeticStore({ action:'equip'"), 'Store equip must bypass the background lane');
assert(client.includes("cosmeticStoreUnequip: equipSlot => requestCosmeticStore({ action:'unequip'"), 'Store unequip must bypass the background lane');
assert(client.includes("gameState: (gameId = null) => request('game_state'"), 'Game polling must bypass the background lane');
assert(client.includes("gameAction: (gameId, gameAction) => request('game_action'"), 'Game actions must bypass the background lane');
assert(client.includes("shopOrder: (itemId, denominationId, requestToken) => request('shop_order', { itemId, denominationId, requestToken })"), 'Legacy shop-order payload shape must remain unchanged');
assert(manifest.includes('startup=hydration-lane'), 'Active import-map delivery must carry a fresh hydration-lane client identity');

console.log(`Startup hydration lane contract passed (${assertions} assertions).`);
