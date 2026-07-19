<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/operations/StagingOperationDefinition.php';
require $root . '/operations/StagingOperationsRunner.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $value, string $message) use (&$assertions): void {
    $assertions++;
    if (!$value) throw new RuntimeException($message);
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

$directory = sys_get_temp_dir() . '/mgw-staging-runner-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$stateFile = $directory . '/state.json';
$clockTick = 0;
$clock = static function () use (&$clockTick): string {
    $clockTick++;
    return sprintf('2026-07-19T15:%02d:00+00:00', min($clockTick, 59));
};

$successCalls = 0;
$success = new StagingOperationDefinition(
    'test.success.v1',
    'build-test',
    static function () use (&$successCalls): array {
        $successCalls++;
        return [
            'ok' => true,
            'blockers' => [],
            'summary' => [
                'count' => 3,
                'user_id' => 'user_secret_123',
                'nested' => ['email' => 'secret@example.com', 'fingerprint' => str_repeat('a', 64)],
            ],
        ];
    }
);
$runner = new StagingOperationsRunner('build-test', $stateFile, [$success], Closure::fromCallable($clock));
$completed = $runner->run();
$assertSame(true, $completed['ok'], 'Successful operation must complete');
$assertSame('completed', $completed['runner_state'], 'Successful runner state must be completed');
$assertSame('test.success.v1', $completed['operation_id'], 'Runner must report the executed operation');
$assertSame(1, $successCalls, 'Successful operation must execute once');
$assertSame(false, array_key_exists('user_id', $completed['operation_result']['summary']), 'Nested user IDs must be redacted');
$assertSame(false, array_key_exists('email', $completed['operation_result']['summary']['nested']), 'Nested emails must be redacted');
$assertSame(str_repeat('a', 64), $completed['operation_result']['summary']['nested']['fingerprint'], 'Safe fingerprints must remain visible');
$assertSame(false, $completed['rollback']['attempted'], 'Successful operation must not roll back');
$assertTrue(is_file($stateFile), 'Runner must persist private state');

$repeat = $runner->run();
$assertSame(true, $repeat['ok'], 'Completed runner no-op must remain healthy');
$assertSame('run_noop', $repeat['action'], 'Completed operation must become a no-op');
$assertSame(true, $repeat['idempotent'], 'Completed operation no-op must be idempotent');
$assertSame(1, $successCalls, 'Completed operation must not execute twice');

$failureState = $directory . '/failure.json';
$failureCalls = 0;
$rollbackCalls = 0;
$failure = new StagingOperationDefinition(
    'test.failure.v1',
    'build-test',
    static function () use (&$failureCalls): array {
        $failureCalls++;
        return ['ok' => false, 'blockers' => ['forced blocker'], 'account_ref' => 'account_secret'];
    },
    static function (?array $result, ?Throwable $error) use (&$rollbackCalls): array {
        $rollbackCalls++;
        return [
            'ok' => $result !== null && $error === null,
            'restored' => true,
            'legacy_user_id' => 'user_secret_rollback',
        ];
    }
);
$failureRunner = new StagingOperationsRunner('build-test', $failureState, [$failure], Closure::fromCallable($clock));
$failed = $failureRunner->run();
$assertSame(false, $failed['ok'], 'Blocked operation must fail closed');
$assertSame('failed', $failed['runner_state'], 'Blocked operation runner state must be failed');
$assertSame(1, $failureCalls, 'Blocked operation must execute once');
$assertSame(1, $rollbackCalls, 'Blocked operation must invoke rollback once');
$assertSame(true, $failed['rollback']['attempted'], 'Rollback must be marked attempted');
$assertSame(true, $failed['rollback']['ok'], 'Successful rollback must be reported');
$assertSame(false, array_key_exists('legacy_user_id', $failed['rollback']), 'Rollback identifiers must be redacted');
$assertSame(false, array_key_exists('account_ref', $failed['operation_result']), 'Operation identifiers must be redacted');

$failedRepeat = $failureRunner->run();
$assertSame(false, $failedRepeat['ok'], 'Failed operation no-op must stay unhealthy');
$assertSame('run_noop', $failedRepeat['action'], 'Failed operation must not retry forever');
$assertSame(true, $failedRepeat['idempotent'], 'Failed no-op must be idempotent');
$assertSame(1, $failureCalls, 'Failed operation must not execute again without a new revision');
$assertSame(1, $rollbackCalls, 'Failed operation rollback must not repeat');

