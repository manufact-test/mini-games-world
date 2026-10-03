import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const owner = read('app/assets/js/profile/mgw-profile-reactions.js');
const header = read('app/assets/js/profile/mgw-profile-reactions-header.js');
const watcher = read('app/assets/js/production-v110-readonly-game-sync.js');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(owner), 'Active Profile reactions owner must contain zero hardcoded Cyrillic.');
assert(owner.includes("from '@mgw/i18n'") && owner.includes('const reactionText =') && owner.includes('const reactionLabel ='),
  'Profile reactions owner must use canonical @mgw/i18n without module-load localized labels.');
assert(owner.includes('api.cosmeticStorePurchase') && owner.includes('ownedReactionCodes') && !owner.includes('api.profileReactionEquip') && !owner.includes('api.profileReactionUnequip'),
  'Cumulative reaction ownership and canonical Store purchase transport must remain unchanged.');
assert(owner.includes("document.addEventListener('mgw:game-reaction'") && owner.includes('api.gameReaction(gameId, code)'),
  'Realtime reaction presentation and send transport must remain unchanged.');
assert(owner.includes('lastReactionFingerprint') && owner.includes('bubble.remove(), 2400') && owner.includes("bubble.style.animationPlayState = 'paused'"),
  'Duplicate suppression and accepted live-bubble presentation timing must remain unchanged.');
assert(owner.includes('formatLocalizedNumber') && !owner.includes("toLocaleString('ru-RU')"),
  'Reaction number formatting must use canonical locale formatting.');
assert(header.includes("from './mgw-profile-reactions.js?v=2&mvp19_3=ingame-corrective-base'"),
  'Accepted reaction header must retain the canonical base import key.');
assert(watcher.includes("document.addEventListener('mgw:app-ready', initMgwProfileReactions"),
  'Reaction UI must still initialize through the accepted read-only game watcher after app-ready.');
assert(manifest.includes("'./assets/js/profile/mgw-profile-reactions.js?v=1&mvp19_3=profile-reactions' => './assets/js/profile/mgw-profile-reactions-header.js?v=6&mvp19_3=header-square-smooth&mobile=stable-bubble-canonical-profile-nav&profile_nav=canonical-pointer-v2&mvp27_1=localized-v1'"),
  'Canonical manifest must publish the localized reaction header owner.');
assert(manifest.includes("'./assets/js/profile/mgw-profile-reactions.js?v=2&mvp19_3=ingame-corrective-base' => './assets/js/profile/mgw-profile-reactions.js?v=7&mvp19_3=cumulative-owned-reactions&store=passive-owned&preview=bounded-packs-v2&route_work=game-only-v1&mvp27_1=localized-v1'"),
  'Canonical manifest must publish the localized reaction base owner.');
assert(Number(ru._meta?.version || 0) >= 43, 'RU locale revision must retain Profile reactions localization v43 or newer.');
assert(ru.profile?.reactions?.title === 'Реакции', 'Accepted RU reactions title must remain unchanged.');
assert(ru.profile?.reactions?.codes?.handshake === 'Хорошая игра', 'Accepted RU reaction labels must remain unchanged.');
assert(ru.profile?.reactions?.purchase?.title === 'Подтвердить покупку', 'Accepted RU reaction purchase title must remain unchanged.');
assert(ru.profile?.reactions?.errors?.send === 'Не удалось отправить реакцию.', 'Accepted RU reaction send error must remain unchanged.');
assert(Number(baseline.cyrillic_lines_total) <= 3384 && Number(baseline.by_scope?.client) <= 1703,
  'Profile reactions localization debt must never regress above the accepted post-reactions baseline.');
assert(Number(baseline.by_scope?.backend) <= 1653 && Number(baseline.by_scope?.['client-entry']) <= 28,
  'Profile reactions localization must not increase backend or client-entry debt.');

console.log('MVP27_1_PROFILE_REACTIONS_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_PROFILE_REACTIONS_HARDCODED_CYRILLIC=0');
