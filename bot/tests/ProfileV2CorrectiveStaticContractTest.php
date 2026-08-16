<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$profile = file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$home = file_get_contents($root . '/app/assets/js/screens/home-screen.js');
$model = file_get_contents($root . '/app/assets/js/profile/mgw-profile-model.js');
$ui = file_get_contents($root . '/app/assets/js/ui.js');
$cleanEntry = file_get_contents($root . '/app/assets/js/production-clean-entry-v110.js');
$i18n = file_get_contents($root . '/app/assets/js/localization/i18n.js');
$endpoint = file_get_contents($root . '/bot/profile-v2.php');
$identityPolicy = file_get_contents($root . '/bot/accounts/MgwIdentityPolicy.php');
$idGenerator = file_get_contents($root . '/bot/accounts/MgwIdGenerator.php');
$correctiveCss = file_get_contents($root . '/app/assets/css/screens/profile-corrective.css');
$mainCss = file_get_contents($root . '/app/assets/css/main.css');
$versionManifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
$catalog = json_decode((string)file_get_contents($root . '/app/locales/ru.json'), true, flags: JSON_THROW_ON_ERROR);

foreach (['profile'=>$profile,'home'=>$home,'model'=>$model,'ui'=>$ui,'clean_entry'=>$cleanEntry,'i18n'=>$i18n,'endpoint'=>$endpoint,'identity_policy'=>$identityPolicy,'id_generator'=>$idGenerator,'corrective_css'=>$correctiveCss,'main_css'=>$mainCss,'version_manifest'=>$versionManifest] as $name => $source) {
    if (!is_string($source)) throw new RuntimeException("Unable to read {$name} corrective source.");
}

require_once $root . '/bot/accounts/MgwIdentityPolicy.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';

$assertions = 0;
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) throw new RuntimeException($message . ': missing ' . $needle);
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($haystack, $needle)) throw new RuntimeException($message . ': forbidden ' . $needle);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};

$assertSame('Последние матчи', $catalog['profile']['history_title'] ?? null, 'Recent matches title must be exact');
$assertContains('history.matches.slice(0, 6)', $profile, 'Profile preview must render at most six matches');
$assertContains("userHistory(\$data, \$userId, 6)", $endpoint, 'Backend profile history must request at most six matches');
$assertContains("sectionHead('profile.history_title')", $profile, 'Recent matches heading must render without redundant subtitle');
$assertNotContains("sectionHead('profile.history_title','profile.history_note')", $profile, 'Recent matches subtitle must stay removed');
$assertContains("['starter-default-01','starter-default-02','starter-default-03']", $profile, 'Profile must retain the three canonical starter avatar IDs');
$assertContains('data-edit-mgw-avatar', $profile, 'Profile must expose one compact avatar edit affordance');
$assertContains('profile-v2-avatar-sheet-grid', $profile, 'Avatar choices must live in the dedicated picker sheet');
$assertNotContains('profile-v2-avatar-picker', $profile, 'Starter avatars must not remain permanently expanded in Profile');
foreach (['starter-default-01','starter-default-02','starter-default-03'] as $avatarItemId) {
    $assertContains('[data-avatar-item-id="' . $avatarItemId . '"]', $correctiveCss, 'Profile identity avatar must have a visible starter variant');
    $assertContains('[data-mgw-avatar-choice="' . $avatarItemId . '"]', $correctiveCss, 'Profile picker must preview each starter variant');
    $assertContains('[data-avatar-id="' . $avatarItemId . '"]', $correctiveCss, 'Shared topbar/profile/search avatar surfaces must render the canonical starter variant');
}
$assertNotContains('profile.avatar_saved', $profile, 'Avatar success toast must stay removed');
$assertContains('state.mgwProfile = optimisticProfile', $profile, 'Avatar selection must update visible canonical state optimistically');
$assertContains('state.mgwProfile = previousProfile', $profile, 'Avatar selection must roll back if persistence fails');
$assertNotContains('initStandardAvatarPolicy', $cleanEntry, 'Legacy starter-01 avatar writer must not own canonical avatar surfaces');

