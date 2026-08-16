import fs from 'node:fs';

const css = fs.readFileSync('app/assets/css/main.css', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

if (!css.includes('.sheet-head > div:first-child{\n  flex:1 1 auto;\n  min-width:0;')) {
  throw new Error('Sheet header copy must own the remaining horizontal width.');
}
if (!css.includes('.sheet-head > div:first-child > p{\n  width:100%;\n  max-width:none;')) {
  throw new Error('Sheet subtitle must be allowed to use the full header-copy width.');
}
if (!css.includes('.unified-game-setup > .btn-row{\n  margin-top:10px !important;')) {
  throw new Error('Unified setup actions must be visually separated from the entry-cost choice.');
}
if (!manifest.includes("main.css?v=159&sk=3&icons=c1efd5af&render=30&mvp16=setup-ui-polish")) {
  throw new Error('Telegram /start must receive a fresh CSS cache target for the setup polish.');
}
if (!manifest.includes("'version' => 'v2-route-scoped-polling'")) {
  throw new Error('Client manifest schema must remain unchanged.');
}
if (!manifest.includes("'version' => 'keys-v1'")) {
  throw new Error('Localization contract must remain unchanged.');
}

console.log('MVP16_4_SETUP_UI_POLISH_CONTRACT=PASS');
