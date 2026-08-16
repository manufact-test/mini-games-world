import fs from 'node:fs';

const css = fs.readFileSync('app/assets/css/main.css', 'utf8');
const launcher = fs.readFileSync('app/assets/js/games/unified-game-launcher.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

if (!launcher.includes('class="sheet-head unified-setup-head"')) {
  throw new Error('Unified setup header must expose its own layout scope.');
}
if (!css.includes('.unified-setup-head{\n  position:relative;\n  display:block;')) {
  throw new Error('Unified setup header must stop reserving a flex column for the close control.');
}
if (!css.includes('.unified-setup-head > div:first-child > h2{\n  padding-right:52px;')) {
  throw new Error('Setup title must reserve space for the close control.');
}
if (!css.includes('.unified-setup-head > div:first-child > p{\n  width:100%;\n  max-width:none;')) {
  throw new Error('Setup subtitle must use the full sheet width.');
}
if (!css.includes('.unified-setup-head > .close{\n  position:absolute !important;\n  top:0;\n  right:0;')) {
  throw new Error('Setup close control must not consume subtitle width.');
}
if (!css.includes('.unified-game-setup > .btn-row{\n  margin-top:10px !important;')) {
  throw new Error('Unified setup actions must remain visually separated from the entry-cost choice.');
}
if (!manifest.includes("main.css?v=160&sk=3&icons=c1efd5af&render=30&mvp16=setup-subtitle-width")) {
  throw new Error('Telegram /start must receive a fresh CSS cache target for subtitle-width correction.');
}
if (!manifest.includes("unified-game-launcher.js?v=3&mvp16=setup-subtitle-width")) {
  throw new Error('Telegram /start must receive fresh launcher markup for subtitle-width correction.');
}
if (!manifest.includes("'version' => 'v2-route-scoped-polling'")) {
  throw new Error('Client manifest schema must remain unchanged.');
}
if (!manifest.includes("'version' => 'keys-v1'")) {
  throw new Error('Localization contract must remain unchanged.');
}

console.log('MVP16_4_SETUP_UI_POLISH_CONTRACT=PASS');