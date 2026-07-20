<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function driver(): string;
    public function transaction(callable $callback): mixed;
    public function readOnly(callable $callback): mixed;
}
final class JsonStorageAdapter implements StorageAdapterInterface
{
    public function __construct(public string $dataDir) {}
    public function driver(): string { return 'json'; }
    public function transaction(callable $callback): mixed { $data = []; return $callback($data); }
    public function readOnly(callable $callback): mixed { return $callback([]); }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/storage/StorageFactory.php';

$assertions = 0;
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

$_SERVER['SCRIPT_FILENAME'] = '/private/api.php';
unset($GLOBALS['config'], $GLOBALS['configFile']);
$assertThrows(
    static fn() => StorageFactory::createJson('/private/json'),
    'requires the active application config context'
);

$GLOBALS['config'] = ['environment' => 'production'];
$GLOBALS['configFile'] = '/private/config.php';
$assertThrows(
    static fn() => StorageFactory::createJson('/private/json'),
    'previously failed in this request'
);

fwrite(STDOUT, "RuntimePrimaryStorageFactoryStickyFailureTest passed: {$assertions} assertions.\n");
