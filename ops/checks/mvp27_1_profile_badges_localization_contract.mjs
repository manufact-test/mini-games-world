import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const owner = read('app/assets/js/profile/mgw-profile-badges.js');
const cleanEntry = read('app/assets/js/production-clean-entry-v110.js');
const manifest = read('app/runtime/client/version-manifest.php');

// Successor-safe: only the bounded MVP-27.2 locale-card delivery marker is permitted.
const acceptedLocaleManifestIdentity = identity => manifest.includes(identity) || manifest.includes(identity.replace(/'$/, "&mvp27_2_locale_cards=v1'"));
const css = read('app/assets/css/components/mgw-profile-badges.css');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(owner), 'Active Profile Badges owner must contain zero hardcoded Cyrillic.');
assert(owner.includes("from '@mgw/i18n'") && owner.includes('const badgeText ='), 'Profile Badges owner must use canonical @mgw/i18n.');
assert(owner.includes("const BADGE_SLOT = 'profile_badge'"), 'Canonical Profile badge equip slot must remain unchanged.');
for (const itemId of ['profile-badge-spark','profile-badge-crest','profile-badge-pulse']) {
  assert(owner.includes(itemId), `Canonical badge item missing: ${itemId}`);
}
assert(owner.includes('api.cosmeticStorePurchase') && owner.includes('api.cosmeticStoreEquip') && owner.includes('api.cosmeticStoreUnequip'), 'Canonical badge purchase/equip/unequip transport must remain unchanged.');
assert(owner.includes('data-profile-badge-store-section') && owner.includes('data-profile-badge-collection'), 'Store and Profile badge surfaces must remain owned by the active module.');
assert(owner.includes("String(players[index]?.badge_item_id") && owner.includes('dataset.profileBadgeAvatarItemId'), 'Live avatar badge projection must remain unchanged.');
assert(owner.includes("const BADGE_PREVIEW_AVATAR = 'starter-default-01'") && owner.includes('style="border-radius:24%"'), 'Accepted rounded-square badge preview identity must remain unchanged.');
assert(cleanEntry.includes("mgw-profile-badges.js?v=5&mvp19_3=profile-badge-avatar-shape"), 'Active clean-entry must retain the canonical Profile Badges import key.');
assert(acceptedLocaleManifestIdentity("'./assets/js/profile/mgw-profile-badges.js?v=5&mvp19_3=profile-badge-avatar-shape' => './assets/js/profile/mgw-profile-badges.js?v=6&mvp19_3=profile-badge-avatar-shape&mvp27_1=localized-v1'"), 'Manifest must map the factual active badge key to localized v6.');
assert(css.includes('profile-badge-spark') && css.includes('profile-badge-crest') && css.includes('profile-badge-pulse'), 'All three accepted badge visual variants must remain unchanged.');
assert(css.includes('[data-profile-badge-avatar-item-id]') && css.includes('pointer-events:none'), 'Equipped badges must remain zero-width avatar overlays.');
assert(css.includes('@media (prefers-reduced-motion:reduce)') && css.includes('animation:none!important'), 'Reduced-motion badge behavior must remain unchanged.');

const badges = ru.profile?.badges;
assert(Number(ru._meta?.version || 0) >= 48, 'RU locale revision must retain Profile Badges localization v48 or newer.');
assert(badges?.title === 'Бейджи' && badges?.type === 'Бейдж' && badges?.fallback_name === 'Бейдж', 'Accepted RU badge labels must remain unchanged.');
assert(badges?.selected_aria === 'Выбран', 'Accepted selected badge accessibility copy must remain unchanged.');
assert(badges?.tiers?.normal === 'Обычный' && badges?.tiers?.rare === 'Редкий' && badges?.tiers?.animated === 'Анимированный', 'Accepted badge tier labels must remain unchanged.');
assert(badges?.actions?.remove === 'Снять' && badges?.actions?.select === 'Выбрать' && badges?.actions?.buy === 'Купить', 'Accepted badge actions must remain unchanged.');
assert(badges?.purchase?.title === 'Подтвердить покупку' && badges?.purchase?.to_pay === 'К оплате' && badges?.purchase?.remaining === 'Останется', 'Accepted badge purchase copy must remain unchanged.');
assert(badges?.toast?.purchased === 'Бейдж добавлен в коллекцию.' && badges?.toast?.removed === 'Бейдж снят.' && badges?.toast?.selected === 'Бейдж выбран.', 'Accepted badge success copy must remain unchanged.');
assert(badges?.errors?.purchase === 'Не удалось купить бейдж.' && badges?.errors?.remove === 'Не удалось снять бейдж.' && badges?.errors?.select === 'Не удалось выбрать бейдж.', 'Accepted badge error copy must remain unchanged.');
assert(baseline.cyrillic_lines_total <= 3296 && baseline.by_scope?.client <= 1615, 'Profile Badges localization debt must never regress above the accepted post-slice baseline.');

console.log('MVP27_1_PROFILE_BADGES_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_PROFILE_BADGES_HARDCODED_CYRILLIC=0');
