import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const owner = read('app/assets/js/profile/mgw-profile-backgrounds.js');
const mainShell = read('app/assets/js/main-v110-handoff-shell.js');
const cleanEntry = read('app/assets/js/production-clean-entry-v110.js');
const manifest = read('app/runtime/client/version-manifest.php');

// Successor-safe: only the bounded MVP-27.2 locale-card delivery marker is permitted.
const acceptedLocaleManifestIdentity = identity => manifest.includes(identity) || manifest.includes(identity.replace(/'$/, "&mvp27_2_locale_cards=v1'"));
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(owner), 'Active Profile backgrounds owner must contain zero hardcoded Cyrillic.');
assert(owner.includes("from '@mgw/i18n'") && owner.includes('const backgroundText ='), 'Profile backgrounds owner must use canonical @mgw/i18n.');
assert(owner.includes('api.cosmeticStorePurchase') && owner.includes('api.cosmeticStoreEquip') && owner.includes('api.cosmeticStoreUnequip'),
  'Background purchase/equip/unequip transports must remain unchanged.');
assert(owner.includes('data-profile-background-store-section') && owner.includes('data-profile-background-collection'),
  'Background Store/Profile presentation ownership must remain unchanged.');
assert(owner.includes("document.addEventListener('mgw:app-ready'") && owner.includes("getElementById('screen-profile')"),
  'Background hydration and Profile projection ownership must remain unchanged.');
const importKey = "./profile/mgw-profile-backgrounds.js?v=2&mvp19_3=profile-backgrounds-ux-corrective";
assert(mainShell.includes(importKey) && cleanEntry.includes(importKey),
  'Main shell and clean-entry route owner must retain the canonical Profile backgrounds import key.');
assert(acceptedLocaleManifestIdentity("'./assets/js/profile/mgw-profile-backgrounds.js?v=2&mvp19_3=profile-backgrounds-ux-corrective' => './assets/js/profile/mgw-profile-backgrounds.js?v=4&mvp19_3=full-profile-surface&mvp27_1=localized-v1'"),
  'Canonical manifest must publish the localized Profile backgrounds owner.');
assert(Number(ru._meta?.version || 0) >= 42, 'RU locale revision must retain Profile backgrounds localization v42 or a newer successor.');
assert(ru.profile?.backgrounds?.title === 'Фоны профиля', 'Accepted RU Profile backgrounds title must remain unchanged.');
assert(ru.profile?.backgrounds?.purchase?.title === 'Подтвердить покупку', 'Accepted RU background purchase title must remain unchanged.');
assert(ru.profile?.backgrounds?.errors?.purchase === 'Не удалось купить фон.', 'Accepted RU background purchase error must remain unchanged.');
assert(ru.profile?.backgrounds?.toast?.selected === 'Фон выбран.', 'Accepted RU background equip toast must remain unchanged.');
assert(Number(baseline.cyrillic_lines_total) <= 3408 && Number(baseline.by_scope?.client) <= 1727,
  'Profile backgrounds localization debt must never regress above the accepted post-background baseline.');
assert(Number(baseline.by_scope?.backend) <= 1653 && Number(baseline.by_scope?.['client-entry']) <= 28,
  'Profile backgrounds localization must not increase backend or client-entry debt.');

console.log('MVP27_1_PROFILE_BACKGROUNDS_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_PROFILE_BACKGROUNDS_HARDCODED_CYRILLIC=0');
