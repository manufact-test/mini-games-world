<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/storage/JsonDatabase.php';

$dir = sys_get_temp_dir() . '/mgw-json-reentrant-' . bin2hex(random_bytes(6));
mkdir($dir, 0755, true);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

try {
    $database = new JsonDatabase($dir);
    $database->transaction(static function (array &$db): void {
        $db['users']['u1'] = ['id' => 'u1', 'status' => 'idle'];
        $db['queue'][] = ['id' => 'q1', 'user_id' => 'u1', 'status' => 'waiting'];
    });

    $outer = $database->exclusiveReadOnly(function (array $snapshot) use ($database, $assert, &$assertions): array {
        $assert(isset($snapshot['users']['u1']), 'Outer exclusive snapshot must contain the stored user.');
        $assert(count($snapshot['queue']) === 1, 'Outer exclusive snapshot must contain the stored queue row.');

        $nestedRead = $database->readOnly(static fn(array $data): array => $data);
        $assert($nestedRead === $snapshot, 'Nested readOnly must reuse the exact active exclusive snapshot.');

        $nestedExclusive = $database->exclusiveReadOnly(static fn(array $data): array => $data);
        $assert($nestedExclusive === $snapshot, 'Nested exclusiveReadOnly must reuse the active exclusive snapshot without a second flock.');

        $usersOnly = $database->readOnlySections(['users'], static fn(array $data): array => $data);
        $assert(array_keys($usersOnly) === ['users'], 'Nested selective read must preserve the requested section boundary.');
        $assert($usersOnly['users'] === $snapshot['users'], 'Nested selective read must come from the active frozen snapshot.');

        return $snapshot;
    });

    $assert(isset($outer['users']['u1']), 'Exclusive callback result must be returned unchanged.');

    $database->transaction(static function (array &$db): void {
        $db['users']['u1']['status'] = 'searching';
    });
    $fresh = $database->readOnly(static fn(array $data): array => $data);
    $assert(($fresh['users']['u1']['status'] ?? '') === 'searching', 'Exclusive snapshot state must be cleared after callback completion.');

    $thrown = false;
    try {
        $database->exclusiveReadOnlySections(['users'], function (array $_snapshot) use ($database): void {
            $database->readOnlySections(['queue'], static fn(array $data): array => $data);
        });
    } catch (RuntimeException $error) {
        $thrown = str_contains($error->getMessage(), 'вне активного JSON-снимка');
    }
    $assert($thrown, 'Nested reads outside a selective active snapshot must fail closed instead of reacquiring app.lock.');
} finally {
    foreach (glob($dir . '/*') ?: [] as $path) @unlink($path);
    @rmdir($dir);
}

fwrite(STDOUT, "JsonDatabaseExclusiveSnapshotReentrancyTest: {$assertions} assertions passed\n");
