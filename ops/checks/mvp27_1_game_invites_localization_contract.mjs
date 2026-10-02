import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const ownerPath = 'app/assets/js/games/game-invites-v110.js';
const wrapperPath = 'app/assets/js/games/game-invites-v110-rematch-policy-v175.js';
const owner = fs.readFileSync(ownerPath, 'utf8');
const wrapper = fs.readFileSync(wrapperPath, 'utf8');
const shell = fs.readFileSync('app/assets/js/main-v110-handoff-shell.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));

for (const [path, source] of [[ownerPath, owner], [wrapperPath, wrapper]]) {
  assert(!/[\u0400-\u04FF]/.test(source), `Active invite owner must contain zero hardcoded Cyrillic: ${path}`);
  assert(!source.includes('ru-RU'), `Active invite owner must not own direct ru-RU formatting: ${path}`);
  assert(source.includes('@mgw/i18n'), `Active invite owner must use canonical i18n: ${path}`);
}

assert(owner.includes("import { t, getI18n } from '@mgw/i18n';"),
  'Invite owner must use canonical text and locale ownership.');
assert(owner.includes("const inviteText = (key, params = {}) => t(\`game_invites.\${key}\`, params);"),
  'Invite owner must resolve player-facing copy through game_invites namespace.');
assert(owner.includes("titleKey:'game_titles.tictactoe'") && !owner.includes("title:'"),
  'Invite game configuration must use stable title keys instead of visible localized titles.');
assert(owner.includes("new Intl.DateTimeFormat(getI18n().locale"),
  'Invite time display must follow the active locale instead of a hardcoded formatter.');
assert(wrapper.includes("t('game_invites.rematch.play_again')"),
  'Rematch wrapper must localize the play-again label.');

assert(shell.includes("import { initGameInvites } from './games/game-invites-v110.js?v=1137&ux=1';"),
  'Active shell must retain the canonical invite import key.');
assert(manifest.includes("'./assets/js/games/game-invites-v110.js?v=1137&ux=1' => './assets/js/games/game-invites-v110-rematch-policy-v175.js?v=3&fp=2&mvp21_6=tournament-exclusion-v1&mvp27_1=localized-v1'"),
  'Canonical manifest must publish the localized rematch wrapper.');
for (const key of [
  'v=1142&zone=unified&rematch=optimistic&terminal=self-silent',
  'v=1143&zone=unified&rematch=optimistic&terminal=self-silent&social=1',
  'v=1144&zone=unified&rematch=optimistic&terminal=self-silent&social=1&share=telegram-native',
]) {
  assert(manifest.includes(`game-invites-v110.js?${key}' => './assets/js/games/game-invites-v110.js?v=1150&`),
    `Canonical manifest must converge accepted invite alias on localized v1150: ${key}`);
}
assert(manifest.includes('mvp27_1=localized-v1'),
  'Canonical invite graph must expose localization cache identity.');

assert(ru._meta?.version === 29, 'RU locale revision must publish invite catalog v29.');
for (const key of [
  'game_titles','social','rematch','direct','incoming','summary','owner_wait',
  'ready','accepted','terminal','loading','status','network','board'
]) {
  assert(ru.game_invites?.[key] && typeof ru.game_invites[key] === 'object',
    `Invite locale namespace missing: ${key}`);
}
assert(ru.game_invites?.rematch?.play_again === 'Сыграть ещё',
  'Accepted rematch label must be preserved in canonical RU locale.');
assert(ru.game_invites?.social?.debit_note === 'Коины спишутся только после запуска матча.',
  'Accepted invite economy note must be preserved in canonical RU locale.');

console.log('MVP27_1_GAME_INVITES_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_GAME_INVITES_HARDCODED_CYRILLIC=0');
