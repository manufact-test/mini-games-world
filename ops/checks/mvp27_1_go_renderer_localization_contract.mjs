import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const renderer = read('app/assets/js/games/go/renderer.js');
const wrapper = read('app/assets/js/games/go/renderer-cosmetics-v1.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(renderer), 'Active Go base renderer must contain zero hardcoded Cyrillic.');
assert(renderer.includes("from '@mgw/i18n'") && renderer.includes('const goText ='), 'Go renderer must use canonical @mgw/i18n.');
assert(renderer.includes("onAction?.({ type:'cell', cell });"), 'Go cell action ownership must remain unchanged.');
assert((renderer.match(/onAction\?\.\(\{ type:'pass' \}\);/g) || []).length === 2, 'Both accepted Go pass action paths must remain unchanged.');
assert(renderer.includes('const captureStart = 330;'), 'Accepted Go capture start must remain 330ms.');
assert(renderer.includes('captureStart + Math.min(index, 10) * 38'), 'Accepted Go capture stagger must remain 38ms.');
assert(renderer.includes(': 520;'), 'Accepted no-capture animation path must remain 520ms.');
assert(renderer.includes("return [9,13].includes(size) ? size : 9;"), 'Accepted 9x9/13x13 board-size normalization must remain unchanged.');
assert(renderer.includes('game?.last_captured_cells') && renderer.includes('game?.final_score'), 'Authoritative capture and final-score projections must remain Go renderer inputs.');
assert(renderer.includes("String(lastMove?.type || '') !== 'place'"), 'Placement animation must remain gated by authoritative last_move type.');
assert(wrapper.includes("from './renderer.js?v=70&base=mvp11-accepted&mvp27_1=localized-v1';"), 'Accepted Go cosmetics wrapper must load the localized base renderer.');
assert(wrapper.includes('renderBaseGoSurface(args);') && wrapper.includes('decorateLiveGo({ game, me, container, size, removedCells });'), 'Cosmetics must still decorate only after the authoritative base render.');
assert(!wrapper.includes('gameAction(') && !wrapper.includes('api.'), 'Go cosmetics wrapper must remain presentation-only.');
assert(manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2&live_effects=stable-overlay-v9&mvp27_1=go-renderer-localized-v1'"), 'Manifest must publish the localized active Go chain.');
assert(Number(ru._meta?.version || 0) >= 46, 'RU locale revision must retain Go renderer localization v46 or a newer successor.');
assert(ru.games?.go?.ui?.status?.your_turn === 'Ваш ход', 'Accepted RU Go turn copy must remain unchanged.');
assert(ru.games?.go?.ui?.confirm?.body === 'Это второй пас подряд. После него территория будет подсчитана автоматически.', 'Accepted RU second-pass confirmation must remain unchanged.');
assert(ru.games?.go?.ui?.events?.capture_self === 'Ваш ход — снимаем окружённую группу', 'Accepted RU Go capture copy must remain unchanged.');
assert(ru.games?.go?.ui?.score?.detail === 'Ваша территория: {mineTerritory} · соперника: {theirTerritory} · komi белых: {komi}', 'Accepted RU Go score copy must remain unchanged.');
assert(ru.games?.go?.ui?.cell?.label === 'Ряд {row}, столбец {col}: {state}', 'Accepted RU Go accessibility copy must remain unchanged.');
assert(Number(baseline.cyrillic_lines_total) <= 3333 && Number(baseline.by_scope?.client) <= 1652, 'Go localization debt must preserve or improve the accepted post-Go baseline.');

console.log('MVP27_1_GO_RENDERER_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_GO_RENDERER_HARDCODED_CYRILLIC=0');