$interruptedState = $directory . '/interrupted.json';
file_put_contents($interruptedState, json_encode([
    'schema_version' => 1,
    'operations' => [
        'test.interrupted.v1' => [
            'id' => 'test.interrupted.v1',
            'build' => 'build-test',
            'state' => 'running',
            'attempts' => 1,
        ],
    ],
], JSON_THROW_ON_ERROR));
$interruptedCalls = 0;
$interrupted = new StagingOperationDefinition(
    'test.interrupted.v1',
    'build-test',
    static function () use (&$interruptedCalls): array {
        $interruptedCalls++;
        return ['ok' => true, 'blockers' => []];
    }
);
$interruptedRunner = new StagingOperationsRunner(
    'build-test',
    $interruptedState,
    [$interrupted],
    Closure::fromCallable($clock)
);
$recovered = $interruptedRunner->run();
$assertSame(true, $recovered['ok'], 'Interrupted operation must be recoverable');
$assertSame(1, $interruptedCalls, 'Interrupted operation must execute once on recovery');
$interruptedPersisted = json_decode((string)file_get_contents($interruptedState), true, 512, JSON_THROW_ON_ERROR);
$assertSame(true, $interruptedPersisted['operations']['test.interrupted.v1']['recovered_interrupted'], 'Recovery must be recorded');
$assertSame(2, $interruptedPersisted['operations']['test.interrupted.v1']['attempts'], 'Recovery must increment attempts');

$revisionState = $directory . '/revision.json';
file_put_contents($revisionState, json_encode([
    'schema_version' => 1,
    'operations' => [
        'test.revision' => [
            'id' => 'test.revision',
            'build' => 'old-build',
            'state' => 'completed',
            'attempts' => 1,
        ],
    ],
], JSON_THROW_ON_ERROR));
$revisionCalls = 0;
$revision = new StagingOperationDefinition(
    'test.revision',
    'new-build',
    static function () use (&$revisionCalls): array {
        $revisionCalls++;
        return ['ok' => true, 'blockers' => []];
    }
);
$revisionRunner = new StagingOperationsRunner('new-build', $revisionState, [$revision], Closure::fromCallable($clock));
$revisionResult = $revisionRunner->run();
$assertSame(true, $revisionResult['ok'], 'A build revision must not reuse stale completion state');
$assertSame(1, $revisionCalls, 'A build revision must execute');

$exceptionState = $directory . '/exception.json';
$exceptionRollbackCalls = 0;
$exception = new StagingOperationDefinition(
    'test.exception.v1',
    'build-test',
    static function (): array {
        throw new RuntimeException('/home/private/project user_secret_999 secret@example.com');
    },
    static function (?array $result, ?Throwable $error) use (&$exceptionRollbackCalls): array {
        $exceptionRollbackCalls++;
        return ['ok' => $result === null && $error instanceof RuntimeException];
    }
);
$exceptionRunner = new StagingOperationsRunner('build-test', $exceptionState, [$exception], Closure::fromCallable($clock));
$exceptionResult = $exceptionRunner->run();
$assertSame(false, $exceptionResult['ok'], 'Exception must fail the operation');
$assertSame(1, $exceptionRollbackCalls, 'Exception must invoke rollback');
$assertTrue(!str_contains($exceptionResult['error_message'], '/home/private'), 'Private paths must be redacted');
$assertTrue(!str_contains($exceptionResult['error_message'], 'user_secret_999'), 'Identifiers in errors must be redacted');
$assertTrue(!str_contains($exceptionResult['error_message'], 'secret@example.com'), 'Emails in errors must be redacted');

$foreignCalls = 0;
$foreign = new StagingOperationDefinition(
    'test.foreign.v1',
    'another-build',
    static function () use (&$foreignCalls): array {
        $foreignCalls++;
        return ['ok' => true];
    }
);
$foreignRunner = new StagingOperationsRunner('build-test', $directory . '/foreign.json', [$foreign], Closure::fromCallable($clock));
$foreignResult = $foreignRunner->run();
$assertSame(0, $foreignResult['eligible_operation_count'], 'Foreign-build operations must not be eligible');
$assertSame(0, $foreignCalls, 'Foreign-build operations must not execute');
$assertSame('run_noop', $foreignResult['action'], 'Empty manifest must be an idempotent no-op');

$removeTree($directory);
fwrite(STDOUT, "StagingOperationsRunnerTest passed: {$assertions} assertions.\n");
