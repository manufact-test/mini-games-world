<?php
declare(strict_types=1);

if (!class_exists('GameCatalogService')) {
    class GameCatalogService {
        public function defaultGameType(): string { return 'battleship'; }
        public function get(string $type): array { return ['engine' => 'battleship', 'action_type' => 'battleship_action']; }
    }
}
if (!class_exists('GameRuntimeService')) {
    class GameRuntimeService {
        public array $actions = [];
        public function applyBattleshipAction(array &$db, array &$user, string $gameId, array $action): array {
            $this->actions[] = $action;
            if (($action['type'] ?? '') === 'clear_fleet') $db['games'][$gameId]['placed'] = [];
            if (($action['type'] ?? '') === 'place_ship') $db['games'][$gameId]['placed'][] = $action;
            return $db['games'][$gameId];
        }
        public function applyCheckersAction(array &$db, array &$user, string $gameId, array $action): array { return []; }
        public function applyReversiAction(array &$db, array &$user, string $gameId, array $action): array { return []; }
        public function applyChessAction(array &$db, array &$user, string $gameId, array $action): array { return []; }
        public function applyGoAction(array &$db, array &$user, string $gameId, array $action): array { return []; }
        public function applyDominoAction(array &$db, array &$user, string $gameId, array $action): array { return []; }
        public function makeMove(array &$db, array &$user, string $gameId, int $cell): array { return []; }
        public function dropFourInARowDisc(array &$db, array &$user, string $gameId, int $column): array { return []; }
    }
}
if (!class_exists('ChessRuntimeService')) {
    class ChessRuntimeService extends GameRuntimeService {}
}

require_once dirname(__DIR__) . '/services/GameActionService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$runtime = new GameRuntimeService();
$service = new GameActionService(new GameCatalogService(), $runtime);
$db = ['games' => ['g1' => [
    'id' => 'g1',
    'status' => 'active',
    'game_type' => 'battleship',
    'player_ids' => ['u1','u2'],
]]];
$user = ['id' => 'u1'];
$ships = [
    ['size'=>4,'cells'=>[0,1,2,3]],
    ['size'=>3,'cells'=>[20,21,22]],
    ['size'=>3,'cells'=>[40,50,60]],
    ['size'=>2,'cells'=>[25,26]],
    ['size'=>2,'cells'=>[44,45]],
    ['size'=>2,'cells'=>[68,78]],
    ['size'=>1,'cells'=>[9]],
    ['size'=>1,'cells'=>[29]],
    ['size'=>1,'cells'=>[49]],
    ['size'=>1,'cells'=>[99]],
];

$result = $service->apply($db, $user, 'g1', ['type'=>'randomize_fleet', 'ships'=>$ships]);
$assert(count($runtime->actions) === 11, 'Exact random fleet must become one clear plus ten existing place_ship validations.');
$assert(($runtime->actions[0]['type'] ?? '') === 'clear_fleet', 'Server validation must clear the previous setup first inside the same transaction.');
$assert(count($result['placed'] ?? []) === 10, 'All ten ships must be passed through the existing Battleship placement path.');
$assert(($result['placed'][0]['cell'] ?? -1) === 0 && ($result['placed'][0]['orientation'] ?? '') === 'h', 'Horizontal ship normalization must preserve the exact layout.');
$assert(($result['placed'][2]['cell'] ?? -1) === 40 && ($result['placed'][2]['orientation'] ?? '') === 'v', 'Vertical ship normalization must preserve the exact layout.');

$runtime->actions = [];
$service->apply($db, $user, 'g1', ['type'=>'randomize_fleet']);
$assert(count($runtime->actions) === 1 && ($runtime->actions[0]['type'] ?? '') === 'randomize_fleet', 'Legacy randomize without a client fleet must remain unchanged.');

$failed = false;
try {
    $invalid = $ships;
    $invalid[0] = ['size'=>4,'cells'=>[0,1,2,12]];
    $service->apply($db, $user, 'g1', ['type'=>'randomize_fleet', 'ships'=>$invalid]);
} catch (RuntimeException $e) {
    $failed = str_contains($e->getMessage(), 'прямой');
}
$assert($failed, 'A bent or discontinuous client ship must be rejected before placement.');

fwrite(STDOUT, "ProductionV102BattleshipServerActionRuntimeTest: {$assertions} assertions passed\n");
