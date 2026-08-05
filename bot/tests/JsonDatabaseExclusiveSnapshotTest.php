<?php
declare(strict_types=1);

require dirname(__DIR__) . '/storage/JsonDatabase.php';

$mode = (string)($argv[1] ?? 'parent');
if ($mode === 'child') {
    $dataDir = (string)($argv[2] ?? '');
    $readyFile = (string)($argv[3] ?? '');
    $resultFile = (string)($argv[4] ?? '');
    $database = new JsonDatabase($dataDir);
    file_put_contents($readyFile, 'ready');
    $started = microtime(true);
    $database->transaction(static function (array &$data): void {
        $data['invites'][] = ['id' => 'child-write'];
    });
    file_put_contents($resultFile, json_encode([
        'elapsed' => microtime(true) - $started,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$root = sys_get_temp_dir() . '/mgw-exclusive-snapshot-' . bin2hex(random_bytes(8));
$readyFile = $root . '/child.ready';
$resultFile = $root . '/child.result.json';
$process = null;
$pipes = [];

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        if (is_file($path)) @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

try {
    $database = new JsonDatabase($root);
    $database->transaction(static function (array &$data): void {
        $data['invites'] = [['id' => 'initial']];
    });

    $database->exclusiveReadOnlySections(
        ['invites'],
        function (array $snapshot) use (
  &$process,
  &$pipes,
  $root,
  $readyFile,
  $resultFile,
  $assert
        ): void {
  $assert(array_keys($snapshot) === ['invites'],
      'Exclusive snapshot must decode only requested sections.');
  $assert(($snapshot['invites'][0]['id'] ?? '') === 'initial',
      'Exclusive snapshot must expose the stable pre-writer state.');

  $process = proc_open(
      [PHP_BINARY, __FILE__, 'child', $root, $readyFile, $resultFile],
      [
          0 => ['pipe', 'r'],
          1 => ['pipe', 'w'],
          2 => ['pipe', 'w'],
      ],
      $pipes
  );
  $assert(is_resource($process), 'Child JSON writer must start.');
  fclose($pipes[0]);

  $deadline = microtime(true) + 3.0;
  while (!is_file($readyFile) && microtime(true) < $deadline) usleep(10000);
  $assert(is_file($readyFile), 'Child writer must reach the lock attempt.');
  usleep(180000);
  $status = proc_get_status($process);
  $assert(!empty($status['running']),
      'JSON writer must remain blocked while the exclusive snapshot callback runs.');
        }
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $assert($exitCode === 0, 'Child JSON writer must finish after lock release: ' . $stderr . $stdout);
    $timing = json_decode(file_get_contents($resultFile) ?: '{}', true);
    $assert(is_array($timing) && (float)($timing['elapsed'] ?? 0) >= 0.15,
        'Child writer must measure the exclusive snapshot blocking interval.');

    $final = $database->readOnlySections(
        ['invites'],
        static fn(array $data): array => $data['invites'] ?? []
    );
    $assert(count($final) === 2 && ($final[1]['id'] ?? '') === 'child-write',
        'Blocked writer must commit normally after the snapshot lock is released.');

    fwrite(STDOUT, "JsonDatabaseExclusiveSnapshotTest: {$assertions} assertions passed\n");
} finally {
    if (is_resource($process)) @proc_terminate($process);
    $removeTree($root);
}
