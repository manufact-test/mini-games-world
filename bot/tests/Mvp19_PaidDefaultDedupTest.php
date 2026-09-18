<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$databaseDir = $root . '/bot/database';
require_once $databaseDir . '/DatabaseConnectionInterface.php';
require_once $databaseDir . '/DatabaseExceptionClassifier.php';
require_once $databaseDir . '/PdoDatabaseConnection.php';
require_once $databaseDir . '/DatabaseMigrationInterface.php';
require_once $databaseDir . '/MigrationRepository.php';
require_once $databaseDir . '/MigrationRunner.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';
require_once $root . '/bot/accounts/MgwIdentityPolicy.php';
require_once $root . '/bot/catalog/ProductInventoryService.php';
require_once $root . '/bot/accounts/AccountIdentityService.php';
require_once $root . '/bot/catalog/CosmeticStoreService.php';

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Paid default dedup test requires pdo_sqlite.');

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assertSame(count(glob($databaseDir . '/migrations/*.php') ?: []), (int)$migration['executed_count'], 'Fixture must apply every current migration');

$expectedNames = [
    'game-chess-board-wood' => 'Янтарная доска',
    'game-chess-pieces-wood' => 'Янтарные фигуры',
    'game-checkers-board-wood' => 'Лазурная доска',
    'game-checkers-pieces-wood' => 'Керамические шашки',
    'game-reversi-field-green' => 'Лазурное поле',
    'game-reversi-pieces-classic' => 'Перламутровые фишки',
    'game-go-board-wood' => 'Доска сакуры',
    'game-go-stones-classic' => 'Янтарные камни',
    'game-domino-table-felt' => 'Бордовый стол',
    'game-domino-tiles-ivory' => 'Янтарные костяшки',
    'game-four-field-blue' => 'Фиолетовое поле',
    'game-four-discs-classic' => 'Аркадные фишки',
];

$expectedPrices = [
    'game-chess-board-wood'=>3000,
    'game-chess-pieces-wood'=>3000,
    'game-checkers-board-wood'=>3000,
    'game-checkers-pieces-wood'=>3000,
    'game-reversi-field-green'=>3000,
    'game-reversi-pieces-classic'=>3000,
    'game-go-board-wood'=>3000,
    'game-go-stones-classic'=>3000,
    'game-domino-table-felt'=>3000,
    'game-domino-tiles-ivory'=>3000,
    'game-four-field-blue'=>3000,
    'game-four-discs-classic'=>3000,
];

