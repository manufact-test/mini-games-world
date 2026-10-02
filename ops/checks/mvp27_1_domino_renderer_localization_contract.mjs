import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const renderer = read('app/assets/js/games/domino/renderer.js');
const live = read('app/assets/js/games/domino/renderer-cosmetics-v1.js');
const corrective = read('app/assets/js/games/domino/renderer-cosmetics-corrective-v25.js');
const manual = read('app/assets/js/games/domino/renderer-live-manual-v30.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(renderer), 'Active Domino base renderer must contain zero hardcoded Cyrillic.');
assert(renderer.includes("from '@mgw/i18n'") && renderer.includes("const dominoText ="), 'Domino renderer must use canonical @mgw/i18n.');
assert(renderer.includes("onAction?.({type:'play', tile, side});"), 'Domino play action ownership must remain unchanged.');
assert(renderer.includes("onAction?.({type:'draw'});"), 'Domino draw action ownership must remain unchanged.');
assert(renderer.includes("function chainDensity(count)") && renderer.includes("function createMediumRoute()") && renderer.includes("function createLongRoute()"), 'Domino chain geometry owners must remain unchanged.');
assert(renderer.includes("dominoText('errors.tile_not_playable')"), 'Invalid-tile feedback must resolve through locale ownership.');
assert(renderer.includes("const key = actorMe ? 'event.draw_self' : 'event.draw_opponent';"), 'Draw actor presentation must retain both branches.');
assert(live.includes("from './renderer.js?v=76&base=mvp19-9-live-native-effects-v1&mvp27_1=localized-v1'"), 'Native cosmetics owner must load the localized base renderer.');
assert(corrective.includes("renderer-cosmetics-v1.js?v=8") && corrective.includes("mvp27_1=domino-renderer-localized-v1"), 'Corrective owner must publish a fresh localized native-cosmetics identity.');
assert(manual.includes("renderer-cosmetics-corrective-v25.js?v=3") && manual.includes("mvp27_1=domino-renderer-localized-v1"), 'Manual stability owner must publish a fresh localized corrective identity.');
assert(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-live-manual-v30.js?v=3&mvp19_9=accepted-live-stability-v30&parent=accepted-live-corrective-v25&visual_portal=v38&precision_geometry=v41&gesture_owner=v27&precision_static=2&live_effects=v41&mvp27_1=domino-renderer-localized-v1'"), 'Canonical manifest must publish the localized Domino outer owner.');
assert(Number(ru._meta?.version || 0) >= 37, 'RU locale revision must retain Domino renderer localization v37 or a newer successor.');
assert(ru.games?.domino?.ui?.status?.your_turn === 'Ваш ход', 'Accepted RU Domino status copy must remain unchanged.');
assert(ru.games?.domino?.ui?.actions?.draw === 'Добрать из запаса', 'Accepted RU Domino draw action copy must remain unchanged.');
assert(ru.games?.domino?.ui?.event?.play_self === 'Вы поставили {a}–{b}', 'Accepted RU Domino play feedback must remain unchanged.');
assert(ru.games?.domino?.ui?.placement?.aria === 'Поставить костяшку {a}–{b} к {side} концу цепочки', 'Accepted RU Domino accessibility copy must remain unchanged.');
assert(baseline.cyrillic_lines_total <= 3503 && baseline.by_scope?.client <= 1822, 'Domino renderer localization debt must never regress above the accepted post-Domino baseline.');

console.log('MVP27_1_DOMINO_RENDERER_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_DOMINO_RENDERER_HARDCODED_CYRILLIC=0');
