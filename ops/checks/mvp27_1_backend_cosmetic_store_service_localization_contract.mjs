import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyr=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const service=read('bot/catalog/CosmeticStoreService.php');
const runtime=read('bot/catalog/CosmeticStoreRuntimePurchaseService.php');
const endpoint=read('bot/cosmetic-store.php');
const client=read('app/assets/js/screens/store-screen.js');
const api=read('app/assets/js/api/client.js');
const history=read('bot/services/HistoryService.php');
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=JSON.parse(read('app/locales/manifest.json'));
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.equal(countCyr(service),0,'CosmeticStoreService must not own direct Cyrillic player/runtime copy.');
assert.equal(countCyr(runtime),0,'CosmeticStoreRuntimePurchaseService must not own direct Cyrillic player/runtime copy.');
assert.ok(service.includes("require_once dirname(__DIR__) . '/localization/ServerLocalization.php';"),'Store service must use canonical ServerLocalization.');
assert.ok(service.includes('ServerLocalization::copy('),'Store service must delegate copy to canonical server localization.');
assert.ok(runtime.includes("ServerLocalization::copy('server.cosmetic_store.transactions.purchase_description'"),'Runtime purchase description must be locale-owned.');
assert.ok(runtime.includes("ServerLocalization::copy('server.cosmetic_store.transactions.refund_description'"),'Runtime refund description must be locale-owned.');

assert.ok(api.includes('/bot/cosmetic-store.php'),'Active API client must retain the Cosmetic Store endpoint.');
assert.ok(endpoint.includes("require_once __DIR__ . '/catalog/CosmeticStoreService.php';"),'Endpoint must retain CosmeticStoreService owner.');
assert.ok(endpoint.includes("require_once __DIR__ . '/catalog/CosmeticStoreRuntimePurchaseService.php';"),'Endpoint must retain runtime purchase owner.');
assert.ok(endpoint.includes('mgw_cosmetic_store_error_message($error->reason, $error->getMessage())'),'Endpoint must remain the public Store error-copy boundary.');

const expectedGames={
  tictactoe:'Крестики-нолики', chess:'Шахматы', checkers:'Шашки', reversi:'Реверси',
  go:'Го', domino:'Домино', four_in_a_row:'4 в ряд', battleship:'Морской бой',
};
for(const [game,value] of Object.entries(expectedGames)){
  const key='store.games.catalog_titles.'+game;
  assert.ok(service.includes("'"+key+"'"),'Store service locale key missing: '+key);
  assert.equal(locale.store?.games?.catalog_titles?.[game],value,'RU game title changed: '+game);
  assert.ok(client.includes('store.games.catalog_titles.'),'Client must retain the same canonical catalog-title namespace.');
}

const expectedTabs={coins:'Коины',profile:'Профиль',games:'Игры',bundles:'Наборы'};
for(const [tab,value] of Object.entries(expectedTabs)){
  const key='store.tabs.'+tab;
  assert.ok(service.includes("'"+key+"'"),'Store service tab key missing: '+key);
  assert.equal(locale.store?.tabs?.[tab],value,'RU Store tab changed: '+tab);
}
assert.ok(client.includes("labelKey:'store.tabs.coins'"),'Client must remain locale owner for visible Store tabs.');

const expectedBundles={
  tictactoe:'Неоновый комплект',
  chess:'Неоновый комплект шахмат',
  checkers:'Неоновый комплект шашек',
  reversi:'Неоновый комплект Реверси',
  go:'Неоновый комплект Го',
  domino:'Неоновый комплект домино',
  four_in_a_row:'Неоновый комплект 4 в ряд',
  battleship:'Неоновый комплект Морского боя',
};
for(const [game,value] of Object.entries(expectedBundles)){
  const key='store.bundles.presentation.'+game+'.bundle_title';
  assert.ok(service.includes("'"+key+"'"),'Store service bundle key missing: '+key);
  assert.equal(locale.store?.bundles?.presentation?.[game]?.bundle_title,value,'RU bundle title changed: '+game);
}
assert.ok(client.includes('store.bundles.presentation.'),'Client must retain canonical bundle-title namespace.');

assert.equal(locale.server?.cosmetic_store?.transactions?.purchase_description,'Покупка косметики MGW');
assert.equal(locale.server?.cosmetic_store?.transactions?.refund_description,'Возврат коинов за незавершённую покупку косметики');
assert.ok(runtime.includes("'type' => 'balance_change'") && runtime.includes("'category' => 'cosmetic_purchase_refund'"),'Refund must remain the same balance-change category.');
assert.ok(history.includes("$description = $this->cleanDescription((string)($tx['description'] ?? ''));"),'History must retain transaction description projection.');
assert.ok(history.includes("if ($type === 'balance_change')"),'History balance-change owner changed unexpectedly.');

for(const token of [
  "public const PURCHASE_TRANSACTION_TYPE = 'cosmetic_purchase';",
  "public const PURCHASE_PENDING_STATUS = 'debited';",
  "public const PURCHASE_COMPLETED_STATUS = 'completed';",
  "'auto_equip' => false",
  "'duplicate_purchase' => false",
  "'duplicate_compensation' => false",
]) assert.ok(service.includes(token),'Frozen Store product invariant missing: '+token);

assert.ok(Number(locale?._meta?.version)>=68,'RU catalog must be Store service revision 68 or newer.');
assert.equal(manifest.default_locale,'ru');
assert.equal(manifest.fallback_locale,'ru');
assert.deepEqual(manifest.supported_locales,['ru'],'MVP-27.1 production manifest must remain truthfully RU-only.');

assert.ok(Number.isInteger(Number(baseline.scanned_files)) && Number(baseline.scanned_files) > 0,'Successor classification may reduce scan coverage only through an explicit ownership proof; the player-facing audit itself must remain populated.');
assert.ok(Number(baseline.cyrillic_lines_total)<=1300,'Backend debt must ratchet to the Store-service ceiling or lower.');
assert.equal(Number(baseline.by_scope?.client),0);
assert.ok(Number(baseline.by_scope?.backend)<=1300);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 Cosmetic Store service localization: OK — two active Store service owners use canonical locale copy; product/economy semantics preserved; backend debt 1348 -> 1300.');
