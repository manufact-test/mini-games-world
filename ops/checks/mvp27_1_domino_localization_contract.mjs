import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const base = read('app/assets/js/games/domino/renderer.js');
const cosmetics = read('app/assets/js/games/domino/renderer-cosmetics-v1.js');
const corrective = read('app/assets/js/games/domino/renderer-cosmetics-corrective-v25.js');
const manual = read('app/assets/js/games/domino/renderer-live-manual-v30.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const debt = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

for (const [path, source] of [
  ['renderer.js', base],
  ['renderer-cosmetics-corrective-v25.js', corrective],
]) assert(!/[\u0400-\u04FF]/.test(source), 'Active Domino presentation owner must contain zero hardcoded Cyrillic: ' + path);

assert(base.includes("from '@mgw/i18n'") && base.includes('plural,'), 'Domino base renderer must use canonical i18n and plural ownership.');
assert(base.includes("dominoPlural('tile_count'") && base.includes("dominoPlural('event.draw_self'"), 'Domino tile grammar must use locale plural rules.');
assert(base.includes("onAction?.({type:'play', tile, side})") && base.includes("onAction?.({type:'draw'})"), 'Domino play/draw action ownership must remain unchanged.');
assert(corrective.includes("button.dataset.dominoFinaleQa = 'preview'") && corrective.includes("dominoText('qa.test')"), 'Corrective finale QA owner must remain active and localized.');

assert(cosmetics.includes("from './renderer.js?v=76&base=mvp19-9-live-native-effects-v1&mvp27_1=localized-v1'"), 'Cosmetics owner must cache-bust localized base renderer.');
assert(corrective.includes("from './renderer-cosmetics-v1.js?v=8&mvp19_9=live-native-effects-v1&mvp27_1=localized-v1'"), 'Corrective owner must cache-bust localized cosmetics renderer.');
assert(manual.includes("from './renderer-cosmetics-corrective-v25.js?v=3") && manual.includes('mvp27_1=localized-v1'), 'Manual v30 owner must cache-bust localized corrective renderer.');
assert(manifest.includes("renderer-live-manual-v30.js?v=3") && manifest.includes('mvp27_1=domino-localized-v1'), 'Canonical manifest must publish localized Domino chain.');

assert(ru._meta?.version === 37, 'RU locale revision must publish Domino catalog v37.');
assert(ru.games?.domino?.ui?.status?.your_turn === 'Ваш ход', 'Accepted RU turn copy changed.');
assert(ru.games?.domino?.ui?.actions?.draw_stock === 'Добрать из запаса', 'Accepted RU draw action copy changed.');
assert(ru.games?.domino?.ui?.final?.blocked === 'Цепочка заблокирована — выигрывает меньшая сумма.', 'Accepted RU blocked-result copy changed.');
assert(ru.games?.domino?.ui?.qa?.retry === 'Повторить «Финиш цепи»', 'Accepted RU corrective QA copy changed.');

assert(debt.cyrillic_lines_total === 3498 && debt.by_scope?.client === 1817, 'Domino localization debt baseline must ratchet by exactly 33 client lines.');

console.log('MVP27_1_DOMINO_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_DOMINO_HARDCODED_CYRILLIC=0');
