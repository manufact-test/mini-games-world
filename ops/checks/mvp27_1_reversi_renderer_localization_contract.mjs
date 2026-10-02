import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const renderer = read('app/assets/js/games/reversi/renderer.js');
const wrapper = read('app/assets/js/games/reversi/renderer-cosmetics-v1.js');
const premium = read('app/assets/js/games/reversi/renderer-cosmetics-premium-v4.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(renderer), 'Active Reversi base renderer must contain zero hardcoded Cyrillic.');
assert(renderer.includes("from '@mgw/i18n'") && renderer.includes('const reversiText ='), 'Reversi renderer must use canonical @mgw/i18n.');
assert(renderer.includes("onAction?.({ type:'cell', cell });"), 'Legal cell action ownership must remain unchanged.');
assert(renderer.includes('const firstFlipDelay = 320;') && renderer.includes('const flipStep = 150;'), 'Accepted Reversi flip cadence must remain 320/150ms.');
assert(renderer.includes("cellElement.classList.add('flip-out')") && renderer.includes("cellElement.classList.add('flip-in')"), 'Base renderer must remain the real-disc transform owner.');
assert(renderer.includes('}, 105);') && renderer.includes("schedule(() => cellElement.classList.remove('flip-in'), 255);"), 'Accepted real-disc midpoint/completion timing must remain 105/255ms.');
assert(renderer.includes("return [6,8,10].includes(size) ? size : 8;"), 'Accepted 6x6/8x8/10x10 board-size normalization must remain unchanged.');
assert(renderer.includes('game?.last_flipped_cells') && renderer.includes('game?.legal_moves'), 'Authoritative flip and legal-move projections must remain the renderer inputs.');
assert(wrapper.includes("from './renderer.js?v=67&base=mvp14r-accepted&mvp27_1=localized-v1';"), 'Accepted cosmetic wrapper must load the localized base renderer.');
assert(wrapper.includes('renderBaseReversiSurface(args);') && wrapper.includes('decorateLiveReversi({ game, me, container });'), 'Cosmetics must still decorate only after the authoritative base render.');
assert(premium.includes("from './renderer-cosmetics-v1.js?v=4&mvp19_7=live-parity-store-motion-v3&pieces=viewer-complete-set-v1&effects=store-phased-real-cadence-v3&footer=fullwidth-scroll-v2&mvp27_1=localized-base-v1';"), 'Premium owner must publish a fresh child-wrapper identity.');
assert(manifest.includes("'./assets/js/games/reversi/renderer.js?v=66' => './assets/js/games/reversi/renderer-cosmetics-premium-v4.js?v=5&mvp19_7=line-mass-premium-v8&motion=single-transform-owner-v1&parent=live-parity-v3&footer=fullwidth-scroll-v2&mvp27_1=reversi-renderer-localized-v1'"), 'Manifest must publish the localized active Reversi chain.');
assert(ru._meta?.version === 40, 'RU locale revision must publish Reversi renderer localization v40.');
assert(ru.games?.reversi?.ui?.status?.your_turn === 'Ваш ход', 'Accepted RU Reversi turn copy must remain unchanged.');
assert(ru.games?.reversi?.ui?.errors?.illegal_move === 'Здесь нельзя поставить фишку. Выберите подсвеченную клетку.', 'Accepted RU illegal-move copy must remain unchanged.');
assert(ru.games?.reversi?.ui?.events?.finished === 'Партия завершена · ● {black} : {white} ○', 'Accepted RU terminal score copy must remain unchanged.');
assert(ru.games?.reversi?.ui?.cell?.black === '{cell}: чёрная фишка', 'Accepted RU accessibility copy must remain unchanged.');
assert(baseline.cyrillic_lines_total === 3431 && baseline.by_scope?.client === 1750, 'Reversi localization debt baseline must ratchet by exactly 22 client lines.');

console.log('MVP27_1_REVERSI_RENDERER_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_REVERSI_RENDERER_HARDCODED_CYRILLIC=0');
