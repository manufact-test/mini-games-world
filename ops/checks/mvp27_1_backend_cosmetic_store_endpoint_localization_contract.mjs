import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const endpoint=read('bot/cosmetic-store.php');
const api=read('app/assets/js/api/client.js');
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=JSON.parse(read('app/locales/manifest.json'));
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
const catalog=read('app/runtime/localization/LocalizationCatalog.php');

assert.equal(countCyrillicLines(endpoint),0,'Active Cosmetic Store endpoint must not own hardcoded Cyrillic player copy.');
assert.ok(endpoint.includes("require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';"),'Cosmetic Store endpoint must use the canonical server LocalizationCatalog owner.');
assert.ok(endpoint.includes("new LocalizationCatalog(dirname(__DIR__) . '/app/locales')"),'Cosmetic Store endpoint must load the canonical production locale directory.');
assert.ok(catalog.includes('final class LocalizationCatalog'),'Canonical server localization owner changed unexpectedly.');

assert.ok(api.includes('/bot/cosmetic-store.php'),'Canonical API client must still point Store requests at bot/cosmetic-store.php.');
for(const token of [
  'cosmeticStoreStatus: () => requestCosmeticStore',
  'cosmeticStorePurchase: (offerId, requestToken) => requestCosmeticStore',
  'cosmeticStoreEquip: itemId => requestCosmeticStore',
  'cosmeticStoreUnequip: equipSlot => requestCosmeticStore',
]) assert.ok(api.includes(token),'Active Cosmetic Store API ownership token missing: '+token);

const expected={
  already_owned:'Этот предмет уже у вас.',
  request_conflict:'Запрос покупки уже использован для другого предложения.',
  purchase_in_progress:'Предыдущая покупка этих предметов ещё завершается. Обновите магазин.',
  ownership_conflict:'Состав покупки изменился. Баланс восстановлен, обновите магазин.',
  price_changed:'Цена предложения изменилась. Баланс восстановлен, обновите магазин.',
  offer_unavailable:'Предложение магазина больше недоступно.',
  item_unavailable:'Предмет больше недоступен.',
  item_not_owned:'Сначала купите этот предмет.',
  equip_failed:'Не удалось выбрать предмет.',
  prepare_failed:'Не удалось подготовить покупку. Обновите магазин.',
  insufficient_balance:'Недостаточно коинов для покупки.',
  account_unavailable:'Профиль MGW недоступен для этой сессии.',
  purchase_failed:'Не удалось выполнить покупку.',
  invalid_request:'Некорректный запрос.',
  unavailable:'Магазин MGW временно недоступен.',
  item_cannot_equip:'Предмет нельзя выбрать.',
  profile_equip_failed:'Не удалось выбрать оформление профиля.',
  item_not_selectable:'Этот предмет нельзя выбрать через магазин.',
  slot_not_unequip:'Этот слот нельзя снять через магазин.',
  invalid_action:'Неизвестное действие магазина.',
  load_failed:'Не удалось загрузить магазин MGW.',
};
for(const [name,value] of Object.entries(expected)){
  const key='server.cosmetic_store.'+name;
  assert.ok(endpoint.includes("'"+key+"'"),'Cosmetic Store endpoint must resolve '+key+' through localization.');
  assert.equal(locale.server?.cosmetic_store?.[name],value,'RU Cosmetic Store copy changed for '+name+'.');
}

assert.ok(Number(locale?._meta?.version)>=63,'RU catalog must be backend Cosmetic Store revision 63 or newer.');
assert.equal(manifest.default_locale,'ru');
assert.equal(manifest.fallback_locale,'ru');
assert.deepEqual(manifest.supported_locales,['ru'],'Production manifest must remain truthful RU-only during MVP-27.1.');

assert.ok(Number.isInteger(Number(baseline.scanned_files)) && Number(baseline.scanned_files) > 0,'Successor classification may reduce scan coverage only through an explicit ownership proof; the player-facing audit itself must remain populated.');
assert.ok(Number(baseline.cyrillic_lines_total)<=1599,'Later backend localization slices may only reduce total debt from the accepted Cosmetic Store endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.client),0);
assert.ok(Number(baseline.by_scope?.backend)<=1599,'Later backend localization slices may only reduce backend debt from the accepted Cosmetic Store endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 backend Cosmetic Store endpoint localization: OK — active endpoint uses canonical server localization with unchanged Russian player copy; backend debt 1621 -> 1599.');
