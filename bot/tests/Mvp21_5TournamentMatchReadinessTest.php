<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/tournaments/TournamentRegistrationService.php';
require $root . '/tournaments/TournamentMatchReadinessService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_5TournamentMatchReadinessTest requires pdo_sqlite.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_tournaments (
    tournament_id TEXT PRIMARY KEY,
    active_slot TEXT NOT NULL,
    tournament_state TEXT NOT NULL,
    game_type TEXT NOT NULL,
    scheduled_start_at_utc TEXT NOT NULL,
    bracket_generated_at_utc TEXT NULL
)');
$db->execute('CREATE TABLE mgw_users (
    mgw_id TEXT PRIMARY KEY,
    nickname TEXT NULL,
    display_name TEXT NULL
)');
$db->execute('CREATE TABLE mgw_tournament_registrations (
    tournament_id TEXT NOT NULL,
    registration_id TEXT PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    account_ref TEXT NOT NULL,
    registration_state TEXT NOT NULL
)');
$db->execute('CREATE TABLE mgw_account_ownership (
    mgw_id TEXT NOT NULL,
    account_ref TEXT NOT NULL,
    ownership_status TEXT NOT NULL,
    legacy_user_id TEXT NOT NULL
)');
$db->execute('CREATE TABLE mgw_tournament_bracket_seeds (
    tournament_id TEXT NOT NULL,
    seed_no INTEGER NOT NULL,
    pair_no INTEGER NOT NULL,
    registration_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    present_at_start INTEGER NOT NULL,
    technical_loss_at_start INTEGER NOT NULL,
    PRIMARY KEY (tournament_id, seed_no)
)');

(require $root . '/database/migrations/20260921_0054_create_tournament_match_readiness.php')->up($db);

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(mb_strtolower($error->getMessage()), mb_strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$seedTournament = static function (
    PdoDatabaseConnection $db,
    string $tournamentId,
    string $start,
    array $players
): void {
    $db->execute(
        'INSERT INTO mgw_tournaments (
            tournament_id,active_slot,tournament_state,game_type,
            scheduled_start_at_utc,bracket_generated_at_utc
         ) VALUES (
            :tournament_id,:active_slot,:tournament_state,:game_type,
            :scheduled_start_at_utc,:bracket_generated_at_utc
         )',
        [
            'tournament_id'=>$tournamentId,
            'active_slot'=>TournamentRegistrationService::ACTIVE_SLOT,
            'tournament_state'=>TournamentRegistrationService::STATE_SCHEDULED,
            'game_type'=>'tictactoe',
            'scheduled_start_at_utc'=>$start,
            'bracket_generated_at_utc'=>$start,
        ]
    );

    foreach ($players as $index=>$player) {
        $db->execute(
            'INSERT INTO mgw_users (mgw_id,nickname,display_name)
             VALUES (:mgw_id,:nickname,:display_name)',
            [
                'mgw_id'=>$player['mgw_id'],
                'nickname'=>$player['nickname'],
                'display_name'=>$player['nickname'],
            ]
        );
        $db->execute(
            'INSERT INTO mgw_tournament_registrations (
                tournament_id,registration_id,mgw_id,account_ref,registration_state
             ) VALUES (
                :tournament_id,:registration_id,:mgw_id,:account_ref,:registration_state
             )',
            [
                'tournament_id'=>$tournamentId,
                'registration_id'=>$player['registration_id'],
                'mgw_id'=>$player['mgw_id'],
                'account_ref'=>$player['account_ref'],
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
            ]
        );
        $db->execute(
            'INSERT INTO mgw_account_ownership (
                mgw_id,account_ref,ownership_status,legacy_user_id
             ) VALUES (:mgw_id,:account_ref,:ownership_status,:legacy_user_id)',
            [
                'mgw_id'=>$player['mgw_id'],
                'account_ref'=>$player['account_ref'],
                'ownership_status'=>'active',
                'legacy_user_id'=>$player['legacy_user_id'],
            ]
        );
        $db->execute(
            'INSERT INTO mgw_tournament_bracket_seeds (
                tournament_id,seed_no,pair_no,registration_id,mgw_id,
                present_at_start,technical_loss_at_start
             ) VALUES (
                :tournament_id,:seed_no,:pair_no,:registration_id,:mgw_id,
                :present_at_start,:technical_loss_at_start
             )',
            [
                'tournament_id'=>$tournamentId,
                'seed_no'=>$index + 1,
                'pair_no'=>$player['pair_no'],
                'registration_id'=>$player['registration_id'],
                'mgw_id'=>$player['mgw_id'],
                'present_at_start'=>$player['present'] ? 1 : 0,
                'technical_loss_at_start'=>$player['present'] ? 0 : 1,
            ]
        );
    }
};

