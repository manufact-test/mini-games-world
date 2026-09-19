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

$bootstrap = $read('bot/core/bootstrap.php');
$api = $read('bot/api.php');
$queue = $read('bot/services/MatchmakingQueue.php');
$baseRuntime = $read('bot/services/GameRuntimeService.php');
$specialRuntime = $read('bot/services/ChessRuntimeService.php');
$service = $read('bot/ratings/HiddenSkillService.php');
$bridge = $read('bot/ratings/HiddenSkillRuntimeBridge.php');
$profileApi = $read('bot/profile-v2.php');
$profileClient = $read('app/assets/js/screens/profile-screen-v110.js');

$assertTrue(str_contains($bootstrap, "../ratings/HiddenSkillService.php"), 'Bootstrap must load one hidden-skill owner.');
$assertTrue(str_contains($bootstrap, "../ratings/HiddenSkillRuntimeBridge.php"), 'Bootstrap must load the hidden-skill runtime bridge.');
$ratingPos = strpos($bootstrap, '$runtimeRatingBridge->processProjectedMatches();');
$skillPos = strpos($bootstrap, '$runtimeHiddenSkillBridge->processProjectedMatches();');
$assertTrue(is_int($ratingPos) && is_int($skillPos) && $skillPos > $ratingPos, 'Hidden skill must consume normalized terminal results after the rating/realtime projection chain.');

$assertTrue(str_contains($api, '$runtimeHiddenSkillBridge->skillBandForUser('), 'start_search must obtain its skill band from the server hidden-skill owner.');
$assertTrue(str_contains($api, '$gameCatalog->normalizeGameType('), 'Hidden skill lookup must use canonical normalized game type.');
$assertTrue(str_contains($api, '$skillBand'), 'Server-assigned skill band must be passed into the game runtime.');
$assertTrue(!str_contains($api, "$payload['skillBand']") && !str_contains($api, "$payload['skill_band']"), 'Client payload must never choose hidden skill or matchmaking band.');

$assertTrue(str_contains($specialRuntime, '?string $skillBand = null'), 'Chess/Go/Domino queue path must accept the same server-assigned hidden band.');
$assertTrue(str_contains($specialRuntime, '$this->base->startSearch($db, $user, $room, $bet, $boardSize, $gameType, $skillBand)'), 'Special queues must delegate the same hidden band to base runtime.');
$assertTrue(str_contains($baseRuntime, 'observeSkillMatchQuality($db, $candidate, $skillBand)'), 'Base queues must emit skill-quality telemetry for human matches.');
$assertTrue(str_contains($specialRuntime, 'observeSkillMatchQuality($db, $candidate, $skillBand)'), 'Special queues must emit the same skill-quality telemetry.');

$assertTrue(str_contains($queue, 'public const SKILL_WIDEN_STEP_SEC = 2;'), 'Accepted two-second progressive widening step must remain unchanged.');
$assertTrue(str_contains($queue, 'public const MAX_SKILL_BAND_DISTANCE = 3;'), 'Accepted hard maximum of three ordinal bands must remain unchanged.');
$assertTrue(str_contains($queue, 'observeSkillMatchQuality'), 'Queue owner must record match quality telemetry.');
$assertTrue(str_contains($queue, 'matchmaking_skill_exact_band_total'), 'Exact-band matches must be measurable.');
$assertTrue(str_contains($queue, 'matchmaking_skill_widened_match_total'), 'Widened-band matches must be measurable.');
$assertTrue(!preg_match('/95\s*%|5\s*%|random.*skill|skill.*random/i', $queue), 'Superseded fixed/random skill matching must not return.');

$assertTrue(str_contains($service, 'public const BASE_SKILL = 1500;'), 'Hidden model must have one deterministic neutral baseline.');
$assertTrue(str_contains($service, 'public const K_FACTOR = 32;'), 'Hidden model update factor must be explicit and testable.');
$assertTrue(str_contains($service, 'public const BAND_WIDTH = 100;'), 'Hidden score-to-band mapping must be deterministic.');
$assertTrue(str_contains($service, 'softAdjustScore'), 'Season transition must have an explicit soft-adjustment owner.');
$assertTrue(str_contains($service, "player_type'] ?? 'human'"), 'Hidden model must inspect canonical player type.');
$assertTrue(str_contains($service, "'bot_game'"), 'Bot results must be explicitly excluded.');
$assertTrue(str_contains($service, "'technical_result'"), 'Technical results must be explicitly excluded from skill changes.');
$assertTrue(str_contains($service, 'INSERT IGNORE INTO mgw_hidden_skill_outcomes'), 'MySQL hidden-skill result processing must be idempotent by match.');
$assertTrue(str_contains($service, 'INSERT OR IGNORE INTO mgw_hidden_skill_outcomes'), 'SQLite hidden-skill result processing must share the same idempotent match key.');

$assertTrue(!str_contains($profileApi, 'HiddenSkillService') && !str_contains($profileApi, 'hidden_skill'), 'Profile API must not expose hidden skill.');
$assertTrue(!str_contains($profileClient, 'hidden_skill') && !str_contains($profileClient, 'skill_score'), 'Profile client must not display hidden skill.');
$assertTrue(!str_contains($bridge, 'normalizeApiData') && !str_contains($bridge, 'snapshotForProfile'), 'Hidden-skill bridge must not become a user-facing data filter.');

fwrite(STDOUT, "Mvp20_2HiddenSkillIntegrationContractTest: {$assertions} assertions passed\n");
