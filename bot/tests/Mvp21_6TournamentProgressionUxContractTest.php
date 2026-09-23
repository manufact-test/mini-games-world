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
$assert(str_contains($screen, 'startTournamentRenderedCountdownTicker(')
        && str_contains($screen, 'stopTournamentRenderedCountdownTicker();')
        && str_contains($screen, 'window.setInterval(updateCountdown, 1000)')
        && str_contains($screen, 'tournamentCountdownTimer = timerId;'),
    'Started Tournament Hall must keep exactly one owned round/replay countdown ticker.');
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
$assert(str_contains($screen, 'tournamentRoundSectionsMarkup(')
        && str_contains($screen, 'data-tournament-round-archive=')
        && str_contains($screen, "'Полуфинал'")
        && str_contains($screen, "'Финальный раунд'")
        && str_contains($screen, 'Вы выбыли из турнира · сетка уже перешла в следующий раунд.'),
    'Every materialized round must remain available as its own collapsible bracket section.');
$assert(str_contains($screen, 'tournamentRoundArchiveOpen')
        && str_contains($screen, 'tournamentRoundArchiveScrollTop')
        && str_contains($screen, 'captureTournamentArchiveViewport(body)')
        && str_contains($screen, 'restoreTournamentArchiveViewport(body)'),
    'Per-round archive open state and inner scroll position must survive Hall heartbeat updates.');
$assert(str_contains($progression, "'rounds'=>\$rounds")
        && str_contains($progression, "'first_round_archive'=>\$firstRoundArchive")
        && str_contains($progression, "'winner'=>\$completed && \$winner !== '' && \$winner === \$participantId")
        && str_contains($screen, "status = winner ? 'прошёл дальше' : 'выбыл'")
        && str_contains($screen, "outcome = 'Матч завершён · победитель не назначен.'"),
    'Durable round history must preserve winners, losers and no-winner technical outcomes for every stage.');
$assert(str_contains($screen, 'function tournamentLiveRenderFingerprint()')
        && substr_count($screen, 'const renderBefore = tournamentLiveRenderFingerprint();') >= 2
        && substr_count($screen, 'if (tournamentLiveRenderFingerprint() !== renderBefore)') >= 2,
    'Long-running Hall polling must not rebuild the full tournament DOM when authoritative state is unchanged.');
$assert(str_contains($screen, 'Все матчи турнира завершены.'),
    'Completed tournament must expose a terminal progression message.');
$assert(str_contains($screen, 'formatReadyCountdown(opensAt.getTime() - Date.now())'),
    'Progression waits must reuse the compact mm:ss countdown.');
$assert(str_contains($screen, 'await refreshTournamentMatchState()'),
    'Existing Hall heartbeat must remain the automatic progression polling owner.');
$assert(str_contains($screen, 'synchronizeTournamentTerminalProgression')
        && str_contains($screen, "document.addEventListener('mgw:game-finished'")
        && str_contains($screen, 'stopTournamentLaunchWatch();')
        && str_contains($screen, 'stopTournamentStartSync();'),
    'Terminal tournament game must pre-sync durable progression and stop stale launch owners.');

$assert(str_contains($manifest, 'tournaments-screen-v1.js?v=31')
        && str_contains($manifest, 'mvp21_manual=acceptance-corrective-v1')
        && str_contains($manifest, 'mvp21_6=terminal-return-preserve-v5')
        && str_contains($manifest, 'mvp21_8=corrective-v8')
        && str_contains($manifest, 'mvp21_6=active-round-grid-v1')
        && str_contains($manifest, 'archive=heartbeat-open-v1')
        && str_contains($manifest, 'mvp21_6=smooth-round-countdown-v1')
        && str_contains($manifest, 'archive=first-round-results-v1')
        && str_contains($manifest, 'archive=per-round-v1')
        && str_contains($manifest, 'desktop=endurance-v1'),
    'Tournament screen must publish the per-round archive and desktop endurance identity.');

$assert(str_contains($api, "'progression'=>\$progressionSnapshot"),
    'Tournament API must expose the durable progression snapshot.');
$assert(str_contains($progression, 'ROUND_BREAK_SECONDS = 180')
        && str_contains($progression, 'DRAW_REPLAY_WAIT_SECONDS = 60'),
    'UI countdown semantics must be backed by canonical server timings.');
$assert(str_contains($progression, "MATCH_FINAL = 'final'")
        && str_contains($progression, "MATCH_THIRD_PLACE = 'third_place'"),
    'UI stage names must map to explicit durable server match kinds.');
$assert(str_contains($progression, 'm.player_a_mgw_id=:player_a_mgw_id OR m.player_b_mgw_id=:player_b_mgw_id')
        && str_contains($progression, "'player_a_mgw_id'=>\$mgwId")
        && str_contains($progression, "'player_b_mgw_id'=>\$mgwId")
        && !str_contains($progression, 'm.player_a_mgw_id=:mgw_id OR m.player_b_mgw_id=:mgw_id'),
    'MySQL PDO participant lookup must never reuse the same named placeholder twice in the OR predicate.');

if ($assertions < 24) throw new RuntimeException('MVP-21.6 progression UX contract is too shallow: ' . $assertions);
fwrite(STDOUT, "Mvp21_6TournamentProgressionUxContractTest: {$assertions} assertions passed\n");
