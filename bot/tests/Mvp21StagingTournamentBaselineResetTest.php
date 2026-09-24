<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../ledger/LedgerIntegrity.php';
require_once __DIR__ . '/../ledger/LedgerWriteService.php';
require_once __DIR__ . '/../storage/RuntimeStorageRouter.php';
require_once __DIR__ . '/../tournaments/TournamentRegistrationService.php';
require_once __DIR__ . '/../tournaments/StagingTournamentBaselineResetService.php';

function baseline_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mysqlDsn = trim((string)getenv('MGW_MVP21_BASELINE_MYSQL_DSN'));
if ($mysqlDsn !== '') {
    $pdo = new PDO(
        $mysqlDsn,
        (string)getenv('MGW_MVP21_BASELINE_MYSQL_USER'),
        (string)getenv('MGW_MVP21_BASELINE_MYSQL_PASS'),
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]
    );
} else {
    $pdo = new PDO('sqlite::memory:');
}
$database = new PdoDatabaseConnection($pdo);
if ($database->driver() === 'sqlite') {
    $database->execute('PRAGMA foreign_keys = ON');
}

foreach ([
    '20260716_0002_create_accounts_identities_sessions.php',
    '20260716_0003_create_realtime_matches_invites_notifications.php',
    '20260717_0005_create_balances_ledger_reservations.php',
    '20260718_0007_create_account_ownership.php',
    '20260920_0049_create_official_tournaments.php',
    '20260920_0050_add_tournament_rules_consent.php',
    '20260921_0052_add_tournament_schedule.php',
    '20260922_0057_create_tournament_results_rewards.php',
] as $migrationFile) {
    $migration = require __DIR__ . '/../database/migrations/' . $migrationFile;
    $migration->up($database);
}

$now = '2026-09-24 16:30:00.000000';
$users = [
    ['MGW-TEST-CHAMPION', 'player_champion'],
    ['MGW-TEST-LOSER', 'player_loser'],
    ['MGW-TEST-ACTIVE', 'player_active'],
];
foreach ($users as [$mgwId, $legacyId]) {
    $database->execute(
        'INSERT INTO mgw_users (
            mgw_id,status,display_name,created_at_utc,updated_at_utc,last_seen_at_utc
         ) VALUES (:mgw_id,:status,:display_name,:created,:updated,:seen)',
        [
            'mgw_id'=>$mgwId,
            'status'=>'active',
            'display_name'=>$legacyId,
            'created'=>$now,
            'updated'=>$now,
            'seen'=>$now,
        ]
    );
    $database->execute(
        'INSERT INTO mgw_account_ownership (
            account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref,
            source_sha256,created_at_utc,verified_at_utc
         ) VALUES (
            :account_ref,:mgw_id,:legacy_user_id,:ownership_status,:source_type,:source_ref,
            :source_sha256,:created_at,:verified_at
         )',
        [
            'account_ref'=>'legacy:' . $legacyId,
            'mgw_id'=>$mgwId,
            'legacy_user_id'=>$legacyId,
            'ownership_status'=>'active',
            'source_type'=>'runtime_identity',
            'source_ref'=>'development:' . $legacyId,
            'source_sha256'=>hash('sha256', $legacyId),
            'created_at'=>$now,
            'verified_at'=>$now,
        ]
    );
}

$ledger = new LedgerWriteService($database);
foreach ($users as [$mgwId, $legacyId]) {
    $ledger->postAvailableDelta([
        'operation_key'=>'fixture:grant:' . $legacyId,
        'account_ref'=>'legacy:' . $legacyId,
        'mgw_id'=>$mgwId,
        'legacy_user_id'=>$legacyId,
        'asset_code'=>'coins',
        'available_delta'=>100000,
        'category'=>'staging_test_grant',
        'source_type'=>'staging_test',
        'source_ref'=>'baseline_fixture',
    ]);
}