$players = [
    ['mgw_id'=>'MGW-AAAAAAAAAAAAAAAA','nickname'=>'A','registration_id'=>'reg-a','account_ref'=>'legacy:a','legacy_user_id'=>'legacy-a','pair_no'=>1,'present'=>true],
    ['mgw_id'=>'MGW-BBBBBBBBBBBBBBBB','nickname'=>'B','registration_id'=>'reg-b','account_ref'=>'legacy:b','legacy_user_id'=>'legacy-b','pair_no'=>1,'present'=>true],
    ['mgw_id'=>'MGW-CCCCCCCCCCCCCCCC','nickname'=>'C','registration_id'=>'reg-c','account_ref'=>'legacy:c','legacy_user_id'=>'legacy-c','pair_no'=>2,'present'=>true],
    ['mgw_id'=>'MGW-DDDDDDDDDDDDDDDD','nickname'=>'D','registration_id'=>'reg-d','account_ref'=>'legacy:d','legacy_user_id'=>'legacy-d','pair_no'=>2,'present'=>false],
];
$seedTournament($db, 'tour-ready', '2026-09-21 01:00:00.000000', $players);

$service = new TournamentMatchReadinessService($db);
$now = new DateTimeImmutable('2026-09-21T01:00:01Z');
$statusA = $service->status($players[0]['mgw_id'], $players[0]['account_ref'], $players[0]['legacy_user_id'], $now);
$assertTrue(is_array($statusA['match'] ?? null), 'Both-present pair must receive a readiness state.');
$assertSame(120, (int)$statusA['match']['ready_window_seconds'], 'Ready window must be exactly two minutes.');
$assertSame('2026-09-21 01:02:00.000000', $statusA['match']['readiness_deadline_at_utc'], 'Ready deadline must be anchored to T0 + 120 seconds.');
$assertSame(false, $statusA['match']['self_ready'], 'Player A must begin not ready.');
$assertSame(false, $statusA['match']['opponent_ready'], 'Opponent must begin not ready.');
$assertSame(true, $statusA['match']['can_ready'], 'Player A must be able to press Ready during the window.');

$technical = $service->status(
    $players[2]['mgw_id'],
    $players[2]['account_ref'],
    $players[2]['legacy_user_id'],
    $now
);
$assertSame(null, $technical['match'], 'Pair already decided by start technical loss must not enter readiness.');

$readyA = $service->markReady(
    $players[0]['mgw_id'],
    $players[0]['account_ref'],
    $players[0]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:20Z')
);
$assertSame(true, $readyA['match']['self_ready'], 'First Ready press must persist viewer readiness.');
$assertSame(false, $readyA['match']['both_ready'], 'One Ready press must not launch the pair.');
$firstReadyAt = (string)$db->fetchValue(
    'SELECT player_a_ready_at_utc FROM mgw_tournament_round_matches
     WHERE tournament_id=:tournament_id AND round_no=1 AND pair_no=1',
    ['tournament_id'=>'tour-ready']
);
$service->markReady(
    $players[0]['mgw_id'],
    $players[0]['account_ref'],
    $players[0]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:30Z')
);
$assertSame(
    $firstReadyAt,
    (string)$db->fetchValue(
        'SELECT player_a_ready_at_utc FROM mgw_tournament_round_matches
         WHERE tournament_id=:tournament_id AND round_no=1 AND pair_no=1',
        ['tournament_id'=>'tour-ready']
    ),
    'Repeated Ready from the same player must be idempotent.'
);

