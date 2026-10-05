import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const normalized=p=>p.split(path.sep).join('/');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const META = {
  tictactoe:'app/assets/js/games/tictactoe/meta.js',
  four_in_a_row:'app/assets/js/games/four-in-a-row/meta.js',
  battleship:'app/assets/js/games/battleship/meta.js',
  checkers:'app/assets/js/games/checkers/meta.js',
  reversi:'app/assets/js/games/reversi/meta.js',
  chess:'app/assets/js/games/chess/meta.js',
  go:'app/assets/js/games/go/meta.js',
  domino:'app/assets/js/games/domino/meta.js',
};

const expectedCopy = {
  tictactoe:['Крестики-нолики','Соберите линию из своих знаков раньше соперника.'],
  four_in_a_row:['4 в ряд','Соберите четыре свои фишки подряд раньше соперника.'],
  battleship:['Морской бой','Найдите и потопите все корабли соперника раньше, чем он уничтожит ваши.'],
  checkers:['Шашки','Заберите все шашки соперника или заблокируйте их.'],
  reversi:['Реверси','Переворачивайте фишки соперника и соберите большинство.'],
  chess:['Шахматы','Поставьте королю соперника мат.'],
  go:['Го','Окружайте камни соперника и захватывайте территорию.'],
  domino:['Домино','Соединяйте одинаковые числа и первым избавьтесь от всех костяшек.'],
};

const cardCopy=read('app/assets/js/games/game-card-copy.js');
const unified=read('app/assets/js/games/unified-game-launcher.js');
const manifest=read('app/runtime/client/version-manifest.php');
const locale=JSON.parse(read('app/locales/ru.json'));
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(Number(locale?._meta?.version)>=56,'RU catalog version must remain active game meta revision 56 or newer');
for(const [id,file] of Object.entries(META)){
  const source=read(file);
  assert.equal(countCyrillicLines(source),0,id+' meta must remain free of hardcoded Cyrillic');
  assert.ok(source.includes(`titleKey: 'game_cards.meta.${id}.title'`),id+' title localization key missing');
  assert.ok(source.includes(`descriptionKey: 'game_cards.meta.${id}.description'`),id+' description localization key missing');
  assert.ok(!source.includes("title: '")&&!source.includes("description: '"),id+' meta must not restore literal display copy');
  assert.equal(locale.game_cards?.meta?.[id]?.title,expectedCopy[id][0],id+' localized title changed');
  assert.equal(locale.game_cards?.meta?.[id]?.description,expectedCopy[id][1],id+' localized description changed');
  assert.ok(!audit.includes(`'${file}'`),id+' active meta must not be classified as historical debt');
}

for(const [specifier] of [
  ['./tictactoe/meta.js?v=53&mvp27_1=localized-meta-v1'],
  ['./four-in-a-row/meta.js?v=53&mvp27_1=localized-meta-v1'],
  ['./battleship/meta.js?v=53&mvp27_1=localized-meta-v1'],
  ['./checkers/meta.js?v=58&mvp27_1=localized-meta-v1'],
  ['./reversi/meta.js?v=65&mvp27_1=localized-meta-v1'],
  ['./chess/meta.js?v=67&mvp27_1=localized-meta-v1'],
  ['./go/meta.js?v=70&mvp27_1=localized-meta-v1'],
  ['./domino/meta.js?v=72&mvp27_1=localized-meta-v1'],
]){
  assert.ok(cardCopy.includes(specifier),'game-card-copy cache identity missing: '+specifier);
}
assert.ok(cardCopy.includes("const titleText = t(meta.titleKey);"));
assert.ok(cardCopy.includes("const descriptionText = t(meta.descriptionKey);"));
assert.ok(cardCopy.includes("t('game_cards.rules_aria', { game:titleText })"));
assert.ok(!cardCopy.includes('meta.title;')&&!cardCopy.includes('meta.description;'),
  'game-card-copy must render canonical localized keys');

assert.ok(unified.includes("./go/meta.js?v=70&mvp27_1=localized-meta-v1"));
assert.ok(unified.includes("./domino/meta.js?v=72&mvp27_1=localized-meta-v1"));
assert.ok(handoff.includes("import { initGameCardCopy } from './games/game-card-copy.js"));
assert.ok(handoff.includes("import { initUnifiedGameLauncher } from './games/unified-game-launcher.js"));

assert.ok(manifest.includes("./assets/js/games/game-card-copy.js?v=84&sk=5&icons=c1efd5af&delivery=static&mvp27_1=active-shell-copy-v2&game_meta_i18n=v1"));
assert.ok(manifest.includes("./assets/js/games/unified-game-launcher.js?v=5&mvp16=setup-subtitle-width&mvp24=room-neutral-client-v2&mvp27_1=localized-game-meta-v1"));

assert.ok(Number(baseline.scanned_files)<=685);
assert.ok(Number(baseline.cyrillic_lines_total)<=1682);
assert.ok(Number(baseline.by_scope?.client)<=29);
assert.equal(Number(baseline.by_scope?.backend),1653);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 active game meta localization: OK — eight factual game-card title/description owners resolve through canonical RU catalog with zero hardcoded Cyrillic in meta sources.');
