<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'migration'=>'bot/database/migrations/20260920_0049_create_official_tournaments.php',
    'service'=>'bot/tournaments/TournamentRegistrationService.php',
    'player_endpoint'=>'bot/tournaments.php',
    'admin_endpoint'=>'bot/admin-tournaments.php',
    'bootstrap'=>'bot/core/bootstrap.php',
    'ledger'=>'bot/ledger/LedgerWriteService.php',
    'client'=>'app/assets/js/api/client.js',
    'screen'=>'app/assets/js/screens/tournaments-screen-v1.js',
    'main_css'=>'app/assets/css/main.css',
    'admin'=>'app/admin.php',
    'admin_client'=>'app/assets/js/admin-tournaments.js',
    'admin_css'=>'app/assets/css/admin-shell.css',
    'manifest'=>'app/runtime/client/version-manifest.php',
    'entry'=>'app/v110.php',
];
$sources = [];
foreach ($paths as $key=>$path) {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Missing MVP-21.1 source: ' . $path);
    $sources[$key] = $value;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(str_contains($sources['migration'], 'CREATE TABLE IF NOT EXISTS mgw_tournaments'), 'MVP-21.1 must persist tournament entities.');
$assertTrue(str_contains($sources['migration'], 'CREATE TABLE IF NOT EXISTS mgw_tournament_registrations'), 'MVP-21.1 must persist registrations.');
$assertTrue(str_contains($sources['migration'], 'UNIQUE KEY uq_mgw_tournaments_active_slot'), 'Only one active official tournament slot may exist.');
$assertTrue(str_contains($sources['migration'], 'capacity IN (8,16,32,64,128)'), 'All five canonical tournament capacities must be accepted.');
$assertTrue(str_contains($sources['migration'], 'FOREIGN KEY (reservation_id)'), 'Tournament registration must reference the canonical ledger reservation.');
$assertTrue(!str_contains($sources['migration'], 'CREATE TABLE IF NOT EXISTS mgw_balances'), 'MVP-21.1 must not create a second balance owner.');
$assertTrue(!str_contains($sources['migration'], 'CREATE TABLE IF NOT EXISTS mgw_reservations'), 'MVP-21.1 must not create a second reservation owner.');

$assertTrue(str_contains($sources['service'], "public const ENTRY_ASSET = 'mgw_coin'"), 'Tournament entry must use canonical mgw_coin.');
$assertTrue(str_contains($sources['service'], 'public const ENTRY_FEE = 50000'), 'Tournament entry must be exactly 50,000.');
$assertTrue(str_contains($sources['service'], 'public const ALLOWED_CAPACITIES = [8, 16, 32, 64, 128]'), 'Service must expose all canonical capacities.');
$assertTrue(str_contains($sources['service'], '$this->ledger->createReservation(['), 'Registration must reserve through LedgerWriteService.');
$assertTrue(str_contains($sources['service'], '$this->ledger->releaseReservation(['), 'Leaving before full must release through LedgerWriteService.');
$assertTrue(!str_contains($sources['service'], '$this->ledger->consumeReservation(['), 'MVP-21.1 must never consume the tournament entry.');
$assertTrue(str_contains($sources['service'], "'registration_semantics'=>'reserved_not_spent'"), 'Frozen reward snapshot must state reserved-not-spent semantics.');
$assertTrue(str_contains($sources['service'], "'total'=>200000"), 'Reward snapshot must freeze first-place total.');
$assertTrue(str_contains($sources['service'], "'total'=>80000"), 'Reward snapshot must freeze second-place total.');
$assertTrue(str_contains($sources['service'], "'total'=>50000"), 'Reward snapshot must freeze third-place total.');
$assertTrue(str_contains($sources['service'], "'golden_ticket'=>true"), 'Reward snapshot must freeze Golden Ticket ownership.');

$registerStart = strpos($sources['service'], 'public function register(');
$leaveStart = strpos($sources['service'], 'public function leave(');
$registerSlice = ($registerStart !== false && $leaveStart !== false)
    ? substr($sources['service'], $registerStart, $leaveStart - $registerStart)
    : '';
$lockPosition = strpos($registerSlice, '$this->activeTournamentRow($db, true)');
$countPosition = strpos($registerSlice, '$this->registeredCount(');
$reservePosition = strpos($registerSlice, '$this->ledger->createReservation([');
$assertTrue($lockPosition !== false, 'Registration must lock the tournament row.');
$assertTrue($countPosition !== false && $lockPosition < $countPosition, 'Capacity must be counted only after the tournament row lock.');
$assertTrue($reservePosition !== false && $countPosition < $reservePosition, 'Capacity must be checked before reserving coins.');
$assertTrue(str_contains($registerSlice, "if ($registeredCount >= $capacity)"), 'Full tournament must reject a later contender before reservation.');

$assertTrue(str_contains($sources['player_endpoint'], 'getUserFromRequest($payload)'), 'Player tournament API must reuse canonical authentication.');
$assertTrue(str_contains($sources['player_endpoint'], "['mgw_account_ref']"), 'Player tournament API must use attached canonical account_ref.');
foreach (["action === 'status'","action === 'register'","action === 'leave'"] as $needle) {
    $assertTrue(str_contains($sources['player_endpoint'], $needle), 'Player tournament endpoint missing action: ' . $needle);
}

$assertTrue(str_contains($sources['admin_endpoint'], 'AdminWebAuth::authorize'), 'Tournament Admin must reuse Telegram AdminWebAuth.');
$assertTrue(str_contains($sources['admin_endpoint'], 'new GameCatalogService($config)'), 'Tournament Admin must validate games through the canonical catalog.');
foreach (["action === 'snapshot'","action === 'create_draft'","action === 'open_registration'"] as $needle) {
    $assertTrue(str_contains($sources['admin_endpoint'], $needle), 'Tournament Admin endpoint missing action: ' . $needle);
}

$assertTrue(str_contains($sources['bootstrap'], "tournaments/TournamentRegistrationService.php"), 'Runtime bootstrap must load tournament registration service.');
$assertTrue(str_contains($sources['ledger'], 'public function createReservation'), 'Canonical ledger reservation owner must remain present.');
$assertTrue(str_contains($sources['ledger'], 'public function releaseReservation'), 'Canonical ledger release owner must remain present.');

$assertTrue(str_contains($sources['client'], 'TOURNAMENT_URL'), 'Client API must own tournament endpoint URL.');
$assertTrue(str_contains($sources['client'], 'tournamentStatus:'), 'Client API must expose tournament status.');
$assertTrue(str_contains($sources['client'], 'tournamentRegister:'), 'Client API must expose register.');
$assertTrue(str_contains($sources['client'], 'tournamentLeave:'), 'Client API must expose leave.');

$assertTrue(str_contains($sources['screen'], 'official-tournament-registration-v1'), 'Arena Tournament tab must expose MVP-21.1 runtime identity.');
$assertTrue(str_contains($sources['screen'], 'data-tournament-action="register"'), 'Tournament tab must expose registration action.');
$assertTrue(str_contains($sources['screen'], 'data-tournament-action="leave"'), 'Tournament tab must expose pre-full leave action.');
$assertTrue(str_contains($sources['screen'], 'api.tournamentStatus()'), 'Tournament tab must read canonical tournament status.');
$assertTrue(str_contains($sources['screen'], 'api.tournamentRegister()'), 'Tournament tab must call canonical registration endpoint.');
$assertTrue(str_contains($sources['screen'], 'api.tournamentLeave()'), 'Tournament tab must call canonical leave endpoint.');
$assertTrue(str_contains($sources['main_css'], 'MVP-21.1 — first official tournament registration'), 'Tournament registration UI must have bounded Arena styling.');

$assertTrue(str_contains($sources['admin'], 'data-tournament-admin'), 'Web Admin must expose tournament creation surface.');
$assertTrue(str_contains($sources['admin'], 'data-tournament-api="../bot/admin-tournaments.php"'), 'Web Admin must point to canonical Tournament Admin API.');
$assertTrue(str_contains($sources['admin_client'], "action:'create_draft'"), 'Admin client must create draft explicitly.');
$assertTrue(str_contains($sources['admin_client'], "action:'open_registration'"), 'Admin client must open registration explicitly.');
$assertTrue(str_contains($sources['admin_css'], '.mgw-admin__tournament'), 'Tournament Admin must have bounded styling.');

$assertTrue(str_contains($sources['manifest'], 'mvp21_1=tournament-registration-v1'), 'Version manifest must publish tournament registration client identity.');
$assertTrue(str_contains($sources['entry'], "X-MGW-Tournaments: official-registration-v1"), 'Rendered runtime must expose tournament fingerprint.');

foreach ([
    'bot/games/',
    'app/assets/js/games/',
] as $forbiddenOwner) {
    $assertTrue(!str_contains($sources['service'], $forbiddenOwner), 'Tournament service must not become a game-engine owner: ' . $forbiddenOwner);
}

$assertTrue($assertions >= 50, 'MVP-21.1 integration contract must cover ownership, ledger, UI and concurrency boundaries.');
fwrite(STDOUT, "Mvp21_1TournamentRegistrationIntegrationContractTest: {$assertions} assertions passed\n");
