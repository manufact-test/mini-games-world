<?php
declare(strict_types=1);

final class DatabaseConfig
{
    public static function fromApplicationConfig(array $config): self
    {
        return new self();
    }

    public function enabled(): bool
    {
        return true;
    }
}

final class GameIdentityProjectionFakeDatabase
{
    public int $queryCount = 0;

    public function __construct(private array $rows) {}

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->queryCount++;
        if (!str_contains($sql, 'SELECT i.provider_subject, i.mgw_id, u.nickname')) {
            throw new RuntimeException('Game identity projection must use the canonical read-only identity query.');
        }
        if (preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1) {
            throw new RuntimeException('Game identity projection must remain read-only.');
        }

        $subjects = array_map('strval', array_values($parameters));
        return array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => in_array((string)$row['provider_subject'], $subjects, true)
        ));
    }
}

final class PdoConnectionFactory
{
    public static GameIdentityProjectionFakeDatabase $database;

    public static function create(DatabaseConfig $config): GameIdentityProjectionFakeDatabase
    {
        return self::$database;
    }
}

$root = dirname(__DIR__, 2);
$responseSource = file_get_contents($root . '/bot/helpers/response.php');
$resolverSource = file_get_contents($root . '/bot/accounts/RuntimeAccountIdentityResolver.php');
$apiSource = file_get_contents($root . '/bot/api.php');
$runtimeSource = file_get_contents($root . '/bot/services/ChessRuntimeService.php');
$catalogSource = file_get_contents($root . '/bot/services/GameCatalogService.php');
foreach ([$responseSource, $resolverSource, $apiSource, $runtimeSource, $catalogSource] as $source) {
    if (!is_string($source)) throw new RuntimeException('Unable to read Task-4 source contract.');
}

require_once $root . '/bot/helpers/response.php';
$GLOBALS['config'] = ['database' => ['enabled' => true]];
PdoConnectionFactory::$database = new GameIdentityProjectionFakeDatabase([
    ['provider_subject'=>'1001', 'mgw_id'=>'MGW-A', 'nickname'=>'Царь у дворца'],
    ['provider_subject'=>'1001', 'mgw_id'=>'MGW-A', 'nickname'=>'Царь у дворца'],
    ['provider_subject'=>'1002', 'mgw_id'=>'MGW-B1', 'nickname'=>'Не использовать 1'],
    ['provider_subject'=>'1002', 'mgw_id'=>'MGW-B2', 'nickname'=>'Не использовать 2'],
    ['provider_subject'=>'1003', 'mgw_id'=>'MGW-C', 'nickname'=>'Игрок Три'],
]);

$input = [
    'user' => ['first_name'=>'Telegram Name', 'username'=>'telegram_user'],
    'game' => [
        'id'=>'g1',
        'players'=>[
            ['id'=>'1001', 'name'=>'Old Telegram 1', 'symbol'=>'X'],
            ['id'=>'1002', 'name'=>'Old Telegram 2', 'symbol'=>'O'],
            ['id'=>'bot_easy', 'name'=>'Бот', 'symbol'=>'B'],
        ],
    ],
    'active_game' => [
        'id'=>'g2',
        'players'=>[
            ['id'=>'1003', 'name'=>'Old Telegram 3', 'symbol'=>'X'],
        ],
    ],
];

$output = mgw_project_canonical_game_identity($input);

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) throw new RuntimeException($message . ': missing ' . $needle);
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($haystack, $needle)) throw new RuntimeException($message . ': forbidden ' . $needle);
};

$assertSame('Царь у дворца', $output['game']['players'][0]['name'], 'Canonical nickname must replace provider display name in public game response');
$assertSame('Old Telegram 2', $output['game']['players'][1]['name'], 'Ambiguous provider subject must preserve existing safe display name');
$assertSame('Бот', $output['game']['players'][2]['name'], 'Bot identity must remain unchanged');
$assertSame('Игрок Три', $output['active_game']['players'][0]['name'], 'Bootstrap active_game must use the same canonical projection');
$assertSame($input['user'], $output['user'], 'Game response projection must never rewrite authenticated user identity');
$assertSame(2, PdoConnectionFactory::$database->queryCount, 'Only public game payloads should trigger canonical read queries');

$assertContains('mgw_project_canonical_game_identity($data)', $responseSource, 'API normalization must project canonical game identity at the final response boundary');
$assertContains("foreach (['game', 'active_game'] as \$gameKey)", $responseSource, 'Projection must be limited to public game payloads');
$assertNotContains("\$user['mgw_nickname']", $resolverSource, 'Runtime account resolver must not inject visible game identity globally');

$assertContains('$games = new ChessRuntimeService(', $apiSource, 'API must keep one shared runtime facade');
$assertContains("'game' => \$game ? \$games->publicGame(\$game, \$userId) : null", $apiSource, 'game_state must keep the shared public-game projection');
$assertContains("'game' => \$games->publicGame(\$game, \$userId)", $apiSource, 'game actions must keep the shared public-game projection');
$assertContains("'chess' => \$this->chess->publicGame(\$game, \$viewerId)", $runtimeSource, 'Chess must remain behind shared public runtime');
$assertContains("'go' => \$this->go->publicGame(\$game, \$viewerId)", $runtimeSource, 'Go must remain behind shared public runtime');
$assertContains("'domino' => \$this->domino->publicGame(\$game, \$viewerId)", $runtimeSource, 'Domino must remain behind shared public runtime');
$assertContains('$public = $this->base->publicGame($game, $viewerId)', $runtimeSource, 'Other games must remain behind the same shared public runtime');

foreach (['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino'] as $gameType) {
    $assertContains("'{$gameType}' =>", $catalogSource, "Catalog must retain all-eight-game coverage for {$gameType}");
}

fwrite(STDOUT, "GamePublicIdentityResponseProjectionTest: {$assertions} assertions passed\n");
