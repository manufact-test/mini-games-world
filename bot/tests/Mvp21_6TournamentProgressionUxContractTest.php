<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$screen = $read('app/assets/js/screens/tournaments-screen-v1.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$api = $read('bot/api.php');
$progression = $read('bot/tournaments/TournamentRoundProgressionService.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($screen, 'let tournamentProgressionSnapshot = null;'),
    'Tournament screen must own a separate progression snapshot.');
$assert(str_contains($screen, 'result?.progression'),
    'Tournament match heartbeat must hydrate progression payload.');
$assert(str_contains($screen, 'tournamentProgressionMarkup('),
    'Tournament screen must render progression states after the first Ready stage.');
$assert(str_contains($screen, 'data-tournament-progression-countdown'),
    'Tournament progression must expose a live wait countdown.');
$assert(str_contains($screen, 'data-progression-opens-at'),
    'Progression countdown must be anchored to the server-provided open instant.');
$assert(str_contains($screen, 'Ничья · переигровка начнётся через минуту. Стороны меняются.'),
    'Draw UX must clearly explain the one-minute replay and side swap.');
$assert(str_contains($screen, 'Раунд завершён · перерыв перед следующим матчем.'),
    'Round UX must clearly explain the inter-round break.');
$assert(str_contains($screen, "if (matchKind === 'final') stage = 'Финал';"),
    'Final must have an explicit tournament label.');
$assert(str_contains($screen, "else if (matchKind === 'third_place') stage = 'Матч за 3-е место';"),
    'Third-place match must have an explicit tournament label.');
$assert(str_contains($screen, 'Ваш матч завершён · ждём остальные матчи раунда.'),
    'A player who finishes early must be told that the round waits for all matches.');
$assert(str_contains($screen, 'Все матчи турнира завершены.'),
    'Completed tournament must expose a terminal progression message.');
$assert(str_contains($screen, 'formatReadyCountdown(opensAt.getTime() - Date.now())'),
    'Progression waits must reuse the compact mm:ss countdown.');
$assert(str_contains($screen, 'await refreshTournamentMatchState()'),
    'Existing Hall heartbeat must remain the automatic progression polling owner.');

$assert(str_contains($manifest, 'tournaments-screen-v1.js?v=20')
        && str_contains($manifest, 'mvp21_6=rounds-replays-v1')
        && str_contains($manifest, 'mvp21_5=manual-acceptance-fixes-v3'),
    'Tournament screen must preserve MVP-21.6 while publishing the later MVP-21.5 manual-acceptance corrective identity.');

$assert(str_contains($api, "'progression'=>\$progressionSnapshot"),
    'Tournament API must expose the durable progression snapshot.');
$assert(str_contains($progression, 'ROUND_BREAK_SECONDS = 300')
        && str_contains($progression, 'DRAW_REPLAY_WAIT_SECONDS = 60'),
    'UI countdown semantics must be backed by canonical server timings.');
$assert(str_contains($progression, "MATCH_FINAL = 'final'")
        && str_contains($progression, "MATCH_THIRD_PLACE = 'third_place'"),
    'UI stage names must map to explicit durable server match kinds.');

if ($assertions < 17) throw new RuntimeException('MVP-21.6 progression UX contract is too shallow: ' . $assertions);
fwrite(STDOUT, "Mvp21_6TournamentProgressionUxContractTest: {$assertions} assertions passed\n");
