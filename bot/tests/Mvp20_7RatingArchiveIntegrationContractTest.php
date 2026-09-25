<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$archive = file_get_contents($root . '/bot/ratings/RatingArchiveService.php');
$endpoint = file_get_contents($root . '/bot/rating-archive.php');
$profile = file_get_contents($root . '/bot/profile-v2.php');
$bootstrap = file_get_contents($root . '/bot/core/bootstrap.php');
$api = file_get_contents($root . '/app/assets/js/api/client.js');
$tournaments = file_get_contents($root . '/app/assets/js/screens/tournaments-screen-v1.js');
$profileScreen = file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
$locale = file_get_contents($root . '/app/locales/ru.json');

foreach ([$archive,$endpoint,$profile,$bootstrap,$api,$tournaments,$profileScreen,$manifest,$locale] as $source) {
    if (!is_string($source)) throw new RuntimeException('MVP-20.7 source file is missing.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(str_contains($archive, 'standingsForSeason'), 'Top-100 archive must reuse canonical LeaderboardService ordering.');
$assertTrue(str_contains($archive, 'mgw_season_awards'), 'Hall of Fame must read durable corrected seasonal placements.');
$assertTrue(str_contains($archive, 'rank_position BETWEEN 1 AND 3'), 'Hall of Fame must be the durable top3 slice.');
$assertTrue(str_contains($archive, "dev_identity.provider = :development_provider"), 'Public archives must exclude development identities.');
$assertTrue(str_contains($archive, "season_state = :state"), 'Archive catalog must expose closed seasons only.');
$assertTrue(!str_contains($archive, 'UPDATE mgw_game_rating_scores'), 'Archive service must be read-only for rating scores.');
$assertTrue(!str_contains($archive, 'INSERT INTO mgw_game_rating_scores'), 'Archive service must never become a rating writer.');

$assertTrue(str_contains($endpoint, "mode === 'overview'"), 'Public archive endpoint must expose overview mode.');
$assertTrue(str_contains($endpoint, "mode === 'season'"), 'Public archive endpoint must expose frozen season top100 mode.');
$assertTrue(str_contains($profile, "'rating_archive'=>$ratingArchive"), 'Profile API must expose personal season history.');
$assertTrue(str_contains($bootstrap, "ratings/RatingArchiveService.php"), 'Runtime bootstrap must load the archive owner.');

$assertTrue(str_contains($api, 'RATING_ARCHIVE_URL'), 'Client API must have one rating archive endpoint owner.');
$assertTrue(str_contains($api, 'ratingArchiveOverview'), 'Client API must expose overview read.');
$assertTrue(str_contains($api, 'ratingArchiveSeason'), 'Client API must expose season archive read.');
$assertTrue(str_contains($api, 'async function requestRatingArchive(payload)'), 'Rating archive reads must have one bounded read-only retry owner.');
$assertTrue(str_contains($api, 'return requestRatingArchive({ mode:\'overview\' })'), 'Overview archive must use the bounded retry owner.');
$assertTrue(str_contains($api, "requestRatingArchive({ mode:'season'"), 'Season archive must use the bounded retry owner.');
$assertTrue(str_contains($api, 'status < 500 || status > 599'), 'Rating archive retry must remain limited to transient 5xx responses.');
$assertTrue(str_contains($tournaments, 'rating-archive-v1'), 'Arena must publish the MVP-20.7 archive surface identity.');
$assertTrue(str_contains($tournaments, 'data-rating-history-mode="seasons"'), 'Arena archive must expose season/tournament mode tabs.');
$assertTrue(str_contains($profileScreen, 'profileRatingArchive'), 'Profile must consume the personal rating history snapshot.');
$assertTrue(str_contains($profileScreen, 'previous_seasons'), 'Profile must render previous official seasons.');
$assertTrue(str_contains($manifest, 'mvp20_7=rating-archives-v1'), 'Version manifest must publish the fresh MVP-20.7 client identity.');

$decoded = json_decode($locale, true, 512, JSON_THROW_ON_ERROR);
$assertTrue(($decoded['profile']['rating_all_time_wins'] ?? null) === 'Победы за всё время', 'Profile locale must name all-time wins.');
$assertTrue(($decoded['shell']['competition_archive_seasons'] ?? null) === 'Сезоны', 'Arena archive locale must expose Seasons tab.');
$assertTrue(($decoded['shell']['competition_archive_tournaments'] ?? null) === 'Турниры', 'Arena archive locale must expose Tournaments tab.');

$assertTrue($assertions >= 20, 'MVP-20.7 integration contract must cover archive owner, Profile, Arena and runtime wiring.');
fwrite(STDOUT, "Mvp20_7RatingArchiveIntegrationContractTest: {$assertions} assertions passed\n");
