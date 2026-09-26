<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database/DatabaseConnectionInterface.php';
require dirname(__DIR__) . '/database/DatabaseExceptionClassifier.php';
require dirname(__DIR__) . '/database/PdoDatabaseConnection.php';
require dirname(__DIR__) . '/analytics/ProductEconomyAnalyticsService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$db = new PdoDatabaseConnection($pdo);

$db->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id TEXT PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'active',
    created_at_utc TEXT NOT NULL,
    last_seen_at_utc TEXT NULL
)
SQL);
$db->execute('CREATE TABLE mgw_identities (mgw_id TEXT NOT NULL, provider TEXT NOT NULL)');
$db->execute(<<<'SQL'
CREATE TABLE mgw_account_ownership (
    account_ref TEXT NOT NULL PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    legacy_user_id TEXT NOT NULL,
    ownership_status TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_ref TEXT NOT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_matches (
    match_id TEXT PRIMARY KEY,
    game_type TEXT NOT NULL,
    status TEXT NOT NULL,
    finished_at_utc TEXT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_match_players (
    match_id TEXT NOT NULL,
    seat INTEGER NOT NULL,
    mgw_id TEXT NULL,
    player_type TEXT NOT NULL,
    PRIMARY KEY (match_id, seat)
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_cosmetic_purchases (
    purchase_id TEXT PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    offer_id TEXT NOT NULL,
    price_coins INTEGER NOT NULL,
    purchase_status TEXT NOT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_tournaments (
    tournament_id TEXT PRIMARY KEY,
    active_slot TEXT NULL,
    title TEXT NOT NULL,
    game_type TEXT NOT NULL,
    capacity INTEGER NOT NULL,
    tournament_state TEXT NOT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_tournament_registrations (
    registration_id TEXT PRIMARY KEY,
    tournament_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    registration_state TEXT NOT NULL,
    registered_at_utc TEXT NOT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_tournament_results (
    tournament_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_ledger_entries (
    entry_id TEXT PRIMARY KEY,
    account_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    asset_code TEXT NOT NULL,
    available_delta INTEGER NOT NULL,
    reserved_delta INTEGER NOT NULL,
    category TEXT NOT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_balances (
    balance_id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    asset_code TEXT NOT NULL,
    available_amount INTEGER NOT NULL,
    reserved_amount INTEGER NOT NULL
)
SQL);

$users = [
    ['REAL1','active','2026-09-01 08:00:00.000000','2026-09-26 09:00:00.000000'],
    ['REAL2','active','2026-09-25 10:00:00.000000','2026-09-26 11:00:00.000000'],
    ['REAL3','active','2026-09-26 08:00:00.000000','2026-09-26 09:30:00.000000'],
    ['DEV1','active','2026-09-01 08:00:00.000000','2026-09-26 10:00:00.000000'],
    ['FIXRET','staging_fixture_retired','2026-09-01 08:00:00.000000','2026-09-26 10:00:00.000000'],
    ['FIXV2','active','2026-09-01 08:00:00.000000','2026-09-26 10:00:00.000000'],
    ['FIXOLD','active','2026-09-01 08:00:00.000000','2026-09-26 10:00:00.000000'],
];
foreach ($users as $row) {
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,created_at_utc,last_seen_at_utc)
         VALUES (:id,:status,:created,:seen)',
        ['id'=>$row[0],'status'=>$row[1],'created'=>$row[2],'seen'=>$row[3]]
    );
}
$db->execute("INSERT INTO mgw_identities (mgw_id,provider) VALUES ('DEV1','development')");
$db->execute(<<<'SQL'
INSERT INTO mgw_account_ownership
(account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref)
VALUES
('acc-real-1','REAL1','real_1','active','runtime_identity','telegram:1'),
('acc-real-2','REAL2','real_2','active','runtime_identity','telegram:2'),
('acc-real-3','REAL3','real_3','active','runtime_identity','telegram:3'),
('acc-fix-v2','FIXV2','stg_tour_v2_abcdef123456','active','runtime_identity','development:stg_tour_v2_abcdef123456'),
('acc-fix-old','FIXOLD','stg_tour_abcdef123456','active','staging_fixture_repair','manual-acceptance:test')
SQL);

$db->execute("INSERT INTO mgw_matches VALUES ('m1','tictactoe','finished','2026-09-25 12:00:00.000000')");
$db->execute("INSERT INTO mgw_match_players VALUES ('m1',1,'REAL1','human'),('m1',2,'REAL2','human')");
$db->execute("INSERT INTO mgw_matches VALUES ('m2','chess','finished','2026-09-25 13:00:00.000000')");
$db->execute("INSERT INTO mgw_match_players VALUES ('m2',1,'REAL1','human'),('m2',2,NULL,'bot')");
$db->execute("INSERT INTO mgw_matches VALUES ('m3','domino','finished','2026-09-25 14:00:00.000000')");
$db->execute("INSERT INTO mgw_match_players VALUES ('m3',1,'FIXV2','human'),('m3',2,'FIXRET','human')");

$db->execute("INSERT INTO mgw_cosmetic_purchases VALUES ('p1','REAL1','avatar_gold',100,'completed','2026-09-25 12:00:00.000000')");
$db->execute("INSERT INTO mgw_cosmetic_purchases VALUES ('p2','FIXV2','avatar_gold',500,'completed','2026-09-25 12:00:00.000000')");
$db->execute("INSERT INTO mgw_cosmetic_purchases VALUES ('p3','REAL1','frame_blue',50,'completed','2026-08-01 12:00:00.000000')");

$db->execute("INSERT INTO mgw_tournaments VALUES ('t1','official','Тест турнира','chess',8,'registration_open','2026-09-24 12:00:00.000000')");
$db->execute("INSERT INTO mgw_tournament_registrations VALUES ('r1','t1','REAL1','registered','2026-09-25 12:00:00.000000')");
$db->execute("INSERT INTO mgw_tournament_registrations VALUES ('r2','t1','FIXV2','registered','2026-09-25 12:00:00.000000')");
$db->execute("INSERT INTO mgw_tournament_results VALUES ('t1','REAL1')");
$db->execute("INSERT INTO mgw_tournament_results VALUES ('t1','FIXV2')");

$db->execute("INSERT INTO mgw_ledger_entries VALUES ('l1','acc-real-1','REAL1','mgw_coin',500,0,'weekly_bonus','2026-09-25 10:00:00.000000')");
$db->execute("INSERT INTO mgw_ledger_entries VALUES ('l2','acc-real-1','REAL1','mgw_coin',-100,0,'cosmetic_purchase','2026-09-25 11:00:00.000000')");
$db->execute("INSERT INTO mgw_ledger_entries VALUES ('l3','acc-real-1','REAL1','mgw_coin',-50,50,'match_entry_reserve','2026-09-25 11:30:00.000000')");
$db->execute("INSERT INTO mgw_ledger_entries VALUES ('l4','acc-real-2',NULL,'mgw_coin',200,0,'weekly_bonus','2026-09-25 12:30:00.000000')");
$db->execute("INSERT INTO mgw_ledger_entries VALUES ('l5','acc-fix-v2','FIXV2','mgw_coin',10000,0,'weekly_bonus','2026-09-25 13:00:00.000000')");

$db->execute("INSERT INTO mgw_balances (account_ref,mgw_id,asset_code,available_amount,reserved_amount) VALUES ('acc-real-1','REAL1','mgw_coin',1000,50)");
$db->execute("INSERT INTO mgw_balances (account_ref,mgw_id,asset_code,available_amount,reserved_amount) VALUES ('acc-real-2',NULL,'mgw_coin',300,0)");
$db->execute("INSERT INTO mgw_balances (account_ref,mgw_id,asset_code,available_amount,reserved_amount) VALUES ('acc-fix-v2','FIXV2','mgw_coin',9999,0)");

$telemetry = [
    'matchmaking_queue_depth'=>2,
    'matchmaking_wait_ms'=>5000,
    'matchmaking_human_match_total'=>9,
    'matchmaking_bot_match_total'=>3,
    'matchmaking_skill_match_total'=>4,
    'matchmaking_skill_exact_band_total'=>3,
    'matchmaking_skill_widened_match_total'=>1,
    'matchmaking_skill_wait_ms_sum'=>20000,
    'matchmaking_skill_wait_ms_max'=>9000,
    'matchmaking_duplicate_match_prevented_total'=>2,
];
$reconciliation = [
    'ok'=>false,
    'phase'=>'post_cutover',
    'planned_delta_count'=>1,
    'integrity_failure_count'=>0,
    'active_reservation_count'=>2,
    'ledger_entry_count'=>5,
    'blockers'=>['Canonical runtime balance differs from mgw_coin and requires synchronization.'],
];

$now = new DateTimeImmutable('2026-09-26T12:00:00+00:00');
$snapshot = (new ProductEconomyAnalyticsService($db))->snapshot($telemetry, $reconciliation, $now);

$assertSame(3, $snapshot['users']['real_total'], 'Synthetic and development users must not inflate real-user analytics');
$assertSame(3, $snapshot['users']['active_24h'], '24h activity must use real-account last_seen only');
$assertSame(1, $snapshot['retention']['return_after_7d']['eligible_accounts'], 'Only mature real cohorts belong in >=7d return denominator');
$assertSame(1, $snapshot['retention']['return_after_7d']['returned_accounts'], 'Real account active after seven days must count as returned');

$assertSame(2, $snapshot['games']['finished_30d'], 'Synthetic-only matches must not count as product matches');
$assertSame(1, $snapshot['games']['pvp_30d'], 'Real PvP match must be classified');
$assertSame(1, $snapshot['games']['vs_bot_30d'], 'Real-vs-bot match must be classified');
$assertSame(1, $snapshot['games']['by_game_30d']['tictactoe'], 'Per-game analytics must include real TTT match');
$assertSame(0, $snapshot['games']['by_game_30d']['domino'], 'Synthetic-only Domino match must be excluded');

$assertSame(5000, $snapshot['matchmaking']['skill_wait_avg_ms'], 'Existing aggregate matchmaking wait must be reused rather than reconstructed');
$assertSame(1, $snapshot['purchases']['completed_30d'], 'Synthetic fixture purchase must not count');
$assertSame(100, $snapshot['purchases']['coins_spent_30d'], 'Purchase coin spend must use real buyers');
$assertSame(false, $snapshot['ads']['available'], 'Ads must explicitly report unavailable telemetry');
$assertSame(false, $snapshot['ads']['history_reconstructable'], 'Missing ad history must never be invented');

$assertSame(1, $snapshot['tournaments']['with_real_participants'], 'Tournament product count must require a real participant');
$assertSame(1, $snapshot['tournaments']['real_registrations_all_time'], 'Fixture tournament registration must be excluded');
$assertSame(1, $snapshot['tournaments']['completed_with_real_participants'], 'Real completed tournament result must count once');
$assertSame(1, $snapshot['tournaments']['current']['real_registered_count'], 'Current tournament count must exclude fixtures');

$assertSame(700, $snapshot['coin_flow']['sources_30d'], 'Coin sources must include direct and ownership-resolved real accounts');
$assertSame(100, $snapshot['coin_flow']['sinks_30d'], 'Internal reserve transfer must not be misreported as a coin sink');
$assertSame(600, $snapshot['coin_flow']['net_30d'], 'Coin net must use total available+reserved delta');
$assertSame(1300, $snapshot['coin_flow']['available_now'], 'Current available balance must exclude fixtures');
$assertSame(50, $snapshot['coin_flow']['reserved_now'], 'Current reserved balance must preserve real reservations');

$assertSame(false, $snapshot['reconciliation']['ok'], 'Canonical reconciliation warning must surface');
$assertSame(1, $snapshot['reconciliation']['warning_count'], 'Reconciliation blockers must stay visible');
$assert(
    str_contains($snapshot['coverage']['retention'], 'не классический D1/D7'),
    'Retention coverage must state the historical limitation explicitly'
);

fwrite(STDOUT, "Mvp22_6ProductEconomyAnalyticsTest: {$assertions} assertions passed\n");
