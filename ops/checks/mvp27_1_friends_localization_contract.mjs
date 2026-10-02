import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const friends = read('app/assets/js/screens/friends-screen-v110.js');
const shortcuts = read('app/assets/js/components/account-shortcuts.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));

assert(!/[\u0400-\u04FF]/.test(friends), 'Active Friends screen must contain zero hardcoded Cyrillic.');
assert(!friends.includes('ru-RU'), 'Active Friends screen must not own a direct ru-RU formatter.');
assert(friends.includes("from '@mgw/i18n'"), 'Active Friends screen must use canonical i18n.');
assert(friends.includes("const GAME_TYPES = Object.freeze([") && friends.includes("'four_in_a_row'") && friends.includes("'domino'"), 'Friends game statistics must keep stable game codes.');
assert(friends.includes("const REPORT_REASON_CODES = Object.freeze([") && friends.includes("t(\`home.report.reasons.\${value}\`)"), 'Friends report reasons must keep stable codes and resolve labels through canonical locale keys.');
assert(friends.includes("t(\`friends.profile.game_names.\${gameType}\`)"), 'Friends-specific accepted game labels must resolve through stable locale keys.');
assert(friends.includes('openSocialPlayerInvite') && !friends.includes('/bot/invites.php'), 'Friends must preserve the canonical invite handoff without a second endpoint owner.');
assert(friends.includes('const FRIENDS_REFRESH_MS = 5000') && friends.includes("action:'report'") && friends.includes("mutation === 'unblock'"), 'Friends social lifecycle owners must remain intact.');

const shortcutCyrillic = shortcuts.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line));
assert(shortcutCyrillic.length === 0, 'Active account shortcut owner must remain fully localized after the later Account Data successor slice.');
assert(shortcuts.includes("t('friends.menu.title')"), 'More-menu Friends entry must use canonical locale ownership.');
assert(shortcuts.includes("friends-screen-v110.js?v=6&mvp18=instant-route&optimistic-relations&mvp22_3=report-categories-v1&mvp27_1=localized-v1"), 'Active shortcut must cache-bust the localized Friends screen.');
assert(manifest.includes("mvp26_4_2=android-download-pending-v1&mvp27_1=friends-localized-v1"), 'Canonical manifest must publish the localized shortcut owner.');

assert(Number(ru._meta?.version || 0) >= 32, 'RU locale revision must retain Friends v32 or a newer successor.');
assert(ru.friends?.menu?.title === 'Друзья', 'RU Friends menu title must preserve accepted wording.');
assert(ru.friends?.tabs?.blocked === 'Блокировки', 'RU Friends tabs must preserve accepted wording.');
assert(ru.friends?.sections?.recent === 'Недавние соперники', 'RU Friends sections must preserve accepted wording.');
assert(ru.friends?.profile?.game_names?.four_in_a_row === '4 в ряд', 'Friends-specific Four in a Row short name must be preserved.');
assert(ru.friends?.profile?.game_names?.checkers === 'Шашки', 'Friends-specific Checkers short name must be preserved.');
assert(ru.home?.report?.reasons?.nickname === 'Недопустимый никнейм', 'Canonical moderation reason locale owner must remain available.');

console.log('MVP27_1_FRIENDS_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_FRIENDS_HARDCODED_CYRILLIC=0');
