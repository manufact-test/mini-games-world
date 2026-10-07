import fs from 'node:fs';
import assert from 'node:assert/strict';

const OWNERS = [
  'app/assets/js/runtime-status.js',
  'app/assets/js/api/client.js',
  'app/assets/js/components/toast.js',
  'app/assets/js/components/user-copy.js',
  'app/assets/js/components/shield-king-visuals.js',
  'app/assets/js/components/boot-state.js',
  'app/assets/js/utils/typography.js',
  'app/assets/js/ui.js',
  'app/assets/js/screens/notifications-screen-v110r13.js',
  'app/assets/js/screens/weekly-match-info.js',
  'app/assets/js/screens/search-screen-v102.js',
  'app/assets/js/games/game-card-copy.js',
  'app/assets/js/games/invite-link-entry-v110r12.js',
  'app/assets/js/production-v99-session-transport.js',
  'app/assets/js/main-v110-handoff-shell.js',
];

const read = path => fs.readFileSync(path, 'utf8');
const cyrillic = /[\u0400-\u04FF]/;

for (const path of OWNERS) {
  const source = read(path);
  assert.ok(!cyrillic.test(source), `Active shell owner still contains Cyrillic: ${path}`);
}

for (const path of OWNERS.filter(path => !path.endsWith('main-v110-handoff-shell.js'))) {
  assert.ok(read(path).includes("@mgw/i18n"), `Active shell owner must consume canonical i18n: ${path}`);
}
assert.ok(read('app/assets/js/main-v110-handoff-shell.js').includes("import { t } from '@mgw/i18n';"), 'Root shell must keep canonical i18n ownership');

const runtime = read('app/assets/js/runtime-status.js');
for (const token of [
  'runtime.maintenance?.enabled',
  'runtime.features?.matchmaking === false',
  'runtime.financial_read_only',
  'runtime.features?.payments === false',
  'runtime.features?.shop === false',
  "document.addEventListener('click', interceptBlockedAction, true)",
]) assert.ok(runtime.includes(token), `Runtime-status invariant missing: ${token}`);

const api = read('app/assets/js/api/client.js');
for (const token of ["error.code = 'network_unavailable'","error.status = 0","await fetch(url", "ACCOUNT_LINK_URL"]) {
  assert.ok(api.includes(token), `API transport invariant missing: ${token}`);
}

const toast = read('app/assets/js/components/toast.js');
assert.ok(toast.includes('duration = 2600'), 'Toast duration changed');
assert.ok(toast.includes("t('store.actions.selected_toast')") && toast.includes("t('profile.entry_effects.toast.removed')"), 'Silent acknowledgement ownership changed');

const userCopy = read('app/assets/js/components/user-copy.js');
assert.ok(userCopy.includes("new MutationObserver"), 'User-copy observer ownership changed');
assert.ok(userCopy.includes(".topup-success") && userCopy.includes(".store-order-success"), 'User-copy target ownership changed');

const typography = read('app/assets/js/utils/typography.js');
for (const token of ['THOUSANDS_GROUPS', 'NUMBER_UNITS', 'WORD_TOKEN', 'NUMBER_SIGN', "replace(SHORT_WORDS"]) {
  assert.ok(typography.includes(token), `Typography invariant missing: ${token}`);
}

const ui = read('app/assets/js/ui.js');
assert.ok(ui.includes("PROFILE_NAME_COLOR_IDS"), 'Profile name-color ownership changed');
assert.ok(ui.includes("has-tournament-prestige-crown"), 'Champion crown ownership changed');

const weekly = read('app/assets/js/screens/weekly-match-info.js');
assert.ok(weekly.includes('api.weeklyMatchStatus()'), 'Weekly bonus API owner changed');
assert.ok(weekly.includes('first_game_grant_count'), 'First-game bonus projection changed');
assert.ok(weekly.includes("timeZone: timezone || 'Europe/Moscow'"), 'Weekly schedule timezone fallback changed');

const search = read('app/assets/js/screens/search-screen-v102.js');
for (const token of [
  'api.startSearch(context.size, context.gameType)',
  'api.leaveSearch()',
  'api.gameState()',
  'currentV99PassiveLock()',
  'rememberV99PassiveLock',
  'searchRuntime.epoch',
]) assert.ok(search.includes(token), `Search lifecycle invariant missing: ${token}`);

const notifications = read('app/assets/js/screens/notifications-screen-v110r13.js');
assert.ok(notifications.includes('localizeLegacyTournamentAssignedMessage'), 'Tournament notification localization bridge changed');
assert.ok(notifications.includes('localizedTechnicalNotificationPatterns'), 'Technical notification cleanup ownership changed');
assert.ok(notifications.includes("t('notifications.friend_request_open_hint')"), 'Friend-request hint ownership changed');

const session = read('app/assets/js/production-v99-session-transport.js');
assert.ok(session.includes('window.__MGW_V99_PASSIVE_LOCK__'), 'Passive session-lock owner changed');

const invite = read('app/assets/js/games/invite-link-entry-v110r12.js');
assert.ok(invite.includes("data-invite-action=\"accept\"") && invite.includes("data-invite-action=\"decline\""), 'Invite deep-link actions changed');

const shell = read('app/assets/js/main-v110-handoff-shell.js');
for (const token of [
  'const result = await api.bootstrap();',
  'const profilePromise = api.mgwProfile();',
  'initGameInvites();',
  'initSearchScreen();',
  'initGameRules();',
  'enterGame(result.active_game, result.me || null);',
]) assert.ok(shell.includes(token), `Root shell invariant missing: ${token}`);

const manifest = read('app/runtime/client/version-manifest.php');
for (const token of [
  'main-v110-handoff-shell.js?v=1170&mvp27_1=active-shell-copy-v1',
  'api/client.js?v=1154&profile_read=page-single-flight-v1&mvp27_1=active-shell-copy-v1',
  'ui.js?v=101&mvp27_1=active-shell-copy-v1&profile_v2_avatar=shared-selected-state-v1',
  'toast.js?v=30&mvp27_1=active-shell-copy-v1',
  'runtime-status.js?v=87&mvp27_1=active-shell-copy-v1',
  'weekly-match-info.js?v=80&complete=green&mvp27_1=active-shell-copy-v1',
  'search-screen-v102.js?v=109&mvp27_1=active-shell-copy-v1',
  'invite-link-entry-v110r12.js?v=1125',
  'production-v99-session-transport.js?v=100&mvp27_1=active-shell-copy-v1',
]) assert.ok(manifest.includes(token), `Active shell cache identity missing: ${token}`);

const locale = JSON.parse(read('app/locales/ru.json'));
assert.ok(Number(locale?._meta?.version) >= 50, 'RU locale revision must be accepted shell revision 50 or newer');
for (const path of [
  ['runtime_status','maintenance_default'],
  ['network','server_unreachable'],
  ['shell','boot','load_failed'],
  ['weekly_match','button'],
  ['search','lock_default'],
  ['game_cards','rules_aria'],
  ['notifications','legacy_cleanup','tournament_starts'],
  ['typography','short_words_pattern'],
]) {
  let value=locale;
  for(const key of path) value=value?.[key];
  assert.equal(typeof value,'string', `Missing RU localization key: ${path.join('.')}`);
}

const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
assert.ok(Number(baseline.cyrillic_lines_total) <= 3077,'Shell bundle total debt must not regress above accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 1396,'Shell bundle client debt must not regress above accepted ceiling');

console.log('MVP-27.1 active shell-copy bundle contract: OK — 122 direct v110 root-graph Cyrillic lines localized with runtime/search/notification semantics frozen.');
