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
$assertSame('domino', $catalog->normalizeGameType('domino'), 'Stored disabled game type must remain normalizable');
$assertSame(false, $catalog->publicGameDefinition('domino')['available'], 'Public catalog must mark disabled domino unavailable');
$assertSame(true, $catalog->publicGameDefinition('chess')['available'], 'Other games must remain available');
$assertSame(false, $catalog->supportsRoom('domino', 'match'), 'Disabled game must reject a new room entry');
$assertSame(true, $catalog->supportsRoom('chess', 'match'), 'Enabled Chess must retain its room support');
$assertSame(false, $catalog->supportsBot('domino'), 'Disabled game must not create a bot match');
$assertSame('chess', $catalog->normalizeGameType('chess'), 'Enabled Chess must remain normalizable');

$actionSource = file_get_contents(dirname(__DIR__) . '/services/GameActionService.php') ?: '';
$assertSame(
    false,
    str_contains($actionSource, 'catalog->normalizeGameType'),
    'Active game actions must not re-run new-match availability checks'
);
$assertSame(
    true,
    str_contains($actionSource, 'catalog->get($gameType)'),
    'Active game actions must resolve their existing engine directly'
);

$catalogSource = file_get_contents(dirname(__DIR__) . '/services/GameCatalogService.php') ?: '';
$normalizeStart = strpos($catalogSource, 'public function normalizeGameType');
$normalizeEnd = strpos($catalogSource, 'public function get', $normalizeStart !== false ? $normalizeStart : 0);
$normalizeBody = $normalizeStart !== false && $normalizeEnd !== false
    ? substr($catalogSource, $normalizeStart, $normalizeEnd - $normalizeStart)
    : '';
$assertSame(
    false,
    str_contains($normalizeBody, 'assertNewMatchAllowed'),
    'Stored-record normalization must never block an active game'
);

fwrite(STDOUT, "GameCatalogFeatureFlagTest: {$assertions} assertions passed\n");
