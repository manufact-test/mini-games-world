import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const sheet = read('app/assets/js/components/sheet.js');
const rulesOwner = read('app/assets/js/games/game-rules.js');
const launcher = read('app/assets/js/games/unified-game-launcher.js');
const clientManifest = read('app/runtime/client/version-manifest.php');
const runtimeManifest = read('bot/helpers/staging-e2e-runtime-files.txt')
  .split(/\r?\n/u)
  .map(line => line.trim())
  .filter(line => line && !line.startsWith('#'));

if (!sheet.includes('const sheetHistory = [];')) {
  throw new Error('Shared sheet owner must keep an explicit nested-sheet history.');
}
if (!sheet.includes('options?.returnToPrevious === true')) {
  throw new Error('Nested return must be opt-in so normal sheets keep accepted close semantics.');
}
if (!sheet.includes('document.createDocumentFragment()') || !sheet.includes('previous.appendChild(s.firstChild)')) {
  throw new Error('Parent setup DOM must be detached, not serialized, so selected state and listeners survive.');
}
if (!sheet.includes('if (sheetHistory.length) {') || !sheet.includes('const previous = sheetHistory.pop();') || !sheet.includes('s.replaceChildren(previous);')) {
  throw new Error('Closing nested rules must restore the previous sheet instead of exposing Home.');
}
const restoreIndex = sheet.indexOf('if (sheetHistory.length) {');
const hideIndex = sheet.indexOf("o.classList.remove('active');");
if (restoreIndex < 0 || hideIndex < 0 || restoreIndex > hideIndex) {
  throw new Error('Nested restore must happen before the overlay can be closed.');
}
if (!sheet.includes('sheetHistory.length = 0;\n      if (s.childNodes.length) s.replaceChildren();')) {
  throw new Error('External sheet closure must clear stale nested history.');
}

if (!rulesOwner.includes("const returnToPrevious = Boolean(button.closest('#sheet'));")) {
  throw new Error('Rules opened from setup must request nested return automatically.');
}
if (!rulesOwner.includes('returnToPrevious:context.returnToPrevious === true')) {
  throw new Error('Shared rules owner must pass nested return intent into the sheet owner.');
}
if (!launcher.includes('data-game-rules-variant="${Number(activeSize)}"')) {
  throw new Error('Unified setup must still bind Rules to the currently selected variant.');
}

const sheetTarget = './assets/js/components/sheet.js?v=1110&mvp16=nested-return';
for (const source of [
  "'./assets/js/components/sheet.js?v=68'",
  "'./assets/js/components/sheet.js?v=1109'",
]) {
  if (!clientManifest.includes(`${source} => '${sheetTarget}'`)) {
    throw new Error(`Active sheet alias must resolve to one fresh singleton target: ${source}`);
  }
}
if (!/game-rules\.js\?v=\d+&mvp16=(?:rules-return-to-setup|all-variant-rules)/u.test(clientManifest)) {
  throw new Error('Rules owner must stay on fresh Telegram cache bytes while preserving return-to-setup behavior.');
}
if (!runtimeManifest.includes('app/assets/js/components/sheet.js')) {
  throw new Error('Shared sheet owner must be covered by exact Hostinger fingerprint.');
}
if (runtimeManifest.filter(path => path === 'app/assets/js/components/sheet.js').length !== 1) {
  throw new Error('Shared sheet owner must appear exactly once in runtime fingerprint manifest.');
}

console.log('MVP16_4_RULES_RETURN_TO_SETUP_CONTRACT=PASS');