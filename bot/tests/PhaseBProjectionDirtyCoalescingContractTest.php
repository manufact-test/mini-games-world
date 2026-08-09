<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/storage/contracts/SelectiveReadStorageInterface.php';
require $root . '/storage/contracts/ExclusiveSnapshotStorageInterface.php';
require $root . '/storage/contracts/ProjectionDirtyStorageInterface.php';
require $root . '/storage/JsonDatabase.php';
require $root . '/storage/JsonStorageAdapter.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/weekly/WeeklyBonusRuntimeBridge.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (!$actual) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $needle, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $needle)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage(), 0, $error);
    }
    throw new RuntimeException($message . ': expected exception was not thrown');
};

$tempDir = sys_get_temp_dir() . '/mgw_phase_b_projection_dirty_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Could not create projection dirty test directory.');
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        if (is_file($path)) @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . '/' . $item;
        if (is_dir($child)) $removeTree($child); else @unlink($child);
    }
    @rmdir($path);
};

try {
    $storage = new JsonStorageAdapter($tempDir);
    $assertTrue($storage instanceof ProjectionDirtyStorageInterface, 'JSON adapter must expose durable projection dirty state');
    $assertSame(false, $storage->runtimeProjectionDirty(), 'Fresh JSON storage must start projection-clean');

    $storage->transaction(static function (array &$db): void {
        // Exact no-op transaction: common polling must not manufacture projection work.
    });
    $assertSame(false, $storage->runtimeProjectionDirty(), 'No-op JSON transaction must stay projection-clean');

    $storage->transaction(static function (array &$db): void {
        $db['system']['diagnostic_only'] = 'irrelevant';
        $db['support'][] = ['id' => 'support_only'];
    });
    $assertSame(false, $storage->runtimeProjectionDirty(), 'Irrelevant system/support changes must not dirty the API projection bundle');

    foreach (['users', 'games', 'queue', 'transactions', 'notifications', 'invites'] as $section) {
        $storage->transaction(static function (array &$db) use ($section): void {
            $db[$section][] = ['id' => 'dirty_' . $section . '_' . count($db[$section] ?? [])];
        });
        $assertSame(true, $storage->runtimeProjectionDirty(), $section . ' mutation must set durable projection dirty marker');

        $assertThrows(
            static fn() => $storage->clearRuntimeProjectionDirty(),
            'only be cleared inside an exclusive JSON snapshot',
            'Dirty marker must not be clearable outside frozen projection ownership'
        );

        $storage->exclusiveReadOnly(static function (array $snapshot) use ($storage): void {
            $storage->clearRuntimeProjectionDirty();
        });
        $assertSame(false, $storage->runtimeProjectionDirty(), $section . ' marker must clear inside exclusive snapshot');
    }

    $assertThrows(
        static function () use ($storage): void {
            $storage->transaction(static function (array &$db): void {
                $db['users'][] = ['id' => 'must_not_publish'];
                throw new RuntimeException('callback_abort');
            });
        },
        'callback_abort',
        'Aborted transaction must propagate callback failure'
    );
    $assertSame(false, $storage->runtimeProjectionDirty(), 'Aborted transaction before publication must not create dirty marker');

    $config = [
        'environment' => 'staging',
        'storage_driver' => 'json',
        'database' => [
            'enabled' => true,
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'mgw_contract',
            'user' => 'mgw_contract',
            'password' => 'contract-password',
            'charset' => 'utf8mb4',
        ],
        'feature_flags' => [
            'database_runtime' => [
                'enabled' => true,
                'modules' => [
                    'accounts' => true,
                    'realtime' => true,
                    'economy' => true,
                    'history' => true,
                    'weekly_bonus' => true,
                ],
            ],
        ],
    ];
    $router = new RuntimeStorageRouter($config);
    $bridge = new WeeklyBonusRuntimeBridge($config, $router, null, $storage);
    $cleanResult = $bridge->synchronizeCurrentJsonIfDirty();
    $assertSame('skip_clean', (string)($cleanResult['action'] ?? ''), 'Clean API projection must return before DB/runtime dependencies');
    $assertSame(false, $storage->runtimeProjectionDirty(), 'Clean fast path must leave marker clean');

    $weeklySource = (string)file_get_contents($root . '/weekly/WeeklyBonusRuntimeBridge.php');
    $bootstrapSource = (string)file_get_contents($root . '/core/bootstrap.php');
    $manifestSource = (string)file_get_contents($root . '/helpers/staging-e2e-runtime-files.txt');

    $precheckPos = strpos($weeklySource, 'if (!$dirty)');
    $exclusivePos = strpos($weeklySource, 'exclusiveReadOnly(');
    $recheckPos = strpos($weeklySource, "'skip_coalesced'");
    $notificationFailurePos = strpos($weeklySource, 'Weekly bonus notification runtime parity failed.');
    $clearPos = strpos($weeklySource, 'clearRuntimeProjectionDirty();');
    $assertTrue($precheckPos !== false && $exclusivePos !== false && $precheckPos < $exclusivePos, 'Clean precheck must happen before exclusive barrier acquisition');
    $assertTrue($recheckPos !== false && $exclusivePos < $recheckPos, 'Dirty marker must be rechecked after exclusive barrier acquisition');
    $assertTrue($notificationFailurePos !== false && $clearPos !== false && $notificationFailurePos < $clearPos, 'Dirty marker may clear only after notification parity succeeds');
    $assertTrue(!str_contains(substr($weeklySource, max(0, $clearPos - 100), 200), 'finally'), 'Dirty marker clear must never live in a finally block');
    $assertTrue(str_contains($bootstrapSource, 'synchronizeCurrentJsonIfDirty();'), 'api.php Weekly hook must use conditional dirty synchronization');
    $assertTrue(str_contains($weeklySource, 'public function synchronizeCurrentJson(): ?array'), 'Forced full Weekly synchronization method must remain available');
    $assertTrue(str_contains($manifestSource, 'bot/storage/contracts/ProjectionDirtyStorageInterface.php'), 'Exact staging fingerprint must include dirty marker contract');

    fwrite(STDOUT, "PhaseBProjectionDirtyCoalescingContractTest passed: {$assertions} assertions.\n");
} finally {
    $removeTree($tempDir);
}
