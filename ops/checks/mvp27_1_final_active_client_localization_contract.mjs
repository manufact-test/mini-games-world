import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const TARGETS=Object.freeze({
  purchase:'app/assets/js/commerce/mgw-purchase-feedback.js',
  lifecycle:'app/assets/js/production-v110-match-lifecycle.js',
  lock:'app/assets/js/production-v99-explicit-lock-guard.js',
  avatar:'app/assets/js/profile/mgw-avatar-registry.js',
  victory:'app/assets/js/profile/mgw-victory-effect-selector.js',
  session:'app/assets/js/session.js',
});

const sources=Object.fromEntries(Object.entries(TARGETS).map(([key,file])=>[key,read(file)]));
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=read('app/runtime/client/version-manifest.php');
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

for(const [key,file] of Object.entries(TARGETS)){
  assert.equal(countCyrillicLines(sources[key]),0,file+' must be free of hardcoded Cyrillic');
  assert.ok(sources[key].includes("from '@mgw/i18n'"),file+' must import canonical i18n owner');
  assert.ok(!audit.includes("'"+file+"'"),file+' must remain an active factual owner rather than historical classification');
}

assert.ok(sources.purchase.includes("t('store.purchase.pending')"));
assert.ok(sources.lifecycle.includes("t('game_screen.errors.leave_restore')"));
assert.ok(sources.lock.includes("t('search.lock_default')"));
assert.ok(sources.avatar.includes("t('profile.collection.avatar_fallback')"));
assert.ok(sources.victory.includes("t('profile.victory_effects.live.player_fallback', { number:playerIndex + 1 })"));
assert.ok(sources.session.includes("t('session.lock_default')"));

assert.equal(locale.store?.purchase?.pending,'Покупаем…');
assert.equal(locale.game_screen?.errors?.leave_restore,'Не удалось завершить матч. Игра восстановлена.');
assert.equal(locale.search?.lock_default,'У вас уже идёт активная игра на другом устройстве.');
assert.equal(locale.profile?.collection?.avatar_fallback,'Аватарка');
assert.equal(locale.profile?.victory_effects?.live?.player_fallback,'Игрок {number}');
assert.equal(locale.session?.lock_default,'Игра уже открыта на другом устройстве.');
assert.ok(Number(locale?._meta?.version)>=58,'RU catalog must be final client localization revision 58 or newer');

const purchaseOwner=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const avatarOwner=read('app/assets/js/profile/mgw-avatar-presentation.js');
const victoryOwner=read('app/assets/js/profile/mgw-profile-victory-effects-v4.js');
const sessionOwner=read('app/assets/js/games/unified-game-launcher.js');

assert.ok(purchaseOwner.includes("from './commerce/mgw-purchase-feedback.js?v=1'"),'Factual purchase-feedback owner changed');
assert.ok(clean110.includes("from './production-v110-match-lifecycle.js?v=1106&release=battleship-action-quarantine'"),'Factual match lifecycle owner changed');
assert.ok(clean110.includes("from './production-v99-explicit-lock-guard.js?v=99'"),'Factual explicit lock owner changed');
assert.ok(avatarOwner.includes("from './mgw-avatar-registry.js?v=4'"),'Factual avatar registry owner changed');
assert.ok(victoryOwner.includes("from './mgw-victory-effect-selector.js?v=1'"),'Factual victory selector owner changed');
assert.ok(sessionOwner.includes("from '../session.js?v=27'"),'Factual session owner changed');

for(const identity of [
  "./assets/js/commerce/mgw-purchase-feedback.js?v=2&mvp27_1=localized-v1",
  "./assets/js/production-v110-match-lifecycle.js?v=1107&release=battleship-action-quarantine&mvp27_1=localized-v1",
  "./assets/js/production-v99-explicit-lock-guard.js?v=100&mvp27_1=localized-v1",
  "./assets/js/profile/mgw-avatar-registry.js?v=5&mvp27_1=localized-v1",
  "./assets/js/profile/mgw-victory-effect-selector.js?v=2&mvp27_1=localized-v1",
  "./assets/js/session.js?v=1132&mvp27_1=localized-v1",
]){
  assert.ok(manifest.includes(identity),'Manifest cache identity missing: '+identity);
}

assert.equal(Number(baseline.scanned_files),678);
assert.equal(Number(baseline.cyrillic_lines_total),1653);
assert.equal(Number(baseline.by_scope?.client),0);
assert.equal(Number(baseline.by_scope?.backend),1653);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 final active client localization: OK — all six factual active client owners resolve unchanged Russian copy through canonical localization; client debt is zero.');
