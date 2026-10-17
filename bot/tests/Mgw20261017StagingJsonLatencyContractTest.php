<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/storage/JsonDatabase.php';

$assertions = 0;
$assert = static function (bool $ok, string $message) use (&$assertions): void {
    if (!$ok) throw new RuntimeException($message);
    $assertions++;
};

$api = file_get_contents(dirname(__DIR__) . '/api.php');
$source = file_get_contents(dirname(__DIR__) . '/storage/JsonDatabase.php');
$assert(is_string($api) && str_contains($api, "'environment'] ?? ''))) === 'staging'"),
    'Gameplay timing must only be enabled by staging API routing.');
$assert(is_string($source) && str_contains($source, 'writer_wait_ms=%.1f')
    && str_contains($source, 'cleanup_ms=%.1f'),
    'Measure both writer lock wait and periodic cross-engine cleanup, not only HTTP time.');
$assert(strpos($source, "fclose(\$writeBarrierHandle);\n            // Never perform filesystem logging") !== false,
    'Timing log must run after both gameplay locks have been closed.');
$assert(!str_contains($source, 'mgw_staging_json_latency_trace_user_id'),
    'No account identifiers may be added to anonymous timing logs.');

$directory = sys_get_temp_dir() . '/mgw-json-latency-'.bin2hex(random_bytes(8));
try {
    $storage = new JsonDatabase($directory);
    $GLOBALS['mgw_staging_json_latency_trace'] = 'battleship_fire';
    $value = $storage->transaction(static function (array &$data): string {
        $data['users']['regression-staging-player'] = ['balance' => 100];
        return 'saved';
    });
    $assert($value === 'saved', 'Traced transactions must retain callback return values.');
    $saved = $storage->readOnlySections(['users'], static fn(array $snapshot): array => $snapshot['users']);
    $assert(($saved['regression-staging-player']['balance'] ?? 0) === 100,
        'Traced transactions must persist the exact original write.');
    unset($GLOBALS['mgw_staging_json_latency_trace']);
    $storage->transaction(static function (array &$data): void {
        $data['users']['regression-staging-player']['balance'] = 75;
    });
    $result = $storage->readOnlySections(['users'], static fn(array $snapshot): array => $snapshot['users']);
    $assert(($result['regression-staging-player']['balance'] ?? 0) === 75,
        'Untraced transactions must preserve ordinary writer semantics.');
} finally {
    unset($GLOBALS['mgw_staging_json_latency_trace']);
    foreach (glob($directory.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}
echo "MGW staging JSON latency contract OK ({$assertions} assertions).\n";
