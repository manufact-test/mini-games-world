<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/storage/RuntimeModuleActivationController.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$writeRuntime = static function (string $file, bool $history): void {
    $runtime = [
        'database_runtime' => [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'realtime' => true,
                'economy' => true,
                'history' => $history,
            ],
        ],
    ];
    file_put_contents($file, "<?php\nreturn " . var_export($runtime, true) . ";\n");
};
$readHistory = static function (string $file): bool {
    $runtime = require $file;
    return !empty($runtime['database_runtime']['modules']['history']);
};
$removeTree = static function (string $directory): void {
    if (!is_dir($directory)) return;
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
        $path = $directory . '/' . $item;
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) @unlink($path . '/' . $child);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
};

$directory = sys_get_temp_dir() . '/mgw-history-activation-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$runtimeFile = $directory . '/runtime.php';
$stateFile = $directory . '/state.json';
$backupFile = $directory . '/backup.php';
$writeRuntime($runtimeFile, false);

$syncCalls = 0;
$auditCalls = 0;
$controller = new RuntimeModuleActivationController(
    'history',
    ['accounts', 'realtime', 'economy'],
    $runtimeFile,
    $stateFile,
    $backupFile,
    static function (array $runtime) use (&$syncCalls): array {
        $syncCalls++;
        return [
            'ok' => !empty($runtime['database_runtime']['modules']['history']),
            'source_user_count' => 1,
        ];
    },
    static function (array $runtime) use (&$auditCalls): array {
        $auditCalls++;
        return [
            'ok' => !empty($runtime['database_runtime']['modules']['history']),
            'read_only' => true,
            'mismatch_count' => 0,
            'blockers' => [],
        ];
    },
    'mvp-14.8.4e-history-runtime-activation'
);

$completed = $controller->run();
$assertSame(true, $completed['ok'], 'Activation must complete');
$assertSame('completed', $completed['state'], 'Activation state must be completed');
$assertSame('history', $completed['module'], 'Report must identify history module');
$assertSame(true, $readHistory($runtimeFile), 'Activation must enable history');
$assertSame(false, is_file($backupFile), 'Successful activation must remove recovery backup');
$assertSame(1, $syncCalls, 'Activation must synchronize once');
$assertSame(1, $auditCalls, 'Activation must audit once');

$repeat = $controller->run();
$assertSame('run_noop', $repeat['action'], 'Completed activation must become a no-op');
$assertSame(true, $repeat['idempotent'], 'Repeated activation must be idempotent');
$assertSame(1, $syncCalls, 'No-op must not synchronize again');
$assertSame(1, $auditCalls, 'No-op must not audit again');

$disabled = $controller->disable('test rollback');
$assertSame(true, $disabled['ok'], 'Disable must succeed');
$assertSame(false, $readHistory($runtimeFile), 'Disable must only turn history off');

$failedDirectory = sys_get_temp_dir() . '/mgw-history-activation-fail-' . bin2hex(random_bytes(6));
mkdir($failedDirectory, 0700, true);
$failedRuntime = $failedDirectory . '/runtime.php';
$writeRuntime($failedRuntime, false);
$failed = new RuntimeModuleActivationController(
    'history',
    ['accounts', 'realtime', 'economy'],
    $failedRuntime,
    $failedDirectory . '/state.json',
    $failedDirectory . '/backup.php',
    static fn(array $runtime): array => ['ok' => !empty($runtime['database_runtime']['modules']['history'])],
    static fn(array $runtime): array => ['ok' => false, 'blockers' => ['forced audit failure']],
    'mvp-14.8.4e-history-runtime-activation'
);
$failure = $failed->run();
$assertSame(false, $failure['ok'], 'Failed audit must fail activation');
$assertSame('audit', $failure['failed_step'], 'Failure must identify audit step');
$assertSame(true, $failure['rollback']['ok'], 'Failure must restore private runtime config');
$assertSame(false, $readHistory($failedRuntime), 'Failure rollback must disable history');
$assertSame(false, is_file($failedDirectory . '/backup.php'), 'Failure rollback must remove backup');

$prerequisiteDirectory = sys_get_temp_dir() . '/mgw-history-activation-prereq-' . bin2hex(random_bytes(6));
mkdir($prerequisiteDirectory, 0700, true);
$prerequisiteRuntime = $prerequisiteDirectory . '/runtime.php';
file_put_contents($prerequisiteRuntime, "<?php\nreturn " . var_export([
    'database_runtime' => [
        'enabled' => true,
        'modules' => ['accounts' => true, 'realtime' => true, 'economy' => false, 'history' => false],
    ],
], true) . ";\n");
$prerequisite = new RuntimeModuleActivationController(
    'history',
    ['accounts', 'realtime', 'economy'],
    $prerequisiteRuntime,
    $prerequisiteDirectory . '/state.json',
    $prerequisiteDirectory . '/backup.php',
    static fn(array $runtime): array => ['ok' => true],
    static fn(array $runtime): array => ['ok' => true, 'blockers' => []],
    'mvp-14.8.4e-history-runtime-activation'
);
$prerequisiteFailed = false;
try {
    $prerequisite->run();
} catch (RuntimeException $error) {
    $prerequisiteFailed = str_contains($error->getMessage(), 'economy');
}
$assertSame(true, $prerequisiteFailed, 'Missing economy dependency must block activation');
$assertSame(false, $readHistory($prerequisiteRuntime), 'Blocked activation must not change history flag');

$recoveryDirectory = sys_get_temp_dir() . '/mgw-history-activation-recover-' . bin2hex(random_bytes(6));
mkdir($recoveryDirectory, 0700, true);
$recoveryRuntime = $recoveryDirectory . '/runtime.php';
$recoveryBackup = $recoveryDirectory . '/backup.php';
$recoveryState = $recoveryDirectory . '/state.json';
$writeRuntime($recoveryRuntime, true);
$original = $recoveryDirectory . '/original.php';
$writeRuntime($original, false);
copy($original, $recoveryBackup);
file_put_contents($recoveryState, json_encode(['state' => 'activating'], JSON_THROW_ON_ERROR));
$recovery = new RuntimeModuleActivationController(
    'history',
    ['accounts', 'realtime', 'economy'],
    $recoveryRuntime,
    $recoveryState,
    $recoveryBackup,
    static fn(array $runtime): array => ['ok' => true],
    static fn(array $runtime): array => ['ok' => true, 'blockers' => []],
    'mvp-14.8.4e-history-runtime-activation'
);
$recovered = $recovery->run();
$assertSame('recovered_interrupted', $recovered['state'], 'Interrupted activation must recover first');
$assertSame(true, $recovered['rollback']['ok'], 'Interrupted activation must restore its backup');
$assertSame(false, $readHistory($recoveryRuntime), 'Interrupted recovery must leave history disabled');
$assertSame(false, is_file($recoveryBackup), 'Interrupted recovery must consume the backup');

$removeTree($directory);
$removeTree($failedDirectory);
$removeTree($prerequisiteDirectory);
$removeTree($recoveryDirectory);

fwrite(STDOUT, "RuntimeModuleActivationControllerTest passed: {$assertions} assertions.\n");