foreach ($expectedNames as $itemId => $displayName) {
    $rows = $database->fetchAll(
        "SELECT c.item_id, c.metadata_json, o.price_coins
         FROM mgw_product_catalog c
         INNER JOIN mgw_product_offers o ON o.item_id = c.item_id AND o.offer_type = 'item'
         WHERE c.item_id = :item_id",
        ['item_id'=>$itemId]
    );
    $assertSame(1, count($rows), 'Stable paid SKU must remain present: ' . $itemId);
    $metadata = json_decode((string)$rows[0]['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame($displayName, (string)($metadata['display_name'] ?? ''), 'Paid SKU must expose the replacement player-facing name');
    $assertSame('v1', (string)($metadata['paid_default_dedup'] ?? ''), 'Paid SKU must be marked as deduplicated');
    $assertSame($expectedPrices[$itemId], (int)$rows[0]['price_coins'], 'Dedup must not alter the economy');
}

$store = new CosmeticStoreService($database);
$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'paid-dedup-user', 'browser_dev', ['username'=>'paid-dedup'], 'paid-dedup-session');
$snapshot = $store->snapshot((string)$account['mgw_id'], 100000, []);

foreach ([
    ['chess','themes','Янтарная доска'],
    ['chess','elements','Янтарные фигуры'],
    ['checkers','themes','Лазурная доска'],
    ['checkers','elements','Керамические шашки'],
    ['reversi','themes','Лазурное поле'],
    ['reversi','elements','Перламутровые фишки'],
    ['go','themes','Доска сакуры'],
    ['go','elements','Янтарные камни'],
    ['domino','themes','Бордовый стол'],
    ['domino','elements','Янтарные костяшки'],
    ['four_in_a_row','themes','Фиолетовое поле'],
    ['four_in_a_row','elements','Аркадные фишки'],
] as [$game,$group,$name]) {
    $offers = $snapshot['games']['catalogs'][$game][$group] ?? [];
    $assertTrue(in_array($name, array_map(static fn(array $offer): string => (string)($offer['display_name'] ?? ''), $offers), true), 'Store snapshot must expose replacement name ' . $name);
}

$migrationSource = (string)file_get_contents($databaseDir . '/migrations/20260918_0037_deduplicate_paid_game_defaults.php');
$css = (string)file_get_contents($root . '/app/assets/css/games/paid-default-dedup-v1.css');
$storeCorrective = (string)file_get_contents($root . '/app/assets/js/screens/store-paid-default-dedup-v1.js');
$baseStoreSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$reversiStoreSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-reversi-store-v1.js');
$goStoreSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-go-store-v1.js');
$dominoStoreSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-domino-store-v1.js');
$fourStoreSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-four-in-a-row-store-v1.js');
$wrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-board-source-wrapper.js');
$mainCss = (string)file_get_contents($root . '/app/assets/css/main.css');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$launch = (string)file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');

$assertTrue(!str_contains($migrationSource, 'game-ttt-'), 'TTT is already correct and must stay outside the dedup migration');
$assertTrue(str_contains($css, 'data-chess-theme="wood"') && str_contains($css, 'amber-v2.svg'), 'Chess first paid board must have a non-default amber identity');
$assertTrue(str_contains($css, 'data-checkers-theme="wood"') && str_contains($css, '#c7e7df'), 'Checkers first paid board must have a non-default azure identity');
$assertTrue(str_contains($css, 'data-reversi-theme="green"') && str_contains($css, '#268da2'), 'Reversi first paid field must no longer be default green');
$assertTrue(str_contains($css, 'data-go-theme="wood"') && str_contains($css, '#cf8b7f'), 'Go first paid board must no longer mirror the base wooden board');
$assertTrue(str_contains($css, 'data-domino-theme="felt"') && str_contains($css, '#5b1830'), 'Domino first paid table must no longer mirror base green felt');
$assertTrue(str_contains($css, 'theme-blue') && str_contains($css, '#8750d5'), 'Four in a Row first paid field must no longer mirror the base blue board');
$assertTrue(str_contains($css, 'pieces-classic.red') && str_contains($css, '#ff78b7') && str_contains($css, '#66e8f3'), 'Four in a Row first paid discs must no longer mirror base red/yellow discs');
$assertTrue(!str_contains($storeCorrective, 'const COPY') && !str_contains($storeCorrective, '.textContent = copy.') && !str_contains($storeCorrective, 'setTimeout'), 'Paid-default corrective must not rewrite text after render; native Store owners must render the final copy immediately');
$assertTrue(str_contains($baseStoreSource, 'Янтарно-бордовая доска') && str_contains($baseStoreSource, 'Холодная лазурно-мятная доска'), 'Chess and Checkers replacement descriptions must render from the base Store owner');
$assertTrue(str_contains($reversiStoreSource, 'Холодное лазурное поле') && str_contains($reversiStoreSource, 'Тёмный индиго и светлый перламутр'), 'Reversi replacement descriptions must render from its native Store owner');
$assertTrue(str_contains($goStoreSource, 'Розово-вишнёвая древесина') && str_contains($goStoreSource, 'медово-янтарные камни'), 'Go replacement descriptions must render from its native Store owner');
$assertTrue(str_contains($dominoStoreSource, 'Глубокое бордовое сукно') && str_contains($dominoStoreSource, 'Тёплые янтарные костяшки'), 'Domino replacement descriptions must render from its native Store owner');
$assertTrue(str_contains($fourStoreSource, 'Насыщенное фиолетово-сливовое поле') && str_contains($fourStoreSource, 'Яркая розово-бирюзовая пара'), 'Four in a Row replacement descriptions must render from its native Store owner');
foreach ([$baseStoreSource, $reversiStoreSource, $goStoreSource, $dominoStoreSource, $fourStoreSource] as $source) {
    $assertTrue(!str_contains($source, 'вместо стандарт') && !str_contains($source, 'базового набора'), 'Replacement cosmetic copy must describe the item itself without technical comparison language');
}
$assertTrue(str_contains($wrapper, 'store-paid-default-dedup-v1.js?v=2&paid_default=dedup-v2'), 'Active Store wrapper must install the dedup corrective');
$assertTrue(str_contains($mainCss, "paid-default-dedup-v1.css?v=1&paid_default=dedup-v1"), 'Global CSS must project new skins into already-live games');

$activeStore = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$activeStoreWrapperMatch = [];
$assertTrue(
    preg_match('~store-screen-checkers-board-source-wrapper\\.js\\?v=(\\d+)~', $activeStore, $activeStoreWrapperMatch) === 1
    && (int)$activeStoreWrapperMatch[1] >= 12
    && str_contains($activeStore, 'paid_default=dedup-v2')
    && str_contains($activeStore, 'copy=human-v1'),
    'Active Store graph must preserve dedup v2 while publishing human copy'
);
$assertTrue(str_contains((string)($manifest['assets']['main_css'] ?? ''), 'main.css?v=191') && str_contains((string)($manifest['assets']['main_css'] ?? ''), 'paid_default=dedup-v1'), 'Active main CSS graph must publish dedup v1');

$launchMatch = [];
$assertTrue(preg_match('~/app/v110\.php\?v=(\d+)~', $launch, $launchMatch) === 1 && (int)$launchMatch[1] >= 1195, 'Telegram launch must publish dedup v2');

echo "Paid game-default dedup contract passed ({$assertions} assertions).\n";
