import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const reconnect = read('app/assets/js/main-v110-reconnect-v174.js');
const main = read('app/assets/js/main-v110.js');
const shell = read('app/assets/js/main-v110-handoff-shell.js');
const profile = read('app/assets/js/screens/profile-screen-v110.js');
const target = read('app/assets/js/profile/mgw-account-link-onboarding.js');
const manifest = read('app/runtime/client/version-manifest.php');
const locale = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on factual v110');
assert.ok(manifest.includes("'@mgw/main' => './assets/js/main-v110-reconnect-v174.js?v=2'"),
  'Canonical @mgw/main must remain on the accepted v110 reconnect owner');
assert.ok(reconnect.includes("import './main-v110.js?v=1139&ux=1&sk=3&icons=c1efd5af&render=5&mvp15=unified-balance';"),
  'Reconnect owner must still delegate to factual main-v110');
assert.ok(main.includes("import './main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5';"),
  'Factual main-v110 must still delegate to the accepted handoff shell');
assert.ok(shell.includes("import { initProfileScreen } from './screens/profile-screen-v110.js?v=1109';"),
  'Factual v110 shell must keep the accepted Profile owner specifier');
assert.ok(manifest.includes("'./assets/js/screens/profile-screen-v110.js?v=1109' => './assets/js/screens/profile-screen-v110.js?v=1142"),
  'Accepted Profile specifier must still resolve to the current canonical v110 owner');
assert.ok(profile.includes("import { initAccountLinkHomeOnboarding } from '../profile/mgw-account-link-onboarding.js?v=5';"),
  'Profile must keep the accepted Account Link Home onboarding specifier');
assert.ok(profile.includes('initAccountLinkHomeOnboarding();'),
  'Profile must still initialize Account Link Home onboarding');
assert.ok(manifest.includes("'./assets/js/profile/mgw-account-link-onboarding.js?v=5' => './assets/js/profile/mgw-account-link-onboarding.js?v=6&mvp27_1=localized-v1'"),
  'Canonical manifest must publish the localized onboarding cache identity');

assert.ok(target.includes("import { t } from '@mgw/i18n';"),
  'Active Account Link Home onboarding must consume canonical @mgw/i18n');
assert.ok(target.includes("const accountLinkOnboardingText = (key, params = {}) => t(\`account_link.onboarding.\${key}\`, params);"),
  'Onboarding copy must resolve through the account_link.onboarding namespace');
assert.equal(countCyrillicLines(target), 0,
  'Active Account Link Home onboarding must contain zero hardcoded Cyrillic after localization');

for (const token of [
  "currentScreen() !== 'home'",
  "preloader.classList.contains('hidden')",
  "overlay.classList.contains('active')",
  "provider !== 'android_device'",
  "=== 'telegram'",
  'data-account-link-onboarding-connect',
  'data-account-link-onboarding-later',
  'localStorage.setItem(DISMISS_KEY',
  'mgw_id:mgwId',
  'PENDING_LINK_KEY',
  'openAccountLinkSheet()',
  'mgw-account-link-onboarding-overlay',
  'mgw-account-link-onboarding-sheet',
  './assets/icons/shield-king/mgw-mark.svg',
]) assert.ok(target.includes(token), 'Account Link Home onboarding behavior invariant changed: ' + token);

for (const key of [
  'close_aria',
  'eyebrow',
  'title',
  'note',
  'bridge_aria',
  'balance_purchases',
  'stats_rating',
  'friends_progress',
  'starter_coin_note',
  'connect',
  'later',
]) assert.ok(target.includes(`accountLinkOnboardingText('${key}')`),
  'Localized onboarding owner must use locale key: ' + key);

assert.ok(Number(locale?._meta?.version) >= 54,
  'RU locale revision must include Account Link Home onboarding localization');
assert.deepEqual(locale?.account_link?.onboarding, {
  close_aria:'Закрыть',
  eyebrow:'ANDROID · ЕДИНЫЙ ПРОФИЛЬ',
  title:'Уже играете в MINI GAMES WORLD в Telegram?',
  note:'Подключите Telegram и продолжайте в Android с тем же игровым профилем.',
  bridge_aria:'Один профиль в Telegram и Android',
  balance_purchases:'Баланс и покупки',
  stats_rating:'Статистика и рейтинг',
  friends_progress:'Друзья и прогресс',
  starter_coin_note:'После привязки Android использует ваш существующий Telegram-профиль. Временные стартовые коины Android к нему не добавляются.',
  connect:'Привязать Telegram',
  later:'Позже',
}, 'RU Account Link Home onboarding copy must preserve exact accepted visible wording');

assert.ok(Number(baseline.scanned_files) <= 711, 'Successor must not restore classified runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1817, 'Total localization debt must not exceed the accepted post-onboarding ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 164, 'Client localization debt must not exceed the accepted post-onboarding ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 Account Link Home onboarding localization: OK — 11 factual v110 Cyrillic lines moved to canonical locale ownership with onboarding behavior preserved.');
