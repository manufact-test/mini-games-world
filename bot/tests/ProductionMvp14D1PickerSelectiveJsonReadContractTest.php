<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$endpoint = $read('bot/invite-opponents.php');
$database = $read('bot/storage/JsonDatabase.php');
$adapter = $read('bot/storage/JsonStorageAdapter.php');
$capability = $read('bot/storage/contracts/SelectiveReadStorageInterface.php');
$client = $read('app/assets/js/games/game-invites-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($capability, 'interface SelectiveReadStorageInterface')
        && str_contains($capability, 'readOnlySections(array $sections, callable $callback)'),
    'Selective reads must be an explicit optional storage capability.'
);
$assert(
    str_contains($adapter, 'SelectiveReadStorageInterface')
        && str_contains($adapter, 'return $this->database->readOnlySections($sections, $callback);'),
    'The canonical JSON adapter must own the selective read capability.'
);
$assert(
    str_contains($database, 'public function readOnlySections(array $sections, callable $callback): mixed')
        && str_contains($database, 'return $this->readSectionsWithLock($sections, LOCK_SH, $callback);')
        && str_contains($database, 'private function readSectionsWithLock(')
        && str_contains($database, 'flock($lockHandle, $lockMode)')
        && str_contains($database, '$db = $this->loadSections($sections);')
        && str_contains($database, 'return $this->readOnlySections(array_keys(self::FILES), $callback);'),
    'Selective and legacy reads must retain shared-lock semantics through one section loader.'
);
$assert(
    str_contains($endpoint, '$storage instanceof SelectiveReadStorageInterface')
        && str_contains($endpoint, '$storage->readOnlySections([\'users\', \'games\'], $reader)')
        && str_contains($endpoint, ': $storage->readOnly($reader);'),
    'The picker must decode only users and games on JSON while preserving a correct fallback for other drivers.'
);
$assert(
    substr_count($endpoint, 'postJson') === 0
        && !str_contains($endpoint, 'transactions.json')
        && !str_contains($endpoint, 'notifications.json')
        && !str_contains($endpoint, 'invites.json')
        && !str_contains($endpoint, 'file_get_contents($config'),
    'The endpoint must not create a parallel storage reader or reach into unrelated runtime files directly.'
);
$assert(
    str_contains($client, 'const result = await postJson(OPPONENTS_URL, {});')
        && substr_count($client, 'postJson(OPPONENTS_URL, {})') === 1
        && !str_contains($client, 'Загружаем соперников')
        && str_contains($client, 'playerPickerRequestGeneration'),
    'Server acceleration must preserve the accepted one-request ready-first client owner.'
);
$assert(
    str_contains($shell, './games/game-invites-v110.js?v=1130'),
    'A server-only storage optimization must not invent a new client graph or cache identity.'
);

fwrite(STDOUT, "ProductionMvp14D1PickerSelectiveJsonReadContractTest: {$assertions} assertions passed\n");
