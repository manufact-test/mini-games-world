<?php
declare(strict_types=1);

require dirname(__DIR__) . '/services/FeatureFlagService.php';
require dirname(__DIR__) . '/services/GameCatalogService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};

$config = [
    'board_sizes' => [3, 5, 9],
    'feature_flags' => [
        'games' => [
            'domino' => false,
            'chess' => true,
        ],
    ],
];
$catalog = new GameCatalogService($config);

$domino = $catalog->get('domino');
$assertSame('domino', $domino['id'], 'Disabled game definition must remain readable for active games');
$assertSame(false, $catalog->publicGameDefinition('domino')['available'], 'Public catalog must mark disabled domino unavailable');
$assertSame(true, $catalog->publicGameDefinition('chess')['available'], 'Other games must remain available');
$assertSame(false, $catalog->supportsBot('domino'), 'Disabled game must not create a bot match');

$blocked = false;
try {
    $catalog->normalizeGameType('domino');
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'временно недоступна');
}
$assertSame(true, $blocked, 'Disabled domino must reject new match normalization');
$assertSame('chess', $catalog->normalizeGameType('chess'), 'Enabled chess must still start');

fwrite(STDOUT, "GameCatalogFeatureFlagTest: {$assertions} assertions passed\n");
