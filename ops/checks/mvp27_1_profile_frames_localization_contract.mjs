import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const owner = read('app/assets/js/profile/mgw-profile-frames.js');
const cleanEntry = read('app/assets/js/production-clean-entry-v110.js');
const manifest = read('app/runtime/client/version-manifest.php');

// Successor-safe: only the bounded MVP-27.2 locale-card delivery marker is permitted.
// Keep the factual import key and localized owner/version exact; only allow
// explicitly versioned MVP-27.2 cache markers after that accepted identity.
const acceptedLocaleManifestIdentity = identity => {
  if (manifest.includes(identity)) return true;
  const stem = identity.endsWith("'") ? identity.slice(0, -1) : identity;
  const at = manifest.indexOf(stem);
  if (at < 0) return false;
  return /^(&mvp27_2_[a-z0-9_]+=[a-z0-9_-]+)+'/.test(manifest.slice(at + stem.length));
};
const css = read('app/assets/css/components/mgw-profile-frames.css');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(owner), 'Active Profile Frames owner must contain zero hardcoded Cyrillic.');
assert(owner.includes("from '@mgw/i18n'") && owner.includes('const frameText ='), 'Profile Frames owner must use canonical @mgw/i18n.');
assert(owner.includes("const FRAME_SLOT = 'profile_frame'"), 'Canonical Profile frame equip slot must remain unchanged.');
for (const itemId of ['profile-frame-01','profile-frame-02','profile-frame-03','profile-frame-animated']) {
  assert(owner.includes(itemId), `Canonical frame item missing: ${itemId}`);
}
assert(owner.includes('api.cosmeticStorePurchase') && owner.includes('api.cosmeticStoreEquip') && owner.includes('api.cosmeticStoreUnequip'), 'Canonical frame purchase/equip/unequip transport must remain unchanged.');
assert(owner.includes('FRAME_NAME_KEYS') && owner.includes("'profile-frame-01':'names.sky'") && owner.includes("'profile-frame-animated':'names.spectrum'"), 'Frame product identity must remain bound to deterministic locale keys.');
assert(cleanEntry.includes("mgw-profile-frames.js?v=4&mvp19_3=profile-frame-avatar-card-parity"), 'Active clean-entry must retain the canonical Profile Frames import key.');
assert(acceptedLocaleManifestIdentity("'./assets/js/profile/mgw-profile-frames.js?v=4&mvp19_3=profile-frame-avatar-card-parity' => './assets/js/profile/mgw-profile-frames.js?v=5&mvp19_3=profile-frame-avatar-card-parity&mvp27_1=localized-v1'"), 'Manifest must map the factual active frame key to localized v5.');
assert(css.includes('[data-profile-frame-avatar-item-id]::before') && css.includes('profile-frame-animated'), 'Avatar frame overlay and animated top tier CSS owners must remain unchanged.');
assert(css.includes('@media (prefers-reduced-motion:reduce)') && css.includes('animation:none!important'), 'Reduced-motion behavior must remain unchanged.');

const frames = ru.profile?.frames;
assert(Number(ru._meta?.version || 0) >= 47, 'RU locale revision must retain Profile Frames localization v47 or newer.');
assert(frames?.title === 'Рамки' && frames?.type === 'Рамка', 'Accepted RU Profile Frames labels must remain unchanged.');
assert(frames?.names?.sky === 'Голубое небо', 'Accepted frame-01 RU name must remain unchanged.');
assert(frames?.names?.gold === 'Золотой ореол', 'Accepted frame-02 RU name must remain unchanged.');
assert(frames?.names?.aurora === 'Аврора', 'Accepted frame-03 RU name must remain unchanged.');
assert(frames?.names?.spectrum === 'Живой спектр', 'Accepted animated frame RU name must remain unchanged.');
assert(frames?.tiers?.normal === 'Обычная' && frames?.tiers?.rare === 'Редкая' && frames?.tiers?.epic === 'Эпическая' && frames?.tiers?.animated === 'Анимированная', 'Accepted frame tier labels must remain unchanged.');
assert(frames?.actions?.remove === 'Снять' && frames?.actions?.select === 'Выбрать' && frames?.actions?.buy === 'Купить', 'Accepted frame actions must remain unchanged.');
assert(frames?.purchase?.title === 'Подтвердить покупку' && frames?.purchase?.to_pay === 'К оплате' && frames?.purchase?.remaining === 'Останется', 'Accepted frame purchase copy must remain unchanged.');
assert(frames?.errors?.purchase === 'Не удалось купить рамку.' && frames?.errors?.remove === 'Не удалось снять рамку.' && frames?.errors?.select === 'Не удалось выбрать рамку.', 'Accepted frame error copy must remain unchanged.');
assert(baseline.cyrillic_lines_total <= 3315 && baseline.by_scope?.client <= 1634, 'Profile Frames localization debt must never regress above the accepted post-slice baseline.');

console.log('MVP27_1_PROFILE_FRAMES_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_PROFILE_FRAMES_HARDCODED_CYRILLIC=0');
