<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/accounts/MgwIdentityPolicy.php';

$profile = file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$home = file_get_contents($root . '/app/assets/js/screens/home-screen.js');
$visuals = file_get_contents($root . '/app/assets/js/components/shield-king-visuals.js');
$mainCss = file_get_contents($root . '/app/assets/css/main.css');
$endpoint = file_get_contents($root . '/bot/profile-v2.php');
$versionManifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
$catalog = json_decode((string)file_get_contents($root . '/app/locales/ru.json'), true, flags: JSON_THROW_ON_ERROR);

foreach (['profile'=>$profile,'home'=>$home,'visuals'=>$visuals,'main_css'=>$mainCss,'endpoint'=>$endpoint,'version_manifest'=>$versionManifest] as $name => $source) {
    if (!is_string($source)) throw new RuntimeException("Unable to read {$name} pass A source.");
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($haystack, $needle)) throw new RuntimeException($message . ' Unexpected: ' . $needle);
};

$assertSame(13, MgwIdentityPolicy::NICKNAME_MAX_LENGTH, 'Backend nickname cap must be 13.');
$generated = MgwIdentityPolicy::generateNickname();
$assertSame(13, strlen($generated), 'Generated nickname must fit the 13-character cap.');
$assertSame(true, preg_match('/^Player\d{7}$/', $generated) === 1, 'Generated nickname must retain stable Player + digits shape.');
$assertSame('Царь у дворца', MgwIdentityPolicy::normalizeNickname('  Царь   у дворца  '), 'Cyrillic nickname with spaces must remain valid.');
$tooLongRejected = false;
try { MgwIdentityPolicy::normalizeNickname('12345678901234'); } catch (InvalidArgumentException $error) { $tooLongRejected = $error->getMessage() === MgwIdentityPolicy::NICKNAME_TOO_LONG_ERROR; }
$assertSame(true, $tooLongRejected, 'Fourteen-character nickname must be rejected.');

$assertContains('const NICKNAME_MAX_LENGTH = 13;', $profile, 'Client nickname cap must be 13.');
$assertContains('maxlength="${NICKNAME_MAX_LENGTH}"', $profile, 'Nickname input must enforce the client cap.');
$assertContains('nicknameSaving = true', $profile, 'Nickname persistence must keep an in-flight owner.');
$assertContains('state.mgwProfile = optimisticProfile', $profile, 'Nickname save must update visible state optimistically.');
$assertContains('state.mgwProfile = previousProfile', $profile, 'Nickname save must rollback on backend failure.');
$assertNotContains("toast(t('profile.nickname_saved'))", $profile, 'Successful nickname save must be silent.');
$assertNotContains('save.disabled = true', $profile, 'Nickname save must not visibly stick disabled.');
$assertContains('максимум 13 символов', $endpoint, 'Backend validation copy must match the final cap.');
$assertSame('Ник может содержать максимум 13 символов.', $catalog['profile']['nickname_too_long'] ?? null, 'Too-long feedback must be localized.');
$assertContains('От 3 до 13 символов', (string)($catalog['profile']['nickname_edit_note'] ?? ''), 'Nickname editor note must show the final cap.');

$assertContains("menuItemMarkup('settingsBtn', '⚙️', t('settings.title'))", $home, 'Settings must use the shared standard menu-row markup.');
$assertContains("menuItemMarkup('rulesBtn', '📘', 'Правила')", $home, 'Neighboring rows must use the same shared markup.');
$assertContains('menu-item-standard', $home, 'Shared More-menu row class must own standard geometry.');
$assertContains('.sheet .menu-item-standard', $mainCss, 'Standard menu geometry must have one shared CSS owner.');
$assertContains('.sheet .menu-item:focus,.sheet .menu-item:focus-visible', $mainCss, 'All menu rows must share one focus geometry rule.');
$assertNotContains('.sheet #settingsBtn', $mainCss, 'Settings must not need a one-off CSS patch.');
$assertContains("settingsBtn:'ui/navigation/settings.webp'", $visuals, 'Settings must use the accepted Shield King metallic settings icon.');
$assertContains("rulesBtn:'ui/actions/rules.webp'", $visuals, 'Settings and neighboring rows must share the same dynamic metallic icon owner.');

$assertContains('home-screen.js?v=80&mvp16=settings-row-owner', $versionManifest, 'Settings row cache key must stay current.');
$assertContains('profile-screen-v110.js?v=1118&mvp16=profile-pass-a', $versionManifest, 'Profile pass A cache key must stay current.');
$assertions++;
if (preg_match('/main\.css\?v=(\d+)/', $versionManifest, $mainCssVersionMatch) !== 1 || (int)$mainCssVersionMatch[1] < 171) {
    throw new RuntimeException('Main CSS cache key must stay at or beyond the Profile pass A baseline.');
}
$assertContains("shield-king-visuals.js?v=127&sk=4&icons=c1efd5af&shell=nav' => './assets/js/components/shield-king-visuals.js?v=128&sk=4&icons=c1efd5af&shell=nav&settings=metallic", $versionManifest, 'Telegram shell must cache-bust the corrected Settings metallic icon owner.');

fwrite(STDOUT, "ProfileV2PassAStaticContractTest: {$assertions} assertions passed\n");
