<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/helpers/validators.php';
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/database/DatabaseConfig.php';
require_once $root . '/database/PdoDatabaseConnection.php';
require_once $root . '/database/PdoConnectionFactory.php';
require_once $root . '/economy/UnifiedBalanceRuntimeState.php';
require_once $root . '/economy/MatchEconomyRuntimeConfig.php';
require_once $root . '/services/GameService.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};

$config = MatchEconomyRuntimeConfig::apply([
    'environment'=>'local',
    'canonical_economy_snapshot'=>[
        'version'=>7,
        'config'=>EconomyConfigDefinition::defaults(),
    ],
    'board_sizes'=>[3,5,9],
    'move_timeout_sec'=>60,
    'queue_timeout_sec'=>60,
    'match_bot_after_sec'=>1,
]);
$service = new GameService($config);
$entry = (int)$config['match_bet'];
$assert($entry > 0,'Test must use a positive canonical Match entry.');

$fixture = static function (int $aBalance, int $bBalance) use ($entry): array {
    return [
        'users'=>[
            'a'=>['id'=>'a','username'=>'A','first_name'=>'A','balance'=>$aBalance,'status'=>'searching','stats'=>[]],
            'b'=>['id'=>'b','username'=>'B','first_name'=>'B','balance'=>$bBalance,'status'=>'searching','stats'=>[]],
        ],
        'queue'=>[
            ['id'=>'queue-a','user_id'=>'a','room'=>'match','bet'=>$entry,'board_size'=>3,'created_at'=>date('c',time()-8)],
            ['id'=>'queue-b','user_id'=>'b','room'=>'match','bet'=>$entry,'board_size'=>3,'created_at'=>date('c',time()-2)],
        ],
        'games'=>[],
        'transactions'=>[],
        'system'=>[],
    ];
};

// A queued caller can spend coins while searching. Do not debit a negative
// balance or silently keep a stale match request alive.
$db = $fixture($entry-1,$entry+200);
$result = $service->maybeCreateBotGameForSearchingUser($db,$db['users']['a']);
$assert($result === null,'Underfunded caller cannot enter another match.');
$assert($db['users']['a']['balance'] === $entry-1,'No coins deducted from underfunded caller.');
$assert($db['users']['a']['status'] === 'idle','Unfunded search is ended.');
$assert(count($db['queue']) === 1 && $db['queue'][0]['user_id'] === 'b','Other funded player's queue survives.');
$assert($db['games'] === [] && $db['transactions'] === [],'Unfunded caller creates neither game nor ledger entry.');

// When only the opponent became underfunded, remove their stale offer and
// safely use the existing normal bot fallback for the funded player.
$db = $fixture($entry+200,$entry-1);
$result = $service->maybeCreateBotGameForSearchingUser($db,$db['users']['a']);
$assert(is_array($result) && !empty($result['is_bot_game']),'Funded player can fall back to bot.');
$assert($db['users']['b']['balance'] === $entry-1,'Unfunded opponent must never be charged.');
$assert($db['users']['b']['status'] === 'idle','Unfunded opponent is removed from queue.');
$assert($db['users']['a']['balance'] === 200,'Bot match debits caller exactly once.');
$assert(count($db['transactions']) === 2,'Bot match writes one entry delta and one game start.');

// Fully funded peers must preserve the accepted two-human path and exact
// single stake debit for both sides.
$db = $fixture($entry+200,$entry+200);
$result = $service->maybeCreateBotGameForSearchingUser($db,$db['users']['a']);
$assert(is_array($result) && empty($result['is_bot_game']),'Two funded peers create a human match.');
$assert($db['users']['a']['balance'] === 200,'Human A debited exactly once.');
$assert($db['users']['b']['balance'] === 200,'Human B debited exactly once.');
$changes = array_values(array_filter($db['transactions'], static fn(array $t): bool =>
    ($t['type'] ?? '') === 'balance_change' && ($t['reason'] ?? '') === 'game_entry'
));
$entries = array_values(array_filter($db['transactions'],static fn(array $t): bool =>
    ($t['type'] ?? '') === 'balance_change'
));
$assert(count($entries) === 2,'Human match creates exactly two balance changes.');
$assert(count($db['queue']) === 0,'Both accepted queue offers are consumed.');
echo "MGW_QUEUED_MATCH_STAKE_INVARIANT=PASS ({$checks} assertions)\n";
