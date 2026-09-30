<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseExceptionClassifier.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/weekly/RuntimeWeeklyBonusRepository.php';
require $root . '/weekly/WeeklyBonusRuntimeBridge.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp26_2FreshAccountWeeklyBootstrapTest requires pdo_sqlite.');
}

final class Mvp26FreshAccountStorage implements StorageAdapterInterface
{
    public function __construct(private array $snapshot) {}

    public function driver(): string
    {
        return RuntimeStorageRouter::DRIVER_JSON;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this->snapshot);
    }

    public function readOnly(callable $callback): mixed
    {
        return $callback($this->snapshot);
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$config = [
    'environment'=>'staging',
    'storage_driver'=>'json',
    'database'=>[
        'enabled'=>true,
        'driver'=>'mysql',
        'host'=>'localhost',
        'port'=>3306,
        'name'=>'mgw_test',
        'user'=>'mgw_test',
        'password'=>'test-only',
        'charset'=>'utf8mb4',
    ],
    'feature_flags'=>[
        'database_runtime'=>[
            'enabled'=>true,
            'modules'=>[
                'accounts'=>true,
                'realtime'=>true,
                'economy'=>true,
                'history'=>true,
                'weekly_bonus'=>true,
            ],
        ],
    ],
];

$legacyUserId = 'android_' . str_repeat('a', 64);
$snapshot = [
    'users'=>[
        $legacyUserId=>[
            'id'=>$legacyUserId,
            'is_dev_user'=>false,
        ],
    ],
];

$pdo = new PDO('sqlite::memory:');
$database = new PdoDatabaseConnection($pdo);
$database->execute(
    'CREATE TABLE mgw_runtime_weekly_bonus_state (
        legacy_user_id TEXT NOT NULL,
        status_json TEXT NOT NULL,
        status_sha256 TEXT NOT NULL
    )'
);

$router = new RuntimeStorageRouter($config);
$storage = new Mvp26FreshAccountStorage($snapshot);
$repository = new RuntimeWeeklyBonusRepository($config, $router, $storage, $database);
$bridge = new WeeklyBonusRuntimeBridge($config, $router, $repository, $storage);

$canonicalActionStatus = [
    'enabled'=>true,
    'timezone'=>'Europe/Moscow',
    'starter_amount'=>1000,
    'first_game_grant_count'=>0,
];

$bootstrap = [
    'user'=>['id'=>$legacyUserId],
    'weekly_match'=>$canonicalActionStatus,
];
$normalizedBootstrap = $bridge->normalizeApiData($bootstrap, 'bootstrap');
$assertSame(
    $canonicalActionStatus,
    $normalizedBootstrap['weekly_match'],
    'Fresh provider-neutral bootstrap must keep its authoritative action-local weekly status while DB projection is pending.'
);

$normalizedFastPath = $bridge->normalizeApiData($bootstrap, 'start_search');
$assertSame(
    $canonicalActionStatus,
    $normalizedFastPath['weekly_match'],
    'Latency-critical API actions must not fail solely because a fresh weekly projection row is still pending.'
);

$dbStatus = ['enabled'=>true,'timezone'=>'Europe/Moscow','source'=>'database_projection'];
$dbJson = json_encode($dbStatus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$database->execute(
    'INSERT INTO mgw_runtime_weekly_bonus_state (legacy_user_id,status_json,status_sha256)
     VALUES (:legacy_user_id,:status_json,:status_sha256)',
    [
        'legacy_user_id'=>$legacyUserId,
        'status_json'=>$dbJson,
        'status_sha256'=>hash('sha256', $dbJson),
    ]
);
$normalizedProjected = $bridge->normalizeApiData($bootstrap, 'bootstrap');
$assertSame(
    $dbStatus,
    $normalizedProjected['weekly_match'],
    'Once present, the verified DB weekly projection must resume normal response ownership.'
);

$database->execute(
    'INSERT INTO mgw_runtime_weekly_bonus_state (legacy_user_id,status_json,status_sha256)
     VALUES (:legacy_user_id,:status_json,:status_sha256)',
    [
        'legacy_user_id'=>$legacyUserId,
        'status_json'=>$dbJson,
        'status_sha256'=>hash('sha256', $dbJson),
    ]
);
$assertThrows(
    static fn() => $bridge->normalizeApiData($bootstrap, 'bootstrap'),
    'Weekly bonus DB state is ambiguous.',
    'Duplicate DB weekly state must remain fail-closed and must never fall back to JSON.'
);

fwrite(STDOUT, "Mvp26_2FreshAccountWeeklyBootstrapTest: {$assertions} assertions passed\n");
