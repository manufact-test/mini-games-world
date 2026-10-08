import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const owner = read('app/assets/js/profile/mgw-profile-entry-effects.js');
const arbitration = read('app/assets/js/profile/mgw-entry-effect-player-arbitration.js');
const watcher = read('app/assets/js/production-v110-readonly-game-sync.js');
const manifest = read('app/runtime/client/version-manifest.php');

// Successor-safe: only the bounded MVP-27.2 locale-card delivery marker is permitted.
const acceptedLocaleManifestIdentity = identity => manifest.includes(identity) || manifest.includes(identity.replace(/'$/, "&mvp27_2_locale_cards=v1'"));
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(owner), 'Active Profile Entry Effects owner must contain zero hardcoded Cyrillic.');
assert(owner.includes("from '@mgw/i18n'") && owner.includes('const entryText ='), 'Entry Effects owner must use canonical @mgw/i18n.');
assert(owner.includes('api.cosmeticStorePurchase') && owner.includes('api.cosmeticStoreEquip') && owner.includes('api.cosmeticStoreUnequip'), 'Canonical purchase/equip ownership must remain unchanged.');
assert(owner.includes('arbitratePlayerEntryEffects(players, {') && owner.includes('isLocalPlayer: player => isLocalEntryEffectPlayer(player, localIds)'), 'Player arbitration handoff must remain unchanged.');
assert(owner.includes("document.addEventListener('mgw:game-entered'") && owner.includes('if (launchOverlayVisible()) return;'), 'Authoritative game-entry and launch-overlay handoff must remain unchanged.');
assert(owner.includes('playedGames.add(gameId);') && owner.includes('Math.min(4000, Math.max(2000, duration))'), 'Entry Effects must remain once-per-game and bounded to 2-4 seconds.');
assert(arbitration.includes('return list.map((player, index) => resolveEntry(player, index)).filter(Boolean);'), 'Spectator arbitration behavior must remain unchanged.');
assert(watcher.includes("import { initMgwProfileEntryEffects } from './profile/mgw-profile-entry-effects.js?v=1&mvp19_3=entry-effects';"), 'Readonly game-sync must keep the canonical import-map entry key.');
assert(acceptedLocaleManifestIdentity("'./assets/js/profile/mgw-profile-entry-effects.js?v=1&mvp19_3=entry-effects' => './assets/js/profile/mgw-profile-entry-effects.js?v=8&mvp19_3=player-arbitration&mvp25_4=profile-post-paint-v1&mvp27_1=localized-v1'"), 'Manifest must publish the localized active Entry Effects owner.');
assert(Number(ru._meta?.version || 0) >= 39, 'RU locale revision must retain Entry Effects localization v39 or a newer successor.');
assert(ru.profile?.entry_effects?.title === 'Эффекты входа', 'Accepted RU Entry Effects title must remain unchanged.');
assert(ru.profile?.entry_effects?.purchase?.title === 'Подтвердить покупку', 'Accepted RU purchase title must remain unchanged.');
assert(ru.profile?.entry_effects?.errors?.purchase === 'Не удалось купить эффект входа.', 'Accepted RU purchase error must remain unchanged.');
assert(ru.profile?.entry_effects?.live?.entering === 'вступает в игру', 'Accepted RU live-entry copy must remain unchanged.');
assert(baseline.cyrillic_lines_total <= 3453 && baseline.by_scope?.client <= 1772, 'Entry Effects localization debt must never regress above the accepted post-Entry-Effects baseline.');

console.log('MVP27_1_PROFILE_ENTRY_EFFECTS_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_PROFILE_ENTRY_EFFECTS_HARDCODED_CYRILLIC=0');