$rewardSnapshot = json_encode(['version'=>'test-v1'], JSON_THROW_ON_ERROR);
$database->execute(
    'INSERT INTO mgw_tournaments (
        tournament_id,active_slot,title,game_type,capacity,entry_fee_amount,entry_asset_code,
        reward_snapshot_json,tournament_state,created_by_ref,opened_by_ref,
        created_at_utc,registration_opened_at_utc,updated_at_utc,
        registration_closed_at_utc,registration_closed_reason,
        scheduled_start_at_utc,scheduled_by_ref,scheduled_at_utc
     ) VALUES (
        :id,NULL,:title,:game,8,50000,:asset,:reward,:state,:actor,:actor,
        :created,:opened,:updated,:closed,:reason,NULL,NULL,NULL
     )',
    [
        'id'=>'tour_completed',
        'title'=>'Test completed tournament',
        'game'=>'tictactoe',
        'asset'=>'coins',
        'reward'=>$rewardSnapshot,
        'state'=>'completed',
        'actor'=>'test',
        'created'=>$now,
        'opened'=>$now,
        'updated'=>$now,
        'closed'=>$now,
        'reason'=>'full',
    ]
);
$database->execute(
    'INSERT INTO mgw_tournaments (
        tournament_id,active_slot,title,game_type,capacity,entry_fee_amount,entry_asset_code,
        reward_snapshot_json,tournament_state,created_by_ref,opened_by_ref,
        created_at_utc,registration_opened_at_utc,updated_at_utc,
        registration_closed_at_utc,registration_closed_reason,
        scheduled_start_at_utc,scheduled_by_ref,scheduled_at_utc
     ) VALUES (
        :id,:slot,:title,:game,8,50000,:asset,:reward,:state,:actor,:actor,
        :created,:opened,:updated,NULL,NULL,NULL,NULL,NULL
     )',
    [
        'id'=>'tour_active',
        'slot'=>'official_current',
        'title'=>'Test active tournament',
        'game'=>'tictactoe',
        'asset'=>'coins',
        'reward'=>$rewardSnapshot,
        'state'=>TournamentRegistrationService::STATE_REGISTRATION_OPEN,
        'actor'=>'test',
        'created'=>$now,
        'opened'=>$now,
        'updated'=>$now,
    ]
);

$register = static function (
    DatabaseConnectionInterface $database,
    LedgerWriteService $ledger,
    string $tournamentId,
    string $mgwId,
    string $legacyId,
    string $registrationId,
    bool $settle,
    int $payout,
    ?int $placement
) use ($now): string {
    $reservation = $ledger->createReservation([
        'operation_key'=>'fixture:entry:' . $registrationId,
        'account_ref'=>'legacy:' . $legacyId,
        'mgw_id'=>$mgwId,
        'legacy_user_id'=>$legacyId,
        'asset_code'=>'coins',
        'amount'=>50000,
        'source_type'=>'official_tournament',
        'source_ref'=>$tournamentId,
    ]);
    $reservationId = (string)$reservation['reservation_id'];

    $database->execute(
        'INSERT INTO mgw_tournament_registrations (
            registration_id,tournament_id,mgw_id,account_ref,attempt_no,registration_state,
            reservation_id,registered_at_utc,withdrawn_at_utc,updated_at_utc,
            rules_version,rules_snapshot_json,rules_snapshot_sha256,rules_accepted_at_utc
         ) VALUES (
            :registration_id,:tournament_id,:mgw_id,:account_ref,1,:state,
            :reservation_id,:registered,NULL,:updated,
            :rules_version,:rules_json,:rules_hash,:rules_accepted
         )',
        [
            'registration_id'=>$registrationId,
            'tournament_id'=>$tournamentId,
            'mgw_id'=>$mgwId,
            'account_ref'=>'legacy:' . $legacyId,
            'state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
            'reservation_id'=>$reservationId,
            'registered'=>$now,
            'updated'=>$now,
            'rules_version'=>'test-v1',
            'rules_json'=>'{}',
            'rules_hash'=>hash('sha256', '{}'),
            'rules_accepted'=>$now,
        ]
    );

    if (!$settle) return $reservationId;

    $ledger->consumeReservation([
        'operation_key'=>'fixture:consume:' . $registrationId,
        'reservation_id'=>$reservationId,
        'metadata'=>['purpose'=>'test_tournament_settlement'],
    ]);
    if ($payout > 0) {
        $ledger->postAvailableDelta([
            'operation_key'=>'fixture:payout:' . $registrationId,
            'account_ref'=>'legacy:' . $legacyId,
            'mgw_id'=>$mgwId,
            'legacy_user_id'=>$legacyId,
            'asset_code'=>'coins',
            'available_delta'=>$payout,
            'category'=>'tournament_reward',
            'source_type'=>'official_tournament',
            'source_ref'=>$tournamentId,
        ]);
    }

    $database->execute(
        'INSERT INTO mgw_tournament_results (
            tournament_id,mgw_id,registration_id,account_ref,placement,result_code,
            reward_snapshot_version,reward_snapshot_sha256,entry_amount,entry_return_amount,
            prize_amount,payout_amount,reward_eligible,reservation_id,settled_at_utc,
            created_at_utc,updated_at_utc
         ) VALUES (
            :tournament_id,:mgw_id,:registration_id,:account_ref,:placement,:result_code,
            :reward_version,:reward_hash,50000,:entry_return,:prize,:payout,1,:reservation_id,
            :settled,:created,:updated
         )',
        [
            'tournament_id'=>$tournamentId,
            'mgw_id'=>$mgwId,
            'registration_id'=>$registrationId,
            'account_ref'=>'legacy:' . $legacyId,
            'placement'=>$placement,
            'result_code'=>$placement === 1 ? 'champion' : 'eliminated',
            'reward_version'=>'test-v1',
            'reward_hash'=>hash('sha256', 'test-v1'),
            'entry_return'=>$placement === 1 ? 50000 : 0,
            'prize'=>$placement === 1 ? 150000 : 0,
            'payout'=>$payout,
            'reservation_id'=>$reservationId,
            'settled'=>$now,
            'created'=>$now,
            'updated'=>$now,
        ]
    );
    return $reservationId;
};

