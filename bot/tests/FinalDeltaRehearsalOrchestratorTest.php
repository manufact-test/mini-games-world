<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/cutover/FinalDeltaRehearsalOrchestrator.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$directory = sys_get_temp_dir() . '/mgw-final-delta-orchestrator-' . bin2hex(random_bytes(5));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create orchestrator test directory.');
}

try {
    $steps = [];
    $sealed = false;
    $released = false;
    $stateFile = $directory . '/success.json';
    $orchestrator = new FinalDeltaRehearsalOrchestrator(
        function () use (&$steps): array {
            $steps[] = 'freeze';
            return ['ok' => true];
        },
        function () use (&$steps): array {
            $steps[] = 'drain';
            return ['drain' => [
                'ready' => true,
                'active_games' => 0,
                'queue_entries' => 0,
                'open_invites' => 0,
                'searching_users' => 0,
                'playing_users' => 0,
                'blockers' => [],
            ]];
        },
        function () use (&$sealed, &$released): array {
            return [
                'ok' => true,
                'freeze' => [
                    'active' => $sealed && !$released,
                    'sealed' => $sealed && !$released,
                    'storage_write_block_active' => $sealed && !$released,
                ],
                'frozen_snapshot' => ['ready' => $sealed && !$released],
                'control_consistency' => ['ok' => true],
            ];
        },
        function () use (&$steps, &$sealed): array {
            $steps[] = 'seal';
            $sealed = true;
            return [
                'ok' => true,
                'freeze' => ['active' => true, 'sealed' => true, 'storage_write_block_active' => true],
                'frozen_snapshot' => ['ready' => true],
                'control_consistency' => ['ok' => true],
            ];
        },
        function () use (&$steps): array {
            $steps[] = 'delta';
            return ['ok' => true, 'count_parity_complete' => true];
        },
        function () use (&$steps): array {
            $steps[] = 'snapshot';
            return [
                'ok' => true,
                'final_reconciliation' => ['ok' => true],
                'restore_rehearsal' => ['ok' => true],
            ];
        },
        function () use (&$steps, &$released): array {
            $steps[] = 'release';
            $released = true;
            return ['ok' => true];
        },
        function () use (&$steps, &$released): array {
            $steps[] = 'emergency';
            $released = true;
            return [
                'ok' => true,
                'freeze' => ['storage_write_block_active' => false],
                'control_consistency' => ['ok' => true],
            ];
        },
        $stateFile
    );

    $success = $orchestrator->run();
    $assertSame(true, $success['ok'], 'One-shot success must be ok');
    $assertSame(true, $success['complete'], 'One-shot success must be complete');
    $assertSame('completed', $success['state'], 'One-shot success must persist completed state');
    $assertSame(['freeze', 'drain', 'seal', 'delta', 'snapshot', 'release'], $steps, 'Steps must run in safe order');
    $assertSame(false, $success['release']['storage_write_block_active'], 'Success must release the write barrier');
    $repeat = $orchestrator->run();
    $assertSame(true, $repeat['idempotent'], 'Completed one-shot must be idempotent');
    $assertSame('run_noop', $repeat['action'], 'Completed one-shot must not start another freeze');
    $assertSame(6, count($steps), 'Completed repeat must execute no operational step');

    $waitingState = $directory . '/waiting.json';
    $drained = false;
    $waitingSteps = [];
    $waitingOrchestrator = new FinalDeltaRehearsalOrchestrator(
        function () use (&$waitingSteps): array { $waitingSteps[] = 'freeze'; return ['ok' => true]; },
        function () use (&$waitingSteps, &$drained): array {
            $waitingSteps[] = 'drain';
            return ['drain' => [
                'ready' => $drained,
                'active_games' => $drained ? 0 : 1,
                'queue_entries' => 0,
                'open_invites' => 0,
                'searching_users' => 0,
                'playing_users' => $drained ? 0 : 1,
                'blockers' => $drained ? [] : ['active games must drain to zero'],
            ]];
        },
        static fn(): array => [
            'freeze' => ['active' => false, 'sealed' => false, 'storage_write_block_active' => false],
            'control_consistency' => ['ok' => true],
        ],
        function () use (&$waitingSteps): array {
            $waitingSteps[] = 'seal';
            return [
                'freeze' => ['active' => true, 'sealed' => true, 'storage_write_block_active' => true],
                'frozen_snapshot' => ['ready' => true],
                'control_consistency' => ['ok' => true],
            ];
        },
        function () use (&$waitingSteps): array { $waitingSteps[] = 'delta'; return ['ok' => true]; },
        function () use (&$waitingSteps): array {
            $waitingSteps[] = 'snapshot';
            return ['ok' => true, 'final_reconciliation' => ['ok' => true], 'restore_rehearsal' => ['ok' => true]];
        },
        function () use (&$waitingSteps): array { $waitingSteps[] = 'release'; return ['ok' => true]; },
        static fn(): array => ['ok' => true, 'freeze' => ['storage_write_block_active' => false], 'control_consistency' => ['ok' => true]],
        $waitingState
    );
    $waiting = $waitingOrchestrator->run();
    $assertSame('waiting_for_drain', $waiting['state'], 'Active game must pause one-shot without releasing freeze');
    $assertSame(['freeze', 'drain'], $waitingSteps, 'Waiting run must not seal or reconcile');
    $drained = true;
    $waitingCompleted = $waitingOrchestrator->run();
    $assertSame('completed', $waitingCompleted['state'], 'Next scheduled run must resume after drain');

    $failureState = $directory . '/failure.json';
    $recoveries = 0;
    $failureOrchestrator = new FinalDeltaRehearsalOrchestrator(
        static fn(): array => ['ok' => true],
        static fn(): array => ['drain' => ['ready' => true, 'blockers' => []]],
        static fn(): array => ['freeze' => ['active' => false, 'sealed' => false], 'control_consistency' => ['ok' => true]],
        static fn(): array => [
            'freeze' => ['active' => true, 'sealed' => true, 'storage_write_block_active' => true],
            'frozen_snapshot' => ['ready' => true],
            'control_consistency' => ['ok' => true],
        ],
        static function (): array { throw new RuntimeException('planned delta failure'); },
        static fn(): array => [],
        static fn(): array => [],
        function () use (&$recoveries): array {
            $recoveries++;
            return [
                'ok' => true,
                'freeze' => ['storage_write_block_active' => false],
                'control_consistency' => ['ok' => true],
            ];
        },
        $failureState
    );
    $failure = $failureOrchestrator->run();
    $assertSame(false, $failure['ok'], 'Failed delta must fail the one-shot');
    $assertSame('final_delta', $failure['failed_step'], 'Failed step must be reported');
    $assertSame(true, $failure['recovery']['ok'], 'Failure must emergency-release the write barrier');
    $assertSame(1, $recoveries, 'Recovery must run exactly once');
    $failureRepeat = $failureOrchestrator->run();
    $assertSame('failed_noop', $failureRepeat['action'], 'Failed state must not auto-retry on the next Cron tick');
    $assertSame(1, $recoveries, 'Failed no-op must not repeat recovery or writes');

    $assertTrue(is_file($stateFile), 'Completed state must be stored privately');
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (!file_exists($path)) return;
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item === '.' || $item === '..') continue;
                $remove($path . '/' . $item);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    };
    $remove($directory);
}

fwrite(STDOUT, "FinalDeltaRehearsalOrchestratorTest passed: {$assertions} assertions.\n");
