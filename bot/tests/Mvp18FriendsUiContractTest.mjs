import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const entry = read('app/v110.php');
const manifest = read('app/runtime/client/version-manifest.php');
const socialMain = read('app/assets/js/main-v110-social-v182.js');
const friends = read('app/assets/js/screens/friends-screen-v110.js');
const router = read('app/assets/js/router.js');
const api = read('app/assets/js/api/client.js');
const accountShortcuts = read('app/assets/js/components/account-shortcuts.js');
const inviteWrapper = read('app/assets/js/games/game-invites-v110-rematch-policy-v175.js');
const inviteOwner = read('app/assets/js/games/game-invites-v110.js');
const endpoint = read('bot/friends.php');

assert(launch.includes("private const ENTRY_PATH = '/app/v110.php?v=1127';"), 'Telegram launch owner must remain v110.php?v=1127');
assert(entry.includes("app/runtime/client/version-manifest.php") || entry.includes("runtime/client/version-manifest.php"), 'v110 entry must still render version manifest');
assert(manifest.includes("'@mgw/main' => './assets/js/main-v110-social-v182.js?v=1&mvp18=friends-ui'"), 'Manifest must activate social wrapper');
assert(socialMain.includes("import './main-v110-reconnect-v174.js?v=2';"), 'Social wrapper must preserve accepted reconnect/main graph');
assert(socialMain.includes("friends-screen-v110.js?v=1&mvp18=friends-ui"), 'Social wrapper must load Friends screen');
assert(manifest.includes("game-invites-v110-rematch-policy-v175.js?v=1&fp=3&social=1"), 'Accepted rematch wrapper must be cache-busted');
assert(manifest.includes("game-invites-v110.js?v=1143&zone=unified&rematch=optimistic&terminal=self-silent&social=1"), 'Old base invite specifier must converge on one social-aware cache identity');
assert(inviteWrapper.includes("v=1143&zone=unified&rematch=optimistic&terminal=self-silent&social=1"), 'Wrapper must consume the same base invite module identity');
assert(inviteOwner.includes('export function openSocialPlayerInvite'), 'Existing invite owner must expose one bounded social entry');
assert(inviteOwner.includes("inviteRequest('create_direct'"), 'Social invite must remain inside existing direct-invite lifecycle owner');
assert(!friends.includes('/bot/invites.php'), 'Friends UI must not create a second invite endpoint owner');
assert(friends.includes('openSocialPlayerInvite'), 'Friends Invite action must hand off to existing invite owner');
assert(router.includes("friends:Object.freeze({ screen:'friends', shell:false })"), 'Friends must be a registered sub-route');
assert(api.includes("const FRIENDS_URL = `${window.location.origin}/bot/friends.php`;"), 'Active API client must use canonical Friends endpoint');
assert(api.includes('friends: (payload = {}) => requestUrl(FRIENDS_URL, payload)'), 'Active API client must expose Friends request helper');
assert(accountShortcuts.includes("trigger.id === 'moreMenuOpen'"), 'Friends shortcut must be limited to normal topbar menu');
assert(!accountShortcuts.includes("trigger.id === 'gameMenuOpen' ? true"), 'Game menu must not bypass active-match navigation lock');
assert(friends.includes("data-friends-section=\"incoming\"") || friends.includes("section('Входящие заявки'"), 'Friends UI must render incoming requests');
assert(friends.includes("section('Исходящие заявки'"), 'Friends UI must render outgoing requests');
assert(friends.includes("section('Друзья'"), 'Friends UI must render friends');
assert(friends.includes("section('Недавние соперники'"), 'Friends UI must render recent opponents');
for (const action of ['invite','profile','remove','report','block']) {
  assert(friends.includes(`data-social-menu-action=\\\"${action}\\\"`) || friends.includes(`data-social-menu-action="${action}"`), `Player context menu must include ${action}`);
}
assert(friends.includes("api.support('player_report'"), '18.2 report action must hand off to existing support owner');
assert(!friends.toLowerCase().includes('phone') && !friends.toLowerCase().includes('email') && !friends.toLowerCase().includes('real name'), 'Friends UI must not introduce prohibited identity search fields');
assert(endpoint.includes("'player_profile'"), 'Friends endpoint must expose read-only public player profile projection');
assert(endpoint.includes('SocialPlayerProfileReader'), 'Player profile action must use dedicated read-only projection');

console.log('PASS: MVP-18.2 active Friends UI contract');
