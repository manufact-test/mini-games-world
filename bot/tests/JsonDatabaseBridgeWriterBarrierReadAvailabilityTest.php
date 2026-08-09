<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/storage/JsonDatabase.php';

$dir = sys_get_temp_dir() . '/mgw-json-bridge-writer-barrier-' . bin2hex(random_bytes(6));
mkdir($dir, 0755, true);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

try {
    $database = new JsonDatabase($dir);
    $database->transaction(static function (array &$db): void {
        $db['users']['u1'] = ['id' => 'u1', 'status' => 'playing'];
        $db['games']['g1'] = ['id' => 'g1', 'status' => 'active', 'player_ids' => ['u1']];
    });

    $database->exclusiveReadOnly(function (array $snapshot) use ($database, $dir, $assert): void {
        $assert(isset($snapshot['games']['g1']), 'Bridge snapshot must contain committed game state.');

        $readerHandle = fopen($dir . '/app.lock', 'c+');
        if (!$readerHandle) throw new RuntimeException('Cannot open app.lock in reader assertion.');
        $readerAvailable = flock($readerHandle, LOCK_SH | LOCK_NB);
        $assert($readerAvailable, 'Read-only clients must not be blocked by DB bridge projection.');
        if ($readerAvailable) flock($readerHandle, LOCK_UN);
        fclose($readerHandle);

        $barrierHandle = fopen($dir . '/app-write-barrier.lock', 'c+');
        if (!$barrierHandle) throw new RuntimeException('Cannot open writer barrier in assertion.');
        $writerCanJoin = flock($barrierHandle, LOCK_SH | LOCK_NB);
        $assert(!$writerCanJoin, 'DB bridge must keep the writer barrier exclusive through projection.');
        if ($writerCanJoin) flock($barrierHandle, LOCK_UN);
        fclose($barrierHandle);

        $nested = $database->readOnly(static fn(array $data): array => $data);
        $assert($nested === $snapshot, 'Nested reads must reuse the exact frozen bridge snapshot.');
    });

    $barrierHandle = fopen($dir . '/app-write-barrier.lock', 'c+');
    if (!$barrierHandle) throw new RuntimeException('Cannot reopen writer barrier after bridge callback.');
    $barrierReleased = flock($barrierHandle, LOCK_SH | LOCK_NB);
    $assert($barrierReleased, 'Writer barrier must be released after bridge callback.');
    if ($barrierReleased) flock($barrierHandle, LOCK_UN);
    fclose($barrierHandle);

    $database->transaction(static function (array &$db): void {
        $db['games']['g1']['status'] = 'finished';
    });
    $fresh = $database->readOnly(static fn(array $data): array => $data);
    $assert(($fresh['games']['g1']['status'] ?? '') === 'finished', 'Normal writer must proceed after bridge callback.');
} finally {
    foreach (glob($dir . '/*') ?: [] as $path) @unlink($path);
    @rmdir($dir);
}

fwrite(STDOUT, "JsonDatabaseBridgeWriterBarrierReadAvailabilityTest: {$assertions} assertions passed\n");
