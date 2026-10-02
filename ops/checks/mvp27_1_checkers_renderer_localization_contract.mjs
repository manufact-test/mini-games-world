// checkers-localization-pr-sync-v1
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const renderer = read('app/assets/js/games/checkers/renderer.js');
const boardTheme = read('app/assets/js/checkers-cosmetics/renderer-board-themes.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(renderer), 'Active Checkers base renderer must contain zero hardcoded Cyrillic.');
assert(renderer.includes("from '@mgw/i18n'") && renderer.includes('const checkersText ='), 'Checkers renderer must use canonical @mgw/i18n.');
assert(renderer.includes("onAction?.({ type:'move', from, to });"), 'Checkers move action ownership must remain unchanged.');
assert(renderer.includes("if (captureRequired) toast(checkersText('errors.capture_required'));"), 'Mandatory-capture feedback must remain active and localization-owned.');
assert(renderer.includes("const orderedCells = viewerSide === 'black'") && renderer.includes("Array.from({ length:64 }"), 'Checkers board orientation and 8x8 geometry must remain unchanged.');
assert(renderer.includes("const targetMoves = selectedFrom === null") && renderer.includes("const targets = new Map("), 'Checkers legal-target selection ownership must remain unchanged.');
assert(boardTheme.includes("../games/checkers/renderer.js?v=58&base=mvp16-accepted&mvp27_1=localized-v1"), 'Accepted board-theme owner must load the localized base renderer.');
assert(manifest.includes("renderer-board-themes.js?v=14&mvp19_6=equal-grid-rows-v1") && manifest.includes("mvp27_1=checkers-renderer-localized-v1"), 'Manifest must publish a fresh board-theme localization identity.');
assert(manifest.includes("renderer-real-flight-cascade-v1.js?v=3&mvp19_6=all-paid-real-flight-v1") && manifest.includes("mvp27_1=checkers-renderer-localized-v1"), 'Manifest must publish a fresh outer Checkers owner.');
assert(Number(ru._meta?.version || 0) >= 38, 'RU locale revision must retain Checkers renderer localization v38 or a newer successor.');
assert(ru.games?.checkers?.ui?.status?.your_turn === 'Ваш ход', 'Accepted RU Checkers turn status copy must remain unchanged.');
assert(ru.games?.checkers?.ui?.errors?.capture_required === 'Есть обязательное взятие — выберите подсвеченную шашку.', 'Accepted RU mandatory-capture copy must remain unchanged.');
assert(ru.games?.checkers?.ui?.event?.your_turn === 'Ваш ход — выберите шашку', 'Accepted RU Checkers event copy must remain unchanged.');
assert(ru.games?.checkers?.ui?.cell?.white_king === 'белая дамка', 'Accepted RU Checkers accessibility copy must remain unchanged.');
assert(baseline.cyrillic_lines_total <= 3478 && baseline.by_scope?.client <= 1797, 'Checkers renderer localization debt must never regress above the accepted post-Checkers baseline.');

console.log('MVP27_1_CHECKERS_RENDERER_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_CHECKERS_RENDERER_HARDCODED_CYRILLIC=0');
