<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/RuntimeConfigLoader.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};

$tempDir = sys_get_temp_dir() . '/mgw_runtime_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create runtime config test directory.');
}
$configFile = $tempDir . '/config.php';
file_put_contents($configFile, "<?php return [];\n");

$base = [
    'bot_token' => 'TOKEN_VALUE_FOR_TEST_ONLY',
    'feature_flags' => [
        'maintenance_mode' => false,
        'features' => [
            'matchmaking' => true,
            'payments' => true,
        ],
        'games' => [
            'domino' => true,
            'chess' => true,
        ],
    ],
];

$withoutRuntime = RuntimeConfigLoader::merge($base, $configFile);
$assertSame($base, $withoutRuntime, 'Missing runtime.php must preserve the primary config');

file_put_contents($tempDir . '/runtime.php', <<<'PHP'
<?php
return [
    'maintenance_mode' => true,
    'features' => [
        'matchmaking' => false,
    ],
    'games' => [
        'domino' => false,
    ],
];
PHP);

$merged = RuntimeConfigLoader::merge($base, $configFile);
$assertSame(true, $merged['feature_flags']['maintenance_mode'], 'runtime.php must override maintenance mode');
$assertSame(false, $merged['feature_flags']['features']['matchmaking'], 'runtime.php must override one feature');
$assertSame(true, $merged['feature_flags']['features']['payments'], 'runtime.php must preserve unspecified features');
$assertSame(false, $merged['feature_flags']['games']['domino'], 'runtime.php must override one game');
$assertSame(true, $merged['feature_flags']['games']['chess'], 'runtime.php must preserve unspecified games');
$assertSame('TOKEN_VALUE_FOR_TEST_ONLY', $merged['bot_token'], 'runtime.php must not replace secrets or primary config');

file_put_contents($tempDir . '/runtime.php', "<?php return 'invalid';\n");
$failedSafely = false;
try {
    RuntimeConfigLoader::merge($base, $configFile);
} catch (RuntimeException $e) {
    $failedSafely = str_contains($e->getMessage(), 'runtime config');
}
$assertSame(true, $failedSafely, 'Invalid runtime.php must fail safely');

@unlink($tempDir . '/runtime.php');
@unlink($configFile);
@rmdir($tempDir);

fwrite(STDOUT, "RuntimeConfigLoaderTest: {$assertions} assertions passed\n");