$register($database, $ledger, 'tour_completed', 'MGW-TEST-CHAMPION', 'player_champion', 'reg_champion', true, 200000, 1);
$register($database, $ledger, 'tour_completed', 'MGW-TEST-LOSER', 'player_loser', 'reg_loser', true, 0, 8);
$register($database, $ledger, 'tour_active', 'MGW-TEST-ACTIVE', 'player_active', 'reg_active', false, 0, null);

foreach (['champion_crown'=>'temporary_style','winner_badge'=>'permanent_achievement','hall_of_fame'=>'permanent_achievement'] as $code=>$kind) {
    $database->execute(
        'INSERT INTO mgw_tournament_reward_entitlements (
            tournament_id,mgw_id,reward_code,reward_kind,valid_from_at_utc,valid_until_at_utc,
            metadata_json,granted_at_utc,created_at_utc
         ) VALUES (
            :tournament_id,:mgw_id,:reward_code,:reward_kind,:valid_from,NULL,
            NULL,:granted,:created
         )',
        [
            'tournament_id'=>'tour_completed',
            'mgw_id'=>'MGW-TEST-CHAMPION',
            'reward_code'=>$code,
            'reward_kind'=>$kind,
            'valid_from'=>$now,
            'granted'=>$now,
            'created'=>$now,
        ]
    );
}
$database->execute(
    'INSERT INTO mgw_tournament_golden_tickets (
        mgw_id,ticket_state,championship_count,first_tournament_id,last_tournament_id,
        first_awarded_at_utc,last_awarded_at_utc,updated_at_utc
     ) VALUES (:mgw_id,:state,1,:first,:last,:first_at,:last_at,:updated)',
    [
        'mgw_id'=>'MGW-TEST-CHAMPION',
        'state'=>'valid',
        'first'=>'tour_completed',
        'last'=>'tour_completed',
        'first_at'=>$now,
        'last_at'=>$now,
        'updated'=>$now,
    ]
);

$runtimeWrites = [];
$service = new StagingTournamentBaselineResetService(
    ['environment'=>'staging'],
    $database,
    $ledger,
    static function (array $balances, array $fixtures, string $resetAt) use (&$runtimeWrites): array {
        $runtimeWrites[] = ['balances'=>$balances,'fixtures'=>$fixtures,'at'=>$resetAt];
        return [
            'updated_balances'=>count($balances),
            'removed_fixture_users'=>count($fixtures),
            'hidden_json_notifications'=>0,
            'hidden_db_notifications'=>0,
        ];
    }
);
$server = ['HTTP_HOST'=>'seashell-okapi-889488.hostingersite.com'];

