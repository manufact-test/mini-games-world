import fs from 'node:fs';
import crypto from 'node:crypto';

const read = path => fs.readFileSync(path, 'utf8');
const router = read('app/assets/js/router.js');
const shell = read('app/assets/js/main-v110-handoff-shell.js');
const layout = read('app/assets/css/base/layout.css');
const mainCss = read('app/assets/css/main.css');
const locale = JSON.parse(read('app/locales/ru.json'));
const manifest = read('app/runtime/client/version-manifest.php');
const index = read('app/index.html');

for (const route of ['home','tournaments','store','profile','search','game']) {
  if (!router.includes(`${route}:Object.freeze`)) throw new Error(`Missing router route: ${route}`);
}

for (const route of ['home','tournaments','store','profile']) {
  if (!shell.includes(`['${route}', 'nav.${route}']`)) throw new Error(`Missing bottom-nav route: ${route}`);
}

if (/spectator|Смотреть/iu.test(shell)) throw new Error('MVP-16.3 must not add a spectator/watch tab.');
if (!shell.includes("activeMatchLocksShell()") || !shell.includes("button.toggleAttribute('disabled', locked)")) {
  throw new Error('Shell navigation must be disabled while an active match owns the runtime.');
}
if (!shell.includes("showScreen('game')")) throw new Error('Locked shell navigation must restore the game route.');
if (!shell.includes("import { t } from '@mgw/i18n'")) throw new Error('New shell copy must use localization keys.');
if (!index.includes('id="moreMenuOpen"')) throw new Error('Accepted More menu trigger must remain in source topbar.');
if (shell.includes("existingTopbar.querySelector('#moreMenuOpen')?.remove()")) throw new Error('Shell must not remove the accepted More menu trigger.');
if (!shell.includes("id=\"topbarBalanceUnified\"")) throw new Error('Topbar must expose unified balance.');
if (!shell.includes("id = 'appBottomNav'")) throw new Error('Single bottom-nav owner is missing.');

if ((index.match(/id="screen-game"/g) || []).length !== 1) throw new Error('Exactly one game screen must remain in source HTML.');
if (shell.includes("id = 'screen-game'") || shell.includes('id="screen-game"')) throw new Error('Shell must never create a second game screen.');

for (const token of ['env(safe-area-inset-top)','env(safe-area-inset-bottom)',':focus-visible','.app-bottom-nav-item:disabled']) {
  if (!layout.includes(token)) throw new Error(`Missing safe-area/accessibility contract: ${token}`);
}
if (!layout.includes('padding-top:calc(68px + max(10px,env(safe-area-inset-top)))')) throw new Error('Shell content top spacing must stay at the accepted tightened value.');
if (!mainCss.includes("./base/layout.css?v=126&sk=1&mvp16=shell-nav-more-menu")) throw new Error('Layout cache target must advance with shell spacing changes.');

for (const key of ['home','tournaments','store','profile']) {
  if (typeof locale.nav?.[key] !== 'string' || !locale.nav[key]) throw new Error(`Missing localized nav key: ${key}`);
}
if (!locale.shell?.navigation_label || !locale.shell?.store_open) throw new Error('Missing localized shell copy.');

const routerHash = crypto.createHash('sha256').update(router).digest('hex').slice(0, 12);
if (!manifest.includes(`router.js?v=29&b=${routerHash}&mvp16=route-registry`)) throw new Error('Router cache target must be content-bound to current bytes.');
if (!manifest.includes("main-v110-handoff-shell.js?v=1149&mvp16=shell-nav-topbar-more-menu")) throw new Error('Shell cache target was not advanced.');
if (!manifest.includes("main.css?v=157") || !manifest.includes('mvp16=shell-nav-topbar-more-menu')) throw new Error('Shell CSS cache target was not advanced.');

console.log('MVP16_3_SHELL_NAVIGATION_CONTRACT=PASS');