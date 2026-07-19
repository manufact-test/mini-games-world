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

$directory = sys_get_temp_dir() . '/mgw-sealed-resume-' . bin2hex(random_bytes(5));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create sealed-resume test directory.');
}

try {
    $steps = [];
    $released = false;
    $sealStatus = function () use (&$released): array {
        return [
            'ok' => true,
            'freeze' => [
                'active' => !$released,
                'sealed' => !$released,
                'storage_write_block_active' => !$released,
            ],
            'drain' => [
                'ready' => !$released,
                'active_games' => 0,
                'queue_entries' => 0,
                'open_invites' => 0,
                'searching_users' => 0,
                'playing_users' => 0,
                'blockers' => [],
            ],
            'frozen_snapshot' => ['ready' => !$released],
            'control_consistency' => ['ok' => true],
        ];
    };
    $orchestrator = new FinalDeltaRehearsalOrchestrator(
        function () use (&$steps): array { $steps[] = 'freeze'; return ['ok' => true]; },
        function () use (&$steps): array { $steps[] = 'legacy-drain'; return ['drain' => ['ready' => false]]; },
        $sealStatus,
        function () use (&$steps): array { $steps[] = 'seal'; return []; },
        function () use (&$steps): array { $steps[] = 'delta'; return ['ok' => true]; },
        function () use (&$steps): array {
            $steps[] = 'snapshot';
            return ['ok' => true, 'final_reconciliation' => ['ok' => true], 'restore_rehearsal' => ['ok' => true]];
        },
        function () use (&$steps, &$released): array {
            $steps[] = 'release';
            $released = true;
            return ['ok' => true];
        },
        static fn(): array => ['ok' => true, 'freeze' => ['storage_write_block_active' => false], 'control_consistency' => ['ok' => true]],
        $directory . '/state.json'
    );

    $result = $orchestrator->run();
    $assertSame(true, $result['ok'], 'Sealed resume must complete');
    $assertSame('completed', $result['state'], 'Sealed resume must finish the existing rehearsal');
    $assertSame(['delta', 'snapshot', 'release'], $steps, 'Sealed resume must not freeze, use legacy drain or seal again');
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) @unlink($path);
    @rmdir($directory);
}

fwrite(STDOUT, "FinalDeltaRehearsalSealedResumeTest passed: {$assertions} assertions.\n");
