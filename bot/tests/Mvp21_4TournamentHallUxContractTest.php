<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$source = [
    'service'=>$read('bot/tournaments/TournamentHallService.php'),
    'endpoint'=>$read('bot/tournament-hall.php'),
    'client'=>$read('app/assets/js/api/client.js'),
    'screen'=>$read('app/assets/js/screens/tournaments-screen-v1.js'),
    'css'=>$read('app/assets/css/main.css'),
    'manifest'=>$read('app/runtime/client/version-manifest.php'),
];

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source['service'], 'HALL_OPEN_BEFORE_SECONDS = 900'),
    'Hall must open exactly 15 minutes before tournament start.');
$assert(str_contains($source['service'], 'HALL_PRESENCE_FRESHNESS_SECONDS = 8'),
    'Hall must reuse the accepted gameplay foreground freshness window.');
$assert(str_contains($source['service'], 'Tournament Hall доступен только зарегистрированным участникам.'),
    'Hall service must reject spectators/nonparticipants.');
$assert(str_contains($source['service'], 'random_int(')
        && str_contains($source['service'], 'bracket_generated_at_utc'),
    'Bracket must have a server-side random immutable generation owner.');
$assert(str_contains($source['service'], 'technical_loss_at_start'),
    'Absent participants must remain in the bracket with technical loss evidence.');
$assert(!str_contains($source['service'], 'ready_at_utc')
        && !str_contains($source['service'], 'countdown_started_at_utc'),
    'MVP-21.4 service must not implement MVP-21.5 ready/countdown state.');

$assert(str_contains($source['endpoint'], 'new PresenceService()')
        && str_contains($source['endpoint'], 'gameplaySnapshot'),
    'Hall endpoint must reuse the canonical PresenceService owner.');
$assert(str_contains($source['endpoint'], "['status','enter','heartbeat']"),
    'Hall endpoint must expose only the bounded participant Hall actions.');

foreach ([
    'tournamentHallStatus',
    'tournamentHallEnter',
    'tournamentHallHeartbeat',
] as $needle) {
    $assert(str_contains($source['client'], $needle), 'Hall client transport missing: ' . $needle);
}

foreach ([
    'data-tournament-hall-enter',
    'tournamentHallHeartbeat',
    'Tournament Hall',
    'Сетка ещё скрыта',
    'случайная сетка',
    'tournamentBracketMarkup',
] as $needle) {
    $assert(str_contains($source['screen'], $needle), 'Tournament Hall UI missing: ' . $needle);
}
$assert(!str_contains($source['screen'], 'data-tournament-ready')
        && !str_contains($source['client'], 'tournamentReady'),
    'MVP-21.4 must not expose a Ready action before MVP-21.5.');

foreach ([
    '.tournaments-v2-hall-gate',
    '.tournaments-v2-hall-roster-grid',
    '.tournaments-v2-bracket-grid',
    '.tournaments-v2-bracket-player.is-loss',
] as $needle) {
    $assert(str_contains($source['css'], $needle), 'Tournament Hall CSS missing: ' . $needle);
}

$assert(str_contains($source['manifest'], 'client.js?v=1142')
        && str_contains($source['manifest'], 'mvp21_4=tournament-hall-v1'),
    'Hall release must preserve the accepted API cache contract and add a fresh Hall identity.');
$assert(str_contains($source['manifest'], 'tournaments-screen-v1.js?v=16')
        && str_contains($source['manifest'], 'mvp21_4=tournament-hall-bracket-v1'),
    'Hall release must preserve the accepted Tournament screen base version and add a fresh Hall identity.');
$assert(str_contains($source['manifest'], 'main.css?v=198')
        && str_contains($source['manifest'], 'mvp21_4=tournament-hall-bracket-v1'),
    'Hall release must preserve accepted CSS base version and add a fresh Hall identity.');

if ($assertions < 20) throw new RuntimeException('MVP-21.4 UX contract is too shallow.');
fwrite(STDOUT, "Mvp21_4TournamentHallUxContractTest: {$assertions} assertions passed\n");
