import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const profileScreen = read('app/assets/js/screens/profile-screen-v110.js');
const apiClient = read('app/assets/js/api/client.js');
const profileCss = read('app/assets/css/screens/profile.css');
const mainCss = read('app/assets/css/main.css');
const locale = JSON.parse(read('app/locales/ru.json'));
const endpoint = read('bot/profile-v2.php');
const manifest = read('app/runtime/client/version-manifest.php');
const runtimeManifest = read('bot/helpers/staging-e2e-runtime-files.txt')
  .split(/\r?\n/u)
  .map(line => line.trim())
  .filter(line => line && !line.startsWith('#'));

const gameTypes = [
  'tictactoe',
  'four_in_a_row',
  'battleship',
  'checkers',
  'reversi',
  'chess',
  'go',
  'domino',
];

if (!apiClient.includes("profileV2: () => requestUrl(`${window.location.origin}/bot/profile-v2.php`)")) {
  throw new Error('API client must expose one dedicated Profile v2 read endpoint.');
}

for (const token of [
  "new MgwProfileService",
  "new HistoryService",
  "'by_game'",
  "provider_neutral' => true",
  "(string)($game['status'] ?? '') !== 'finished'",
]) {
  if (!endpoint.includes(token)) throw new Error(`Profile v2 endpoint contract missing: ${token}`);
}
for (const gameType of gameTypes) {
  if (!endpoint.includes(`'${gameType}'`)) throw new Error(`Profile v2 endpoint missing game stats bucket: ${gameType}`);
  if (!profileScreen.includes(`'${gameType}'`)) throw new Error(`Profile v2 screen missing game stats card: ${gameType}`);
}

for (const token of [
  "import { t, formatNumber, formatDate, formatDateTime } from '@mgw/i18n'",
  'api.profileV2()',
  'data-copy-mgw-id',
  'profile-v2-games-grid',
  'profile-v2-history',
  'profile-v2-achievements',
  'profile-v2-linked-list',
  "content.innerHTML = '<div class=\"profile-v2\" id=\"profileV2Root\"></div>'",
]) {
  if (!profileScreen.includes(token)) throw new Error(`Profile v2 screen contract missing: ${token}`);
}

for (const forbidden of [
  'shopOrders(',
  'profileOrders',
  'profile-orders-action',
  'profile-wallet-card',
  'balance_gold',
  'balance_match',
  'gold_shop',
  'Мои заявки',
]) {
  if (profileScreen.includes(forbidden)) throw new Error(`Legacy Match/Gold/order profile UI survived: ${forbidden}`);
}
if (/[А-Яа-яЁё]/u.test(profileScreen)) {
  throw new Error('Profile v2 product copy must live in localization files, not the screen owner.');
}
if (/rating|tournament awards|leaderboard/iu.test(profileScreen)) {
  throw new Error('Profile v2 must not surface rating/tournament award features early.');
}

for (const key of [
  'title','subtitle','mgw_id','copy_id','balance','stats_title','by_game_title',
  'history_title','achievements_title','account_title','linked_accounts','language',
]) {
  if (typeof locale.profile?.[key] !== 'string' || !locale.profile[key]) {
    throw new Error(`Missing localized profile key: ${key}`);
  }
}
if (Number(locale._meta?.version || 0) < 6) throw new Error('RU catalog content version must advance for Profile v2 copy.');

for (const token of [
  '.profile-v2-identity',
  '.profile-v2-balance',
  '.profile-v2-summary-grid',
  '.profile-v2-games-grid',
  '.profile-v2-history-row',
  '.profile-v2-achievements',
  '.profile-v2-account-card',
]) {
  if (!profileCss.includes(token)) throw new Error(`Profile v2 CSS missing: ${token}`);
}
for (const forbidden of ['profile-orders-action','profile-wallet-card.gold','profile-wallet-card.match']) {
  if (profileCss.includes(forbidden)) throw new Error(`Legacy profile CSS survived: ${forbidden}`);
}

if (!mainCss.includes("./screens/profile.css?v=126&sk=1&mvp16=profile-v2")) {
  throw new Error('Profile v2 nested CSS cache target is stale.');
}
if (!manifest.includes("client.js?v=1133&mvp16=profile-v2")) throw new Error('API client cache target is stale.');
if (!manifest.includes("profile-screen-v110.js?v=1115&mvp16=profile-v2")) throw new Error('Profile v2 screen cache target is stale.');
if (!manifest.includes("main.css?v=167&sk=3&icons=c1efd5af&render=36&mvp16=final-bottom-nav-align")) {
  throw new Error('Outer CSS cache target must advance with Profile v2.');
}
if (!manifest.includes("'version' => 'keys-v1'")) throw new Error('Localization schema contract must remain keys-v1.');
if (!runtimeManifest.includes('bot/profile-v2.php')) throw new Error('Profile v2 endpoint is missing from exact Hostinger fingerprint.');
for (const path of [
  'app/assets/js/screens/profile-screen-v110.js',
  'app/assets/js/api/client.js',
  'app/assets/css/screens/profile.css',
  'app/assets/css/main.css',
  'app/locales/ru.json',
  'app/runtime/client/version-manifest.php',
]) {
  if (!runtimeManifest.includes(path)) throw new Error(`Profile v2 runtime owner missing from exact fingerprint: ${path}`);
}

console.log('MVP16_5_PROFILE_V2_CONTRACT=PASS');
