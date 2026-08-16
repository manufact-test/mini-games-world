<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$profile = file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$home = file_get_contents($root . '/app/assets/js/screens/home-screen.js');
$model = file_get_contents($root . '/app/assets/js/profile/mgw-profile-model.js');
$i18n = file_get_contents($root . '/app/assets/js/localization/i18n.js');
$endpoint = file_get_contents($root . '/bot/profile-v2.php');
$catalog = json_decode((string)file_get_contents($root . '/app/locales/ru.json'), true, flags: JSON_THROW_ON_ERROR);

foreach (['profile' => $profile, 'home' => $home, 'model' => $model, 'i18n' => $i18n, 'endpoint' => $endpoint] as $name => $source) {
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
$assertContains("['starter-default-01','starter-default-02','starter-default-03']", $profile, 'Profile must expose the three canonical starter avatar IDs');
$assertContains('data-edit-mgw-nickname', $profile, 'Profile must expose nickname editing');
$assertContains('mgw:open-language-settings', $profile, 'Profile language row must reuse the shared settings owner');
$assertContains("target.id === 'moreMenuOpen'", $home, 'Top more menu must remain the primary settings entry');
$assertContains('id="settingsBtn"', $home, 'More menu must expose Settings');
$assertContains('id="languageSettingsBtn"', $home, 'Settings must expose Language');
$assertNotContains('language_en', $home, 'English must not be offered before the full EN catalog exists');
$assertContains('explicitLocale', $i18n, 'Locale precedence must include explicit user choice');
$assertContains('accountLocale', $i18n, 'Locale precedence must include saved MGW account locale');
$assertContains('platformLocale', $i18n, 'Locale precedence must include platform/device locale');
$assertContains('fallbackLocale', $i18n, 'Locale precedence must include fallback locale');
$assertNotContains('geo', strtolower($i18n), 'Locale selection must not depend on geolocation');
$assertNotContains('photo_url: avatarUrl', $model, 'Canonical projection must not restore provider photo ownership');
$assertContains("photo_url: ''", $model, 'Canonical projection must explicitly suppress provider photo URL');
$assertSame(3, substr_count($profile, "[1,2,3].map" ) === 1 ? 3 : 0, 'Achievements preview must remain exactly three placeholders');

fwrite(STDOUT, "ProfileV2CorrectiveStaticContractTest: {$assertions} assertions passed\n");
