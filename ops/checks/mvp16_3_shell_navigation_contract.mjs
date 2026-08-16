import fs from 'node:fs';
import crypto from 'node:crypto';

const read = path => fs.readFileSync(path, 'utf8');
const router = read('app/assets/js/router.js');
const shell = read('app/assets/js/main-v110-handoff-shell.js');
const store = read('app/assets/js/screens/store-screen.js');
const profile = read('app/assets/js/screens/profile-screen-v110.js');
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

if (!shell.includes("openStoreTab") || shell.includes("openStoreSheet()")) throw new Error('Primary Store navigation must render inside its tab, not open a sheet.');
if (!store.includes("export async function openStoreTab()") || !store.includes("document.getElementById('storeTabSurface')")) {
  throw new Error('Store must own an embedded primary-tab surface.');
}
if (!profile.includes("document.querySelector('#screen-profile [data-back-home]')?.remove()")) {
  throw new Error('Profile primary tab must remove its redundant close action.');
}
if (!shell.includes("className = 'screen app-shell-primary-screen'")) throw new Error('Tournament/Store shell routes must be full primary screens.');

for (const token of ['env(safe-area-inset-top)','env(safe-area-inset-bottom)',':focus-visible','.app-bottom-nav-item:disabled']) {
  if (!layout.includes(token)) throw new Error(`Missing safe-area/accessibility contract: ${token}`);
}
if (!layout.includes('padding-top:calc(68px + max(10px,env(safe-area-inset-top)))')) throw new Error('Shell content top spacing must stay at the accepted tightened value.');
if (!layout.includes('min-height:52px')
    || !layout.includes('padding:5px 3px')
    || !layout.includes('justify-content:flex-start')
    || !layout.includes('padding-bottom:calc(72px + env(safe-area-inset-bottom))')) {
  throw new Error('Bottom navigation must expose real symmetric vertical breathing room without altering shell ownership.');
}
if (!layout.includes('.app-bottom-nav-icon{width:34px;height:32px')
    || !layout.includes('.app-bottom-nav-icon .shield-king-metal-icon{width:32px;height:32px')
    || !layout.includes('font-size:10px')) {
  throw new Error('Optically balanced navigation must preserve the accepted 32px icon and 10px label sizes.');
}
if (!/\.\/base\/layout\.css\?v=\d+&sk=1&mvp16=(?:unified-primary-tabs|balanced-bottom-nav|optical-bottom-nav)/u.test(mainCss)) {
  throw new Error('Layout cache target must stay versioned under the MVP-16 shell owner.');
}
if (!mainCss.includes("./screens/store.css?v=29&mvp16=primary-tab")) throw new Error('Store CSS cache target must advance with embedded store changes.');

for (const key of ['home','tournaments','store','profile']) {
  if (typeof locale.nav?.[key] !== 'string' || !locale.nav[key]) throw new Error(`Missing localized nav key: ${key}`);
}
if (!locale.shell?.navigation_label || !locale.shell?.store_open) throw new Error('Missing localized shell copy.');

const routerHash = crypto.createHash('sha256').update(router).digest('hex').slice(0, 12);
if (!manifest.includes(`router.js?v=29&b=${routerHash}&mvp16=route-registry`)) throw new Error('Router cache target must be content-bound to current bytes.');
if (!/main-v110-handoff-shell\.js\?v=\d+&mvp16=(?:unified-primary-tabs|unified-game-setup)/u.test(manifest)) {
  throw new Error('Current shell cache target must remain versioned under the MVP-16 shell owner.');
}
if (!manifest.includes("store-screen.js?v=36&mvp16=primary-tab")) throw new Error('Store module cache target was not advanced.');
if (!manifest.includes("profile-screen-v110.js?v=1114&mvp16=primary-tab")) throw new Error('Profile module cache target was not advanced.');
if (!/main\.css\?v=\d+&sk=3&icons=c1efd5af&render=\d+&mvp16=(?:unified-primary-tabs|setup-ui-polish|setup-subtitle-width|variant-rules-nav-balance|optical-bottom-nav)/u.test(manifest)) {
  throw new Error('Shell CSS must remain on a versioned MVP-16 cache target.');
}

console.log('MVP16_3_SHELL_NAVIGATION_CONTRACT=PASS');