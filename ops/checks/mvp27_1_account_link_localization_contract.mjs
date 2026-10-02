import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const ownerPath = 'app/assets/js/profile/mgw-account-link-ui.js';
const owner = fs.readFileSync(ownerPath, 'utf8');
const profile = fs.readFileSync('app/assets/js/screens/profile-screen-v110.js', 'utf8');
const shell = fs.readFileSync('app/assets/js/main-v110-handoff-shell.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const debt = JSON.parse(fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_baseline.json', 'utf8'));

assert(!/[\u0400-\u04FF]/.test(owner), 'Active account-link UI owner must contain zero hardcoded Cyrillic.');
assert(owner.includes("import { t } from '@mgw/i18n';"), 'Account-link UI must use canonical i18n.');
assert(owner.includes("const accountLinkText = (key, params = {}) => t(`account_link.${key}`, params);"), 'Account-link UI must resolve copy through account_link namespace.');

for (const token of [
  'api.accountLinkCreate()',
  'api.accountLinkStatus(pending.challenge_id)',
  'api.accountLinkFinalize(challengeId)',
  "for (const delay of [0, 180, 360, 720])",
  "for (const delay of [0, 220, 420, 780, 1200])",
  'globalThis.location.assign(candidate)',
  'globalThis.location.reload()',
  'localStorage.setItem(STORAGE_KEY',
  "url.protocol === 'https:' && url.hostname === 't.me'",
]) assert(owner.includes(token), 'Account-link ownership token missing: ' + token);
assert(!owner.includes('telegram_url:telegramUrl') && !owner.includes('LedgerWriteService') && !owner.includes('mgw_coin'), 'Account-link UI must not persist Telegram URLs or take economy ownership.');

assert(shell.includes("import { settlePendingAccountLinkBeforeBoot } from './profile/mgw-account-link-ui.js?v=3';"), 'Main preboot must preserve the accepted v3 account-link import identity.');
assert(profile.includes("from '../profile/mgw-account-link-ui.js?v=3';"), 'Profile must preserve the same accepted v3 account-link import identity.');
assert(manifest.includes("'./assets/js/profile/mgw-account-link-ui.js?v=3' => './assets/js/profile/mgw-account-link-ui.js?v=4&mvp27_1=localized-v1'"), 'Canonical manifest must publish one localized account-link alias.');

assert(ru._meta?.version === 36, 'RU locale revision must publish account-link catalog v36.');
assert(ru.account_link?.profile?.title === 'Привязать Telegram-аккаунт', 'Accepted Profile link title must remain unchanged.');
assert(ru.account_link?.loading?.checking_telegram === 'Проверяем подтверждение в Telegram…', 'Accepted confirmation progress copy must remain unchanged.');
assert(ru.account_link?.intro?.not_moved_note === 'Временные 1000 стартовых коинов нового Android-профиля не добавляются к вашему балансу.', 'Accepted starter-coin warning must remain unchanged.');
assert(ru.account_link?.pending?.confirmed === 'Я подтвердил в Telegram', 'Accepted confirmation action must remain unchanged.');
assert(ru.account_link?.success === 'Telegram-аккаунт привязан. Загружаем ваш профиль…', 'Accepted success copy must remain unchanged.');

assert(debt.cyrillic_lines_total === 3531 && debt.by_scope?.client === 1850, 'Localization debt baseline must ratchet by exactly 39 client lines.');

console.log('MVP27_1_ACCOUNT_LINK_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_ACCOUNT_LINK_HARDCODED_CYRILLIC=0');