$readyB = $service->markReady(
    $players[1]['mgw_id'],
    $players[1]['account_ref'],
    $players[1]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:40Z')
);
$assertSame(true, $readyB['match']['both_ready'], 'Second Ready press must make the pair launchable.');
$assertSame(TournamentMatchReadinessService::STATE_READY, $readyB['match']['launch_state'], 'Both-ready state must be durable before game creation.');

$launch = $service->launchContext(
    $players[0]['mgw_id'],
    $players[0]['account_ref'],
    $players[0]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:41Z')
);
$assertTrue(is_array($launch), 'Both-ready pair must expose a launch context.');
$assertSame('tictactoe', $launch['game_type'], 'Launch context must preserve tournament game type.');
$assertSame(2, count($launch['players']), 'Launch context must contain exactly the paired runtime identities.');
$assertSame(
    $service->expectedGameId('tour-ready', 1, 1),
    $launch['game_id'],
    'Tournament game id must be deterministic for idempotent cross-store recovery.'
);

$service->attachGame('tour-ready', 1, 1, $launch['game_id'], new DateTimeImmutable('2026-09-21T01:00:42Z'));
$service->attachGame('tour-ready', 1, 1, $launch['game_id'], new DateTimeImmutable('2026-09-21T01:00:43Z'));
$launched = $service->status(
    $players[0]['mgw_id'],
    $players[0]['account_ref'],
    $players[0]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:44Z')
);
$assertSame(TournamentMatchReadinessService::STATE_LAUNCHED, $launched['match']['launch_state'], 'Attached game must persist launched state.');
$assertSame($launch['game_id'], $launched['match']['game_id'], 'Reload must preserve exact tournament game attachment.');
$assertThrows(
    fn() => $service->attachGame('tour-ready', 1, 1, 'game_other', new DateTimeImmutable('2026-09-21T01:00:45Z')),
    'already attached',
    'A pair must never attach to a second game.'
);

$expiredPlayers = [
    ['mgw_id'=>'MGW-EEEEEEEEEEEEEEEE','nickname'=>'E','registration_id'=>'reg-e','account_ref'=>'legacy:e','legacy_user_id'=>'legacy-e','pair_no'=>1,'present'=>true],
    ['mgw_id'=>'MGW-FFFFFFFFFFFFFFFF','nickname'=>'F','registration_id'=>'reg-f','account_ref'=>'legacy:f','legacy_user_id'=>'legacy-f','pair_no'=>1,'present'=>true],
];
$seedTournament($db, 'tour-expired', '2026-09-21 02:00:00.000000', $expiredPlayers);
$expired = $service->status(
    $expiredPlayers[0]['mgw_id'],
    $expiredPlayers[0]['account_ref'],
    $expiredPlayers[0]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T02:02:01Z')
);
$assertSame(TournamentMatchReadinessService::STATE_READINESS_EXPIRED, $expired['match']['launch_state'], 'Unready pair must durably expire after T0 + 120 seconds.');
$assertSame(false, $expired['match']['can_ready'], 'Expired readiness must not accept a late Ready press.');
$assertThrows(
    fn() => $service->markReady(
        $expiredPlayers[0]['mgw_id'],
        $expiredPlayers[0]['account_ref'],
        $expiredPlayers[0]['legacy_user_id'],
        new DateTimeImmutable('2026-09-21T02:02:01Z')
    ),
    'завершено',
    'Late Ready must fail closed without inventing a match result.'
);

$assertSame(
    2,
    (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_round_matches'),
    'Only both-present playable pairs must own readiness rows.'
);

if ($assertions < 20) {
    throw new RuntimeException('MVP-21.5 readiness test is too shallow: ' . $assertions);
}
fwrite(STDOUT, "Mvp21_5TournamentMatchReadinessTest: {$assertions} assertions passed\n");
