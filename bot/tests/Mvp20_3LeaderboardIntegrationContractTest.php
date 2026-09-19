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

$migration = $read('bot/database/migrations/20260919_0044_create_leaderboards_and_antifarming.php');
$rating = $read('bot/ratings/PerGameRatingService.php');
$leaderboard = $read('bot/ratings/LeaderboardService.php');
$endpoint = $read('bot/leaderboard.php');
$bootstrap = $read('bot/core/bootstrap.php');
$client = $read('app/assets/js/api/client.js');
$profile = $read('app/assets/js/screens/profile-screen-v110.js');
$locale = json_decode($read('app/locales/ru.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = $read('app/runtime/client/version-manifest.php');
$v110 = $read('app/v110.php');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$assertTrue(str_contains($bootstrap, "../ratings/LeaderboardService.php"), 'Bootstrap must load one leaderboard owner.');
$assertTrue(str_contains($migration, 'min_rated_matches') && str_contains($migration, 'DEFAULT 5'), 'Leaderboard minimum must stay five rated matches.');
$assertTrue(str_contains($migration, 'min_human_wins') && str_contains($migration, 'DEFAULT 1'), 'Leaderboard minimum must stay one human win.');
$assertTrue(
    str_contains($migration, 'max_credited_wins_same_opponent_day')
        && str_contains($migration, 'DEFAULT 3'),
    'Anti-farming cap must stay three credited wins vs the same opponent/day.'
);
$assertTrue(str_contains($migration, "'Europe/Moscow'"), 'Daily anti-farming boundary must use the product Moscow timezone.');
$assertTrue(str_contains($migration, 'mgw_game_rating_participation'), 'Eligibility must have one durable participation owner.');
$assertTrue(str_contains($migration, 'mgw_rating_daily_pair_wins'), 'Anti-farming must have one durable daily pair counter.');
$assertTrue(str_contains($migration, 'anti_farming_started_at_utc'), 'New anti-farming rule must have a non-retroactive activation boundary.');

$assertTrue(str_contains($rating, "'anti_farming_cap'"), 'Fourth same-opponent/day win must have an explicit audit result.');
$assertTrue(str_contains($rating, 'reserveDailyWinSlot'), 'Visible rating owner must gate credits before incrementing score.');
$assertTrue(str_contains($rating, 'max_credited_wins_same_opponent_day'), 'Visible rating owner must read the canonical cap.');
$assertTrue(str_contains($rating, 'ratingDay('), 'Visible rating owner must assign one canonical day to anti-farming.');
$assertTrue(str_contains($rating, 'points_requested'), 'Audit must preserve rating requested before anti-farming.');
$assertTrue(str_contains($rating, 'recordParticipation'), 'Rated human participation must be written by the same result projection.');
$assertTrue(str_contains($rating, "result_code = 'win'") === false, 'Visible rating owner must not query leaderboard aggregates while awarding points.');

foreach ([
    'tictactoe',
    'four_in_a_row',
    'battleship',
    'checkers',
    'reversi',
    'chess',
    'go',
    'domino',
] as $gameType) {
    $assertTrue(str_contains($leaderboard, "'{$gameType}'"), 'Leaderboard owner must support ' . $gameType . '.');
}
$assertTrue(str_contains($leaderboard, "HAVING COUNT(*) >= ' . \$minMatches"), 'Eligibility must enforce minimum rated matches in the authoritative query.');
$assertTrue(str_contains($leaderboard, ">= ' . \$minWins"), 'Eligibility must enforce minimum human wins in the authoritative query.');
$assertTrue(str_contains($leaderboard, 'ORDER BY s.points DESC'), 'Leaderboard ordering must start with visible points descending.');
$assertTrue(str_contains($leaderboard, 's.rated_wins DESC'), 'Credited wins must be the second tie-break.');
$assertTrue(str_contains($leaderboard, 's.updated_at_utc ASC'), 'Earlier arrival at equal score must be the third tie-break.');
$assertTrue(str_contains($leaderboard, 's.mgw_id ASC'), 'Stable MGW id must be the final deterministic tie-break.');
$assertTrue(str_contains($leaderboard, 'MgwIdGenerator::toPublic'), 'Leaderboard must expose public MGW id rather than internal identifiers.');
$assertTrue(!str_contains($leaderboard, 'HiddenSkillService') && !str_contains($leaderboard, 'skill_score'), 'Leaderboard must not expose hidden skill.');

$assertTrue(str_contains($endpoint, 'PerGameRatingRuntimeBridge'), 'Leaderboard endpoint must reuse the canonical rating projection before reads.');
$assertTrue(str_contains($endpoint, 'new LeaderboardService'), 'Leaderboard endpoint must read through the single leaderboard owner.');
$assertTrue(!str_contains($endpoint, 'HiddenSkillService') && !str_contains($endpoint, 'skill_score'), 'Endpoint must not expose hidden skill.');

$assertTrue(str_contains($client, 'LEADERBOARD_URL'), 'Client must use one dedicated leaderboard endpoint.');
$assertTrue(str_contains($client, 'leaderboard: (gameType'), 'Client API must expose lazy per-game leaderboard loading.');
$assertTrue(str_contains($profile, "data-open-leaderboard"), 'Profile must provide one user-facing leaderboard launcher.');
$assertTrue(str_contains($profile, 'openLeaderboardSheet'), 'Leaderboard UI must load only after the user opens it.');
$assertTrue(str_contains($profile, 'GAME_TYPES.map(type => leaderboardTab'), 'Leaderboard sheet must expose all eight game tabs.');
$assertTrue(str_contains($profile, "profile.leaderboard_preseason"), 'PRESEASON board must be visibly marked as non-official.');
$assertTrue(!str_contains($profile, 'skill_score') && !str_contains($profile, 'hidden_skill'), 'Profile leaderboard must not display hidden skill.');

$profileKeys = $locale['profile'] ?? [];
foreach ([
    'leaderboard_title',
    'leaderboard_open_note',
    'leaderboard_preseason',
    'leaderboard_progress',
    'leaderboard_empty',
    'leaderboard_error',
] as $key) {
    $assertTrue(
        is_string($profileKeys[$key] ?? null) && trim((string)$profileKeys[$key]) !== '',
        'Russian locale must define profile.' . $key
    );
}

$assertTrue(str_contains($manifest, 'mvp20_3=leaderboards-v1'), 'Version manifest must publish fresh leaderboard client assets.');
$assertTrue(str_contains($v110, 'X-MGW-Leaderboards: per-game-antifarming-v1'), 'Active v110 entry must identify the leaderboard runtime.');
$assertTrue(str_contains($launch, 'leaderboards=per-game-antifarming-v1'), 'Telegram launch identity must include MVP-20.3.');
$assertTrue(str_contains($manifest, 'mvp20_1=visible-rating-v2'), 'MVP-20.1 visible-rating identity must remain frozen.');
$assertTrue(str_contains($v110, 'X-MGW-Visible-Rating: per-game-preseason-v2'), 'Existing visible-rating header must remain frozen.');

fwrite(STDOUT, "Mvp20_3LeaderboardIntegrationContractTest: {$assertions} assertions passed\n");
