<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/DatabaseConfigLoader.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$root = sys_get_temp_dir() . '/mgw-database-loader-test-' . bin2hex(random_bytes(5));
$privateDir = $root . '/_private_mgw';
$configFile = $privateDir . '/config.php';
$databaseFile = $privateDir . '/database.php';
mkdir($privateDir, 0755, true);
file_put_contents($configFile, '<?php return [];');

try {
    $base = [
        'environment' => 'staging',
        'environment_guard' => [
            'production_hosts' => ['play.example.com'],
            'production_data_dir' => '/srv/mgw_data',
        ],
    ];

    $missing = DatabaseConfigLoader::merge($base, $configFile);
    $assertSame(false, $missing['database_config_loaded'], 'Missing standalone database file must be optional');
    $assertSame(['play.example.com'], $missing['environment_guard']['production_hosts'], 'Missing database file must not alter the environment guard');

    file_put_contents($databaseFile, <<<'PHP'
<?php
return [
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'test-db.internal',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test_user',
        'password' => 'private-password',
        'charset' => 'utf8mb4',
    ],
    'database_migrations_allow_production' => false,
    'environment_guard' => [
        'production_database_sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ],
];
PHP);

    $merged = DatabaseConfigLoader::merge($base, $configFile);
    $assertSame(true, $merged['database_config_loaded'], 'Standalone database file must be detected');
    $assertSame('test-db.internal', $merged['database']['host'], 'Standalone database settings must be merged');
    $assertSame(false, $merged['database_migrations_allow_production'], 'Production migration approval must be loaded explicitly');
    $assertSame(
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        $merged['environment_guard']['production_database_sha256'],
        'Database fingerprint must merge into the existing environment guard'
    );
    $assertSame(['play.example.com'], $merged['environment_guard']['production_hosts'], 'Database loader must preserve existing environment guard keys');

    file_put_contents($databaseFile, '<?php return ["bot_token" => "forbidden"];');
    $assertThrows(
        static fn() => DatabaseConfigLoader::merge($base, $configFile),
        'unsupported keys',
        'Standalone database file must not override unrelated application settings'
    );

    file_put_contents($databaseFile, '<?php return ["environment_guard" => ["production_hosts" => ["forbidden"]]];');
    $assertThrows(
        static fn() => DatabaseConfigLoader::merge($base, $configFile),
        'unsupported environment guard keys',
        'Standalone database file must only control the database fingerprint guard'
    );

    file_put_contents($databaseFile, '<?php return "invalid";');
    $assertThrows(
        static fn() => DatabaseConfigLoader::merge($base, $configFile),
        'must return an array',
        'Standalone database file must return an array'
    );

    fwrite(STDOUT, "DatabaseConfigLoaderTest: {$assertions} assertions passed\n");
} finally {
    foreach (glob($privateDir . '/*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    if (is_dir($privateDir)) rmdir($privateDir);
    if (is_dir($root)) rmdir($root);
}
