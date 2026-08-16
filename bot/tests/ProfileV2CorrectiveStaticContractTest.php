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
$assertContains('profile-corrective.css?v=3&mvp16=profile-polish', $mainCss, 'Nested corrective stylesheet cache key must advance with profile polish');
$assertContains('main.css?v=170', $versionManifest, 'Runtime main stylesheet cache key must advance with profile polish');
$assertContains('data-edit-mgw-nickname', $profile, 'Profile must expose nickname editing');
$assertContains("replace(/\\s+/gu, ' ').trim()", $profile, 'Nickname client normalization must collapse repeated whitespace');
$assertContains("preg_replace('/\\s+/u', ' '", $identityPolicy, 'Nickname backend normalization must collapse repeated whitespace');
$assertContains("preg_match('/^[\\p{L}\\p{N}_ -]+$/u'", $identityPolicy, 'Nickname backend must allow Unicode letters and ordinary spaces');
$assertSame('Этот ник уже занят, выберите другой', $catalog['profile']['nickname_edit_note'] ?? null, 'sentinel');
