import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const accountData = read('app/assets/js/screens/account-data-sheet-v1.js');
const shortcuts = read('app/assets/js/components/account-shortcuts.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));

assert(!/[\u0400-\u04FF]/.test(accountData), 'Active Account Data owner must contain zero hardcoded Cyrillic.');
assert(!accountData.includes('ru-RU'), 'Active Account Data owner must not own direct ru-RU formatting.');
assert(accountData.includes("from '@mgw/i18n'"), 'Active Account Data owner must use canonical i18n.');
assert(accountData.includes("ACTION_CREATE_EXPORT = 'create_export'") && accountData.includes("ACTION_DOWNLOAD_EXPORT = 'download_export'") && accountData.includes("ACTION_CANCEL_DELETE = 'cancel_delete'") && accountData.includes("ACTION_SCHEDULE_DELETE = 'schedule_delete'"), 'Sensitive Account Data action codes must remain stable.');
assert(accountData.includes('ANDROID_REAUTH_TIMEOUT_MS = 135000') && accountData.includes('mgw://android-reauth\\?challenge=ar_[a-f0-9]{24}') && accountData.includes("mgw:android-reauth-success") && accountData.includes("mgw:android-reauth-cancelled") && accountData.includes("mgw:android-reauth-failed"), 'Native Android reauth boundary must remain unchanged.');
assert(accountData.includes('mgw://android-account-download\\?request=adr_[a-f0-9]{32}') && accountData.includes("mgw:android-download-enqueued") && accountData.includes("mgw:android-download-failed") && accountData.includes('URL.createObjectURL(result.blob)'), 'Native Android download and browser fallback owners must remain unchanged.');
assert(accountData.includes("formatLocalizedDateTime(date, 'short'") && accountData.includes("formatLocalizedNumber(bytes / 1024"), 'Account Data dates and byte sizes must resolve through canonical locale formatting.');
assert(!/[\u0400-\u04FF]/.test(shortcuts), 'Active account shortcut owner must contain zero hardcoded Cyrillic.');
assert(shortcuts.includes("t('friends.menu.title')") && shortcuts.includes("t('account_data.menu.title')"), 'More menu social/account labels must resolve through canonical locale ownership.');
assert(shortcuts.includes('account-data-sheet-v1.js?v=8&mvp22_8=account-data-v1&mvp23=mobile-cold-first-open-v1&mvp25_2=human-copy-v1&mvp26_4=android-reauth-v1&mvp26_4_2=android-download-pending-v1&mvp27_1=localized-v1'), 'Active Account Data lazy import must publish the localized cache identity.');
assert(manifest.includes('mvp27_1=friends-localized-v1&mvp27_1=account-data-localized-v1'), 'Canonical manifest must publish the localized account-shortcuts owner.');
assert(ru._meta?.version === 33, 'RU locale revision must publish Account Data v33.');
assert(ru.account_data?.menu?.title === 'Данные и аккаунт', 'RU Account Data menu copy must preserve accepted wording.');
assert(ru.account_data?.export?.download === 'Скачать ZIP', 'RU export action must preserve accepted wording.');
assert(ru.account_data?.delete?.action === 'Удалить аккаунт', 'RU delete action must preserve accepted wording.');
assert(ru.account_data?.confirm?.schedule === 'Да, запланировать', 'RU destructive confirmation must preserve accepted wording.');
assert(ru.account_data?.confirm?.retention_note?.includes('операций с игровыми монетами'), 'Accepted human retention explanation must remain preserved.');

console.log('MVP27_1_ACCOUNT_DATA_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_ACCOUNT_DATA_HARDCODED_CYRILLIC=0');
