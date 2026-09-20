<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) throw new RuntimeException('Missing contract source: ' . $relative);
    return $content;
};

$migration = $read('bot/database/migrations/20260920_0047_create_yearly_medals.php');
$medal = $read('bot/ratings/YearlyMedalService.php');
$lifecycle = $read('bot/ratings/SeasonLifecycleService.php');
$bootstrap = $read('bot/core/bootstrap.php');
$profileApi = $read('bot/profile-v2.php');
$profileJs = $read('app/assets/js/screens/profile-screen-v110.js');
$css = $read('app/assets/css/main.css');
$locale = $read('app/locales/ru.json');
$manifest = $read('app/runtime/client/version-manifest.php');

$assertTrue(str_contains($migration, 'mgw_yearly_medal_designs'), 'MVP-20.6 must persist one annual design owner.');
$assertTrue(str_contains($migration, 'calendar_year') && str_contains($migration, 'PRIMARY KEY'), 'Annual design must be keyed by year, not by quarter.');
$assertTrue(str_contains($migration, 'mgw_yearly_medal_fragments'), 'MVP-20.6 must persist durable quarter fragments.');
$assertTrue(str_contains($migration, 'PRIMARY KEY (calendar_year, mgw_id, quarter)'), 'A player can own each yearly quarter fragment at most once.');
$assertTrue(str_contains($migration, 'mgw_yearly_medal_runs'), 'Quarter eligibility processing must be idempotent.');
$assertTrue(str_contains($migration, 'mgw_yearly_medal_audit'), 'Fragment corrections must remain auditable.');
$assertTrue(str_contains($migration, 'annual-2026-neon-orbit'), 'The first annual medal must have a stable 2026 identity/design.');

$assertTrue(str_contains($medal, 'mgw_game_rating_participation'), 'Exactly real rated-human participation must drive fragment eligibility.');
$assertTrue(str_contains($medal, 'SELECT DISTINCT p.mgw_id'), 'One rated match in any game must be sufficient for the quarter.');
$assertTrue(!str_contains($medal, "result_code = 'win'"), 'A win must not be required for yearly medal fragment eligibility.');
$assertTrue(str_contains($medal, "dev_identity.provider = :development_provider"), 'Staging development identities must be excluded from official fragments.');
$assertTrue(str_contains($medal, 'eligibility_fingerprint'), 'Repeated eligibility reconciliation must be idempotent.');
$assertTrue(str_contains($medal, "FRAGMENT_REVOKED = 'revoked'"), 'Reviewed corrections must be able to revoke invalid fragment eligibility.');
$assertTrue(str_contains($medal, 'boundaryReadiness'), 'Year-boundary readiness must validate annual medal design assets.');
$assertTrue(str_contains($medal, 'upsertDesign'), 'Recurring annual operations need one explicit design-preparation seam.');
$assertTrue(!str_contains($medal, 'ProductInventoryService'), 'Yearly fragments must not become permanent Store inventory.');
$assertTrue(!str_contains($medal, 'CosmeticStoreService'), 'Yearly fragments must not use Store purchase/equip ownership.');
$assertTrue(!str_contains(strtolower($medal), 'buy'), 'There must be no medal-fragment purchase path.');

$readinessPos = strpos($lifecycle, '$medalReadiness = $medalService->boundaryReadiness');
$seasonalAwardPos = strpos($lifecycle, 'new SeasonalAwardService($database)');
$fragmentPos = strpos($lifecycle, 'new YearlyMedalService($database))->reconcileSeasonFragment');
$closePos = strpos($lifecycle, "'state' => self::SEASON_CLOSED");
$assertTrue($readinessPos !== false && $seasonalAwardPos !== false && $readinessPos < $seasonalAwardPos, 'Annual medal asset readiness must be checked before any partial season-close awards.');
$assertTrue($fragmentPos !== false && $closePos !== false && $fragmentPos < $closePos, 'Quarter fragment eligibility must finish before the ending season becomes CLOSED.');
$assertTrue(str_contains($lifecycle, 'OP_ASSETS_REQUIRED'), 'Missing annual design must reuse durable FINALIZING / ASSETS_REQUIRED.');

$assertTrue(str_contains($bootstrap, "../ratings/YearlyMedalService.php"), 'Runtime bootstrap must load the yearly medal owner.');
$assertTrue(
    strpos($bootstrap, "../ratings/SeasonalAwardService.php") < strpos($bootstrap, "../ratings/YearlyMedalService.php")
    && strpos($bootstrap, "../ratings/YearlyMedalService.php") < strpos($bootstrap, "../ratings/SeasonLifecycleService.php"),
    'Yearly medal dependencies must load before lifecycle composition.'
);

$assertTrue(str_contains($profileApi, '$yearlyMedals = (new YearlyMedalService($database))->userSnapshot($mgwId);'), 'Profile API must expose the canonical yearly-medal snapshot.');
$assertTrue(str_contains($profileApi, "'yearly_medals'=>\$yearlyMedals"), 'Profile payload must carry yearly_medals.');

$assertTrue(str_contains($profileJs, 'state.profileYearlyMedals'), 'Profile client must retain the yearly-medal snapshot.');
$assertTrue(str_contains($profileJs, 'renderYearlyMedalSection'), 'Profile must own one compact yearly-medal renderer.');
$assertTrue(str_contains($profileJs, "source?.visible === true"), 'PRESEASON/inactive snapshots must render no medal UI.');
$assertTrue(str_contains($profileJs, '[1,2,3,4]'), 'Profile medal visual must always model four quarter pieces.');
$assertTrue(str_contains($profileJs, 'profile-v2-yearly-medal-piece'), 'Profile must render four fragment pieces.');
$assertTrue(str_contains($profileJs, 'is-complete'), 'Four earned fragments must expose an assembled state.');

$assertTrue(str_contains($css, '@keyframes mgw-yearly-medal-assemble'), 'Completed annual medal must have a four-part assembly animation.');
$assertTrue(str_contains($css, '@media (prefers-reduced-motion:reduce)'), 'Assembly animation must respect reduced-motion.');
$assertTrue(str_contains($css, 'profile-v2-yearly-medal-piece.q1') && str_contains($css, 'profile-v2-yearly-medal-piece.q4'), 'Profile styling must define all four fragment quadrants.');

$decodedLocale = json_decode($locale, true, 512, JSON_THROW_ON_ERROR);
$assertTrue(($decodedLocale['profile']['yearly_medal_title'] ?? null) === 'Годовая медаль', 'Russian Profile copy must name the yearly medal.');
$assertTrue(str_contains((string)($decodedLocale['profile']['yearly_medal_note'] ?? ''), 'рейтинговый матч'), 'Profile copy must explain the one-rated-match unlock rule.');

$assertTrue(str_contains($manifest, 'mvp20_6=yearly-medal-v1'), 'Version manifest must publish the fresh yearly-medal Profile/CSS identity.');

$assertTrue($assertions >= 35, 'MVP-20.6 integration contract must protect annual design, eligibility, lifecycle and Profile presentation.');

fwrite(STDOUT, "Mvp20_6YearlyMedalIntegrationContractTest: {$assertions} assertions passed\n");
