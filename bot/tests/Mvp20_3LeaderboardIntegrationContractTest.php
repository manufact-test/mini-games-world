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
$arena = $read('app/assets/js/screens/tournaments-screen-v1.js');
$mainCss = $read('app/assets/css/main.css');
$mainShell = $read('app/assets/js/main-v110-handoff-shell.js');
$locale = json_decode($read('app/locales/ru.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = $read('app/runtime/client/version-manifest.php');

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
    $assertTrue(str_contains($arena, "'{$gameType}'"), 'Arena rating selector must expose ' . $gameType . '.');
}
$assertTrue(str_contains($leaderboard, "HAVING COUNT(*) >= ' . \$minMatches"), 'Eligibility must enforce minimum rated matches in the authoritative query.');
$assertTrue(str_contains($leaderboard, ">= ' . \$minWins"), 'Eligibility must enforce minimum human wins in the authoritative query.');
$assertTrue(str_contains($leaderboard, 'ORDER BY s.points DESC'), 'Leaderboard ordering must start with visible points descending.');
$assertTrue(str_contains($leaderboard, 's.rated_wins DESC'), 'Credited wins must be the second tie-break.');
$assertTrue(str_contains($leaderboard, 's.updated_at_utc ASC'), 'Earlier arrival at equal score must be the third tie-break.');
$assertTrue(str_contains($leaderboard, 's.mgw_id ASC'), 'Stable MGW id must be the final deterministic tie-break.');
$assertTrue(str_contains($leaderboard, 'MgwIdGenerator::toPublic'), 'Leaderboard must expose public MGW id rather than internal identifiers.');
$assertTrue(!str_contains($leaderboard, 'HiddenSkillService') && !str_contains($leaderboard, 'skill_score'), 'Leaderboard must not expose hidden skill.');
$assertTrue(
    str_contains($leaderboard, 'mgw_identities')
        && str_contains($leaderboard, "dev_identity.provider = :development_provider")
        && str_contains($leaderboard, "'development_provider' => 'development'"),
    'Public leaderboard must exclude staging development identities while preserving their audit history.'
);

$assertTrue(str_contains($endpoint, 'PerGameRatingRuntimeBridge'), 'Leaderboard endpoint must reuse the canonical rating projection owner.');
$assertTrue(str_contains($endpoint, 'processProjectedMatches(50)'), 'Leaderboard endpoint must use bounded projected-match catch-up.');
$assertTrue(!str_contains($endpoint, 'snapshotForProfile('), 'Leaderboard reads must not invoke the heavy Profile synchronization path.');
$assertTrue(str_contains($endpoint, 'new LeaderboardService'), 'Leaderboard endpoint must read through the single leaderboard owner.');
$assertTrue(!str_contains($endpoint, 'HiddenSkillService') && !str_contains($endpoint, 'skill_score'), 'Endpoint must not expose hidden skill.');

$assertTrue(str_contains($client, 'LEADERBOARD_URL'), 'Client must use one dedicated leaderboard endpoint.');
$assertTrue(str_contains($client, 'leaderboard: (gameType'), 'Client API must expose lazy per-game leaderboard loading.');

$assertTrue(!str_contains($profile, 'data-open-leaderboard'), 'Profile must not own the global leaderboard launcher after the Arena corrective.');
$assertTrue(!str_contains($profile, 'openLeaderboardSheet'), 'Profile must not own the global leaderboard sheet after the Arena corrective.');
$assertTrue(str_contains($profile, "profile.rating_title"), 'Profile must preserve personal visible rating.');

$assertTrue(str_contains($mainShell, "initTournamentsScreen"), 'Main shell must initialize the competition screen owner.');
$assertTrue(str_contains($mainShell, "tournaments-screen-v1.js?v=4&arena=final-table-polish-v1"), 'Main shell must load the final Arena table polish identity.');
$assertTrue(str_contains($arena, 'data-competition-mode="rating"'), 'Arena must expose Rating as a primary competition tab.');
$assertTrue(str_contains($arena, 'data-competition-mode="tournaments"'), 'Arena must expose Tournaments as a separate primary competition tab.');
$assertTrue(!str_contains($arena, "t('shell.tournaments_note')"), 'Arena heading must not repeat the redundant rating/tournaments subtitle.');
$assertTrue(str_contains($arena, 'data-tournaments-game'), 'Arena must own the per-game rating selector.');
$assertTrue(str_contains($arena, "addEventListener('wheel'"), 'Arena game selector must support mouse-wheel overflow.');
$assertTrue(str_contains($arena, "addEventListener('pointermove'"), 'Arena game selector must support mouse drag overflow.');
$assertTrue(str_contains($arena, 'data-tournaments-scroll'), 'Arena game selector must expose explicit left/right overflow controls.');
$assertTrue(str_contains($arena, 'tournaments-v2-table-head') && str_contains($arena, '>Игрок<') && str_contains($arena, '>Очки<'), 'Arena leaderboard must label rank, player and points columns.');
$assertTrue(str_contains($arena, 'tournaments-v2-scroll-icon') && str_contains($arena, '<svg'), 'Arena overflow controls must use centered SVG chevrons rather than font glyph baselines.');
$assertTrue(str_contains($mainCss, '#screen-tournaments .tournaments-v2-tabs-shell{') && str_contains($mainCss, 'display:block;'), 'Arena game strip must start at the board content edge instead of reserving a left arrow column.');
$assertTrue(str_contains($mainCss, '.tournaments-v2-scroll--left{left:0}') && str_contains($mainCss, '.tournaments-v2-scroll--right{right:0}'), 'Arena overflow arrows must be centered overlays rather than layout columns.');
$assertTrue(str_contains($mainCss, 'padding:1px 0 4px 0;') && str_contains($mainCss, 'scroll-padding-inline:34px;'), 'Arena game strip must align its final tab with the right content edge without a permanent arrow spacer.');
$assertTrue(str_contains($mainCss, '#screen-profile .profile-v2-rating-score>b{') && str_contains($mainCss, 'font-size:12px;'), 'Personal Profile rating numerals must stay compact for three-digit values.');
$assertTrue(str_contains($arena, 'CACHE_TTL_MS'), 'Arena must cache recent per-game boards instead of refetching every tab activation.');
$assertTrue(!str_contains($arena, 'leaderboard_preseason'), 'Arena must not render a PRESEASON badge.');
$assertTrue(!str_contains($arena, 'leaderboard_progress'), 'Arena must not render the rejected eligibility progress strip above the board.');
$assertTrue(!str_contains($arena, 'skill_score') && !str_contains($arena, 'hidden_skill'), 'Arena must not display hidden skill.');

$nav = $locale['nav'] ?? [];
$shell = $locale['shell'] ?? [];
$profileKeys = $locale['profile'] ?? [];
$assertTrue(($nav['tournaments'] ?? null) === 'Арена', 'Bottom navigation label must be Арена.');
$assertTrue(($shell['tournaments_title'] ?? null) === 'Соревнования', 'Arena page heading must be Соревнования.');
$assertTrue(($shell['competition_rating'] ?? null) === 'Рейтинг', 'Competition primary tab must include Рейтинг.');
$assertTrue(($shell['competition_tournaments'] ?? null) === 'Турниры', 'Competition primary tab must include Турниры.');
foreach ([
    'leaderboard_title',
    'leaderboard_open_note',
    'leaderboard_empty',
    'leaderboard_error',
] as $key) {
    $assertTrue(
        is_string($profileKeys[$key] ?? null) && trim((string)$profileKeys[$key]) !== '',
        'Russian locale must define profile.' . $key
    );
}
$assertTrue(
    !str_contains(strtolower((string)($profileKeys['leaderboard_open_note'] ?? '')), 'предсезон')
        && !str_contains(strtolower((string)($profileKeys['leaderboard_empty'] ?? '')), 'предсезон'),
    'Normal leaderboard copy must not surface PRESEASON.'
);

$assertTrue(str_contains($manifest, 'mvp20_3=leaderboards-v1'), 'Version manifest must preserve the accepted leaderboard runtime identity.');
$assertTrue(str_contains($manifest, 'arena=competition-rating-v4'), 'Version manifest must publish the final Arena edge-alignment cache identity.');
$assertTrue(str_contains($manifest, 'mvp20_1=visible-rating-v2'), 'MVP-20.1 visible-rating identity must remain frozen.');

fwrite(STDOUT, "Mvp20_3LeaderboardIntegrationContractTest: {$assertions} assertions passed\n");