$preview = $service->preview($server);
baseline_assert($preview['clean'] === false, 'dirty MVP-21 test state must be detected');
baseline_assert($preview['can_apply'] === true, 'fixture cleanup must be safe to apply');
baseline_assert((int)$preview['counts']['eligible_results'] === 2, 'two visible test results expected');
baseline_assert((int)$preview['counts']['reward_entitlements'] === 3, 'three test entitlements expected');
baseline_assert((int)$preview['counts']['golden_tickets'] === 1, 'one test Golden Ticket expected');
baseline_assert((int)$preview['counts']['active_reservations'] === 1, 'one active test reservation expected');

$result = $service->apply(
    $server,
    (string)$preview['fingerprint'],
    'RESET_MVP21_TEST_TOURNAMENT_STATE'
);
baseline_assert($result['status'] === 'reset', 'cleanup must execute');

$champion = $ledger->getBalance('legacy:player_champion', 'coins');
$loser = $ledger->getBalance('legacy:player_loser', 'coins');
$active = $ledger->getBalance('legacy:player_active', 'coins');
baseline_assert((int)$champion['available_amount'] === 100000, 'champion test prize must be fully neutralized');
baseline_assert((int)$loser['available_amount'] === 100000, 'loser test entry must be restored');
baseline_assert((int)$active['available_amount'] === 100000, 'active test entry must be released');
baseline_assert((int)$champion['reserved_amount'] === 0, 'champion reserved balance must be zero');
baseline_assert((int)$loser['reserved_amount'] === 0, 'loser reserved balance must be zero');
baseline_assert((int)$active['reserved_amount'] === 0, 'active reserved balance must be zero');

baseline_assert((int)$database->fetchValue('SELECT COUNT(*) FROM mgw_tournament_results') === 2, 'raw tournament results must remain for audit');
baseline_assert((int)$database->fetchValue('SELECT COUNT(*) FROM mgw_tournament_results WHERE reward_eligible<>0') === 0, 'no result may remain product-visible');
baseline_assert((int)$database->fetchValue('SELECT COUNT(*) FROM mgw_tournament_reward_entitlements') === 0, 'all test reward projections must be removed');
baseline_assert((int)$database->fetchValue('SELECT COUNT(*) FROM mgw_tournament_golden_tickets') === 0, 'all test Golden Tickets must be removed');
baseline_assert((int)$database->fetchValue('SELECT COUNT(*) FROM mgw_tournaments WHERE active_slot IS NOT NULL') === 0, 'no official tournament may remain active');
baseline_assert((int)$database->fetchValue("SELECT COUNT(*) FROM mgw_tournament_registrations WHERE registration_state='registered'") === 0, 'no test registration may remain active');
baseline_assert((int)$database->fetchValue("SELECT COUNT(*) FROM mgw_ledger_entries WHERE source_type='official_tournament'") > 0, 'append-only tournament ledger audit must be preserved');
baseline_assert((int)$database->fetchValue("SELECT COUNT(*) FROM mgw_ledger_entries WHERE source_type='staging_test' AND source_ref='mvp21_staging_baseline_reset_2026_09_24'") === 2, 'financial reversal must be compensating ledger entries');
baseline_assert(count($runtimeWrites) === 1, 'runtime projection must be updated once');

$final = $service->preview($server);
baseline_assert($final['clean'] === true, 'final staging product state must look like zero tournaments happened');
$repeat = $service->apply(
    $server,
    (string)$final['fingerprint'],
    'RESET_MVP21_TEST_TOURNAMENT_STATE'
);
baseline_assert($repeat['status'] === 'already_clean', 'cleanup must be idempotent');

fwrite(STDOUT, "MVP-21 staging baseline reset OK (" . $database->driver() . "): feature code preserved, visible tournaments/rewards zeroed, ledger audit retained.\n");
