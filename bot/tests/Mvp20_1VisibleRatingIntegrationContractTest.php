<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    $content = file_get_contents($path);
    if (!is_string($content)) throw new RuntimeException('Missing contract source: ' . $relative);
    return $content;
};

$bootstrap = $read('bot/core/bootstrap.php');
$profileApi = $read('bot/profile-v2.php');
$profileClient = $read('app/assets/js/screens/profile-screen-v110.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$v110 = $read('app/v110.php');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$localeRaw = $read('app/locales/ru.json');
$locale = json_decode($localeRaw, true, 512, JSON_THROW_ON_ERROR);

$assertTrue(str_contains($bootstrap, "../ratings/PerGameRatingService.php"), 'Bootstrap must load the single rating owner.');
$assertTrue(str_contains($bootstrap, "../ratings/PerGameRatingRuntimeBridge.php"), 'Bootstrap must load the rating runtime bridge.');
$weeklyHook = strpos($bootstrap, "if (\$runtimeScript === 'api.php' && \$runtimeWeeklyBonusBridge->shouldAttachToCurrentRequest");
$ratingHook = strpos($bootstrap, "if (\$runtimeScript === 'api.php' && \$runtimeRatingBridge->shouldAttachToCurrentRequest");
$assertTrue(is_int($weeklyHook) && is_int($ratingHook) && $ratingHook > $weeklyHook, 'Rating must consume normalized results after realtime/weekly projection.');
$assertTrue(str_contains($bootstrap, '$runtimeRatingBridge->processProjectedMatches();'), 'API success path must process projected terminal matches.');

$assertTrue(str_contains($profileApi, 'snapshotForProfile($mgwId)'), 'Profile API must refresh and read canonical rating snapshot.');
$assertTrue(str_contains($profileApi, "'rating'=>\$rating"), 'Profile API must expose rating as a first-class payload.');

$assertTrue(str_contains($profileClient, 'state.profileRating = result.rating'), 'Profile client must consume rating payload.');
$assertTrue(str_contains($profileClient, "profile.rating_title"), 'Profile must render a visible seasonal rating section.');
$assertTrue(str_contains($profileClient, "ratingNoteKey(rating)"), 'Profile must distinguish OFF/PRESEASON/ACTIVE copy.');
$assertTrue(str_contains($profileClient, "GAME_TYPES.map(gameType => gameRatingCard"), 'Profile must show rating for all eight canonical games.');

$profileKeys = $locale['profile'] ?? [];
foreach (['rating_title','rating_note_preseason','rating_note_active','rating_note_off','rating_points'] as $key) {
    $assertTrue(is_string($profileKeys[$key] ?? null) && trim((string)$profileKeys[$key]) !== '', 'Russian locale must define profile.' . $key);
}

$assertTrue(str_contains($manifest, 'mvp20_1=visible-rating-v1'), 'Version manifest must publish a fresh rating UI identity.');
$assertTrue(str_contains($v110, 'X-MGW-Visible-Rating: per-game-preseason-v1'), 'Active v110 entry must identify the visible-rating runtime.');
$assertTrue(str_contains($v110, "'visible_rating_profile'"), 'Active v110 entry must fail closed if the fresh profile rating target is absent.');
$assertTrue(str_contains($launch, 'rating=per-game-visible-v1'), 'Telegram launch URL must carry the fresh rating deployment identity.');

$service = $read('bot/ratings/PerGameRatingService.php');
$assertTrue(str_contains($service, "public const STATE_PRESEASON = 'preseason';"), 'PRESEASON must be explicit in the rating owner.');
$assertTrue(str_contains($service, "public const STATE_ACTIVE = 'active';"), 'ACTIVE must be explicit in the rating owner.');
$assertTrue(str_contains($service, "finishReason !== 'normal_win'"), 'Technical results must not receive rating.');
$assertTrue(str_contains($service, "TOURNAMENT_MATCH_SOURCE"), 'Tournament +2 compatibility must have one exact source contract.');
$assertTrue(str_contains($service, 'INSERT OR IGNORE INTO mgw_game_rating_outcomes'), 'SQLite idempotency must be enforced by the durable match outcome key.');
$assertTrue(str_contains($service, 'INSERT IGNORE INTO mgw_game_rating_outcomes'), 'MySQL idempotency must be enforced by the durable match outcome key.');

fwrite(STDOUT, "Mvp20_1VisibleRatingIntegrationContractTest: {$assertions} assertions passed\n");