$assertContains('data-edit-mgw-nickname', $profile, 'Profile must expose nickname editing');
$assertContains("replace(/\\s+/gu, ' ').trim()", $profile, 'Nickname client normalization must collapse repeated whitespace');
$assertContains("preg_replace('/\\s+/u', ' '", $identityPolicy, 'Nickname backend normalization must collapse repeated whitespace');
$assertContains("preg_match('/^[\\p{L}\\p{N}_ -]+$/u'", $identityPolicy, 'Nickname backend must allow Unicode letters and ordinary spaces');
$assertSame('Царь у дворца', MgwIdentityPolicy::normalizeNickname('  Царь   у дворца  '), 'Cyrillic nickname with spaces must normalize and remain valid');
$invalidNicknameRejected = false;
try { MgwIdentityPolicy::normalizeNickname('Царь!'); } catch (InvalidArgumentException $error) { $invalidNicknameRejected = $error->getMessage() === MgwIdentityPolicy::NICKNAME_INVALID_CHARACTERS_ERROR; }
$assertSame(true, $invalidNicknameRejected, 'Forbidden nickname characters must expose a stable validation reason');
$assertContains('nickname_too_short', $endpoint, 'Endpoint must map short nickname validation');
$assertContains('nickname_too_long', $endpoint, 'Endpoint must map long nickname validation');
$assertContains('nickname_invalid_characters', $endpoint, 'Endpoint must map forbidden nickname validation');
$assertSame('Этот ник уже занят, выберите другой', MgwIdentityPolicy::NICKNAME_TAKEN_ERROR, 'Occupied nickname copy must stay canonical');
$assertContains('MgwIdentityPolicy::NICKNAME_TAKEN_ERROR', $endpoint, 'Endpoint must use the canonical occupied nickname copy');
$assertContains('буквы, цифры, пробелы', (string)($catalog['profile']['nickname_edit_note'] ?? ''), 'Nickname editor must explain accepted character classes');
$assertContains('.mgw-nickname-input', $correctiveCss, 'Nickname editor must use the clean focus treatment');

$internalId = 'MGW-0123456789ABCDEF';
$publicId = 'MGW-ID-0123456789ABCDEF';
$assertSame($publicId, MgwIdGenerator::toPublic($internalId), 'Public MGW-ID format must be deterministic');
$assertSame($internalId, MgwIdGenerator::fromPublic($publicId), 'Public MGW-ID must parse back to the immutable internal id');
$assertSame($internalId, MgwIdGenerator::fromPublic($internalId), 'Legacy internal input must remain parseable for compatibility');
$assertContains('publicMgwId', $model, 'Client model must own the public MGW-ID formatter');
$assertContains('data-copy-mgw-id', $profile, 'Profile must copy the complete public MGW-ID');
$assertNotContains('<span>${escapeHtml(t(\'profile.mgw_id\'))}</span>', $profile, 'Separate MGW-ID label must stay removed from the row');
$assertContains('overflow:visible;text-overflow:clip', $correctiveCss, 'Visible MGW-ID must not be ellipsized');

$assertContains("if (currentScreen() === 'profile') return;", $profile, 'Reopening the already active Profile must be a no-op');
$assertContains('mergeCanonicalMgwUser(state.user, {}, state.mgwProfile)', $profile, 'Profile entry must re-project any stale runtime user through the canonical MGW identity');
$assertContains('state.mgwProfile?.nickname', $ui, 'Shared visible identity must prefer the canonical MGW profile');
$assertContains('state.mgwProfile?.avatar?.item_id', $ui, 'Shared avatar surface must prefer the canonical MGW profile');
$assertNotContains('initStandardAvatarPolicy', $cleanEntry, 'No second canonical avatar writer may remain initialized');

$assertContains("target.id === 'moreMenuOpen'", $home, 'Top more menu must remain the primary settings entry');
$assertContains('id="settingsBtn"', $home, 'More menu must expose Settings');
$assertContains('id="languageSettingsBtn"', $home, 'Settings must expose Language');
$assertContains('.sheet .menu-item:focus-visible', $mainCss, 'Menu focus must use the same row geometry without a second outline');
$assertNotContains('language_en', $home, 'English must not be offered before the full EN catalog exists');
$assertContains('explicitLocale', $i18n, 'Locale precedence must include explicit user choice');
$assertContains('accountLocale', $i18n, 'Locale precedence must include saved MGW account locale');
$assertContains('platformLocale', $i18n, 'Locale precedence must include platform/device locale');
$assertContains('fallbackLocale', $i18n, 'Locale precedence must include fallback locale');
$assertNotContains('geo', strtolower($i18n), 'Locale selection must not depend on geolocation');
$assertNotContains('photo_url: avatarUrl', $model, 'Canonical projection must not restore provider photo ownership');
$assertContains("photo_url: ''", $model, 'Canonical projection must explicitly suppress provider photo URL');
$assertSame(3, substr_count($profile, '[1,2,3].map') === 1 ? 3 : 0, 'Achievements preview must remain exactly three placeholders');

$assertContains('profile-corrective.css?v=3&mvp16=profile-polish', $mainCss, 'Nested corrective stylesheet cache key must advance with profile polish');
$assertContains('main.css?v=170', $versionManifest, 'Runtime main stylesheet cache key must advance with profile polish');
$assertContains('canonical-avatar-owner', $versionManifest, 'Runtime must ship the canonical avatar owner cleanup');
$assertContains('canonical-profile-display-owner', $versionManifest, 'Runtime must ship the canonical visible identity owner');
$assertContains('profile-polish', $versionManifest, 'Runtime must ship the Profile polish controller');

fwrite(STDOUT, "ProfileV2CorrectiveStaticContractTest: {$assertions} assertions passed\n");
