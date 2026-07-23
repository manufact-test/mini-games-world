<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require $projectRoot . '/bot/storage/JsonDatabase.php';
require $projectRoot . '/bot/storage/JsonStorageAdapter.php';
require $projectRoot . '/bot/storage/StorageFactory.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$containsDirectJsonDatabaseConstruction = static function (string $source): bool {
    $tokens = token_get_all($source);
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_NEW) continue;

        $name = '';
        for ($cursor = $index + 1; $cursor < $tokenCount; $cursor++) {
            $part = $tokens[$cursor];
            if (is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($part) && in_array($part[0], [
                T_STRING,
                T_NAME_QUALIFIED,
                T_NAME_FULLY_QUALIFIED,
                T_NAME_RELATIVE,
                T_NS_SEPARATOR,
            ], true)) {
                $name .= $part[1];
                continue;
            }
            if ($part === '\\') {
                $name .= '\\';
                continue;
            }
            break;
        }

        $name = ltrim($name, '\\');
        $separator = strrpos($name, '\\');
        if ($separator !== false) {
            $name = substr($name, $separator + 1);
        }
        if ($name === 'JsonDatabase') return true;
    }

    return false;
};

$root = sys_get_temp_dir() . '/mgw-storage-adapter-test-' . bin2hex(random_bytes(5));
$dataDir = $root . '/data';

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
};

try {
    $storage = StorageFactory::create([
        'storage_driver' => 'json',
        'data_dir' => $dataDir,
    ]);

    $assertTrue($storage instanceof StorageAdapterInterface, 'Factory must return the storage adapter contract');
    $assertSame('json', $storage->driver(), 'JSON adapter must expose its driver');

    $storage->transaction(static function (array &$data): void {
        $data['users']['user_1'] = ['id' => 'user_1', 'balance_match' => 50];
    });

    $storedUser = $storage->readOnly(static fn(array $data): ?array => $data['users']['user_1'] ?? null);
    $assertSame('user_1', $storedUser['id'] ?? null, 'Transaction changes must persist through the adapter');

    $storage->readOnly(static function (array &$data): void {
        $data['users']['user_1']['balance_match'] = 999;
    });
    $balanceAfterRead = $storage->readOnly(static fn(array $data): int => (int)($data['users']['user_1']['balance_match'] ?? 0));
    $assertSame(50, $balanceAfterRead, 'Read-only callback changes must not persist');

    $rolledBack = false;
    try {
        $storage->transaction(static function (array &$data): void {
            $data['users']['user_1']['balance_match'] = 1;
            throw new RuntimeException('rollback-test');
        });
    } catch (RuntimeException $error) {
        $rolledBack = $error->getMessage() === 'rollback-test';
    }
    $assertTrue($rolledBack, 'Transaction exception must be propagated');
    $balanceAfterRollback = $storage->readOnly(static fn(array $data): int => (int)($data['users']['user_1']['balance_match'] ?? 0));
    $assertSame(50, $balanceAfterRollback, 'Failed transaction must not persist changes');

    $unsupportedBlocked = false;
    try {
        StorageFactory::create(['storage_driver' => 'mysql', 'data_dir' => $dataDir]);
    } catch (RuntimeException $error) {
        $unsupportedBlocked = str_contains($error->getMessage(), 'Unsupported storage driver');
    }
    $assertTrue($unsupportedBlocked, 'Unsupported storage driver must fail closed');

    $assertTrue(
        !$containsDirectJsonDatabaseConstruction("<?php \$needle = 'new JsonDatabase(';"),
        'Static adapter scan must ignore JsonDatabase text inside string literals'
    );
    $assertTrue(
        $containsDirectJsonDatabaseConstruction('<?php $database = new JsonDatabase($path);'),
        'Static adapter scan must detect real direct JsonDatabase construction'
    );

    $directInstantiations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot . '/bot', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $projectRoot))), '/');
        if (in_array($relative, [
            'bot/storage/JsonStorageAdapter.php',
            'bot/tests/StorageAdapterTest.php',
        ], true)) {
            continue;
        }
        $source = file_get_contents($path) ?: '';
        if ($containsDirectJsonDatabaseConstruction($source)) {
            $directInstantiations[] = $relative;
        }
    }
    sort($directInstantiations, SORT_STRING);
    $assertSame([], $directInstantiations, 'Direct JsonDatabase construction must be isolated inside JsonStorageAdapter');

    fwrite(STDOUT, "StorageAdapterTest: {$assertions} assertions passed\n");
} finally {
    $remove($root);
}
