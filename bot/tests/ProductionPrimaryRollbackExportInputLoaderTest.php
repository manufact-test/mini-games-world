<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportInputLoader.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
        );
    }
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        $items = scandir($path);
        if (!is_array($items)) throw new RuntimeException('Fixture directory could not be listed.');
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') continue;
            $remove($path . '/' . $name);
        }
        if (!rmdir($path)) throw new RuntimeException('Fixture directory could not be removed.');
        return;
    }
    if (!unlink($path)) throw new RuntimeException('Fixture file could not be removed.');
};
$write = static function (string $path, string $content): void {
    $written = file_put_contents($path, $content, LOCK_EX);
    if ($written !== strlen($content) || !chmod($path, 0600)) {
        throw new RuntimeException('Private fixture could not be written.');
    }
};

$root = sys_get_temp_dir() . '/mgw-rollback-input-loader-' . bin2hex(random_bytes(6));
$project = $root . '/public_html';
$private = $root . '/private';
$output = $root . '/rollback-exports';
$oldDatabaseOverride = getenv('MGW_DATABASE_CONFIG_FILE');

try {
    foreach ([$root, $project, $private, $output] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Fixture directory could not be created.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Fixture directory mode could not be secured.');
        }
    }

    $configFile = $private . '/config.php';
    $runtimeFile = $private . '/runtime.php';
    $databaseFile = $private . '/database.php';
    $cutoverFile = $private . '/production-cutover.json';
    $authorizationFile = $private . '/production-rollback-export-authorization.json';

    $write($configFile, <<<'PHP'
<?php
declare(strict_types=1);
return [
    'environment' => 'production',
    'storage_driver' => 'json',
    'feature_flags' => [
        'maintenance_mode' => false,
    ],
];
PHP);
    $write($runtimeFile, <<<'PHP'
<?php
declare(strict_types=1);
return [
    'maintenance_mode' => true,
    'financial_read_only' => true,
];
PHP);
    $write($databaseFile, <<<'PHP'
<?php
declare(strict_types=1);
return [
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'db.internal',
        'port' => 3306,
        'name' => 'mgw_production',
        'user' => 'mgw_production',
        'password' => 'private-test-password',
        'charset' => 'utf8mb4',
    ],
];
PHP);
    $write($cutoverFile, "{\n  \"state\": \"completed\"\n}\n");
    $write($authorizationFile, "{\n  \"authorized\": true\n}\n");
    putenv('MGW_DATABASE_CONFIG_FILE');

    $loader = new ProductionPrimaryRollbackExportInputLoader(
        realpath($project) ?: $project
    );
    $loaded = $loader->load(
        realpath($configFile) ?: $configFile,
        realpath($cutoverFile) ?: $cutoverFile,
        realpath($authorizationFile) ?: $authorizationFile,
        realpath($output) ?: $output
    );

    $assertSame('production', $loaded['config']['environment'], 'Environment must load exactly');
    $assertSame(true, $loaded['config']['feature_flags']['maintenance_mode'], 'Runtime overlay must merge');
    $assertSame(true, $loaded['config']['feature_flags']['financial_read_only'], 'Financial read-only must merge');
    $assertSame(true, $loaded['config']['database']['enabled'], 'Private database config must merge');
    $assertSame(true, $loaded['database_config_loaded'], 'Database config loaded marker must be true');
    $assertSame('completed', $loaded['cutover']['state'], 'Cutover JSON must load');
    $assertSame(true, $loaded['authorization']['authorized'], 'Authorization JSON must load');
    $assertTrue(
        preg_match('/\A[a-f0-9]{64}\z/', $loaded['output_root_fingerprint']) === 1,
        'Output root fingerprint must be safe SHA-256'
    );
    $assertTrue(
        preg_match('/\A[a-f0-9]{64}\z/', $loaded['database_config_fingerprint']) === 1,
        'Database config fingerprint must be available'
    );
    $assertSame(false, $loaded['paths_exposed'], 'Safe loader report must not claim path exposure');
    $assertSame(false, $loaded['production_changed'], 'Loader must not change production');

    chmod($authorizationFile, 0644);
    $assertThrows(
        static fn() => $loader->load(
            $configFile,
            $cutoverFile,
            $authorizationFile,
            $output
        ),
        'exact mode 0600'
    );
    chmod($authorizationFile, 0600);

    $wrongAuthorization = $private . '/wrong-name.json';
    $write($wrongAuthorization, "{}\n");
    $assertThrows(
        static fn() => $loader->load(
            $configFile,
            $cutoverFile,
            $wrongAuthorization,
            $output
        ),
        'filename is invalid'
    );

    chmod($output, 0755);
    $assertThrows(
        static fn() => $loader->load(
            $configFile,
            $cutoverFile,
            $authorizationFile,
            $output
        ),
        'exact mode 0700'
    );
    chmod($output, 0700);

    putenv('MGW_DATABASE_CONFIG_FILE=' . $root . '/outside-database.php');
    $write($root . '/outside-database.php', "<?php return ['database' => []];\n");
    $assertThrows(
        static fn() => $loader->load(
            $configFile,
            $cutoverFile,
            $authorizationFile,
            $output
        ),
        'exact private database.php'
    );

    putenv('MGW_DATABASE_CONFIG_FILE');
    $insideOutput = $project . '/rollback-exports';
    mkdir($insideOutput, 0700);
    chmod($insideOutput, 0700);
    $assertThrows(
        static fn() => $loader->load(
            $configFile,
            $cutoverFile,
            $authorizationFile,
            $insideOutput
        ),
        'outside the deployed project'
    );
} finally {
    if ($oldDatabaseOverride === false) {
        putenv('MGW_DATABASE_CONFIG_FILE');
    } else {
        putenv('MGW_DATABASE_CONFIG_FILE=' . $oldDatabaseOverride);
    }
    $remove($root);
}

fwrite(
    STDOUT,
    "ProductionPrimaryRollbackExportInputLoaderTest passed: {$assertions} assertions.\n"
);
