<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/storage/JsonDatabase.php';

$temp = sys_get_temp_dir() . '/mgw-runtime-bridge-dirty-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0700, true) && !is_dir($temp)) {
    throw new RuntimeException('Could not create temp data dir.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

try {
    $db = new JsonDatabase($temp);

    $db->transaction(static function (array &$data): void {
        $data['users']['u1'] = [
            'id' => 'u1',
            'telegram_id' => 'u1',
            'balance_match' => 100,
            'balance_gold' => 0,
            'registered_at' => '2026-08-09T00:00:00Z',
            'status' => 'idle',
            'last_seen_at' => '2026-08-09T00:00:00Z',
        ];
    });
    $dirty = $GLOBALS['mgw_runtime_bridge_dirty'] ?? null;
    $assertSame(true, $dirty['economy'] ?? null, 'Creating an economy-bearing user must dirty economy');
    $assertSame(false, $dirty['realtime'] ?? null, 'Creating only a user must not dirty realtime');

    $db->transaction(static function (array &$data): void {
        $data['users']['u1']['status'] = 'playing';
        $data['users']['u1']['current_game_id'] = 'g1';
        $data['users']['u1']['last_seen_at'] = '2026-08-09T00:01:00Z';
        $data['users']['u1']['active_session'] = [
            'session_id' => 's1',
            'touched_at' => '2026-08-09T00:01:00Z',
        ];
    });
    $dirty = $GLOBALS['mgw_runtime_bridge_dirty'] ?? null;
    $assertSame(false, $dirty['economy'] ?? null, 'Session/status-only user writes must not re-run economy projection');
    $assertSame(false, $dirty['weekly_bonus'] ?? null, 'Session/status-only user writes must not re-run weekly projection');

    $db->transaction(static function (array &$data): void {
        $data['users']['u1']['balance_match'] = 90;
        $data['transactions'][] = ['id' => 'tx1', 'user_id' => 'u1', 'amount' => -10];
    });
    $dirty = $GLOBALS['mgw_runtime_bridge_dirty'] ?? null;
    $assertSame(true, $dirty['economy'] ?? null, 'Balance/transaction changes must dirty economy');

    $db->transaction(static function (array &$data): void {
        $data['games']['g1'] = [
            'id' => 'g1',
            'status' => 'active',
            'room' => 'match',
            'player_ids' => ['u1', 'u2'],
        ];
    });
    $dirty = $GLOBALS['mgw_runtime_bridge_dirty'] ?? null;
    $assertSame(true, $dirty['realtime'] ?? null, 'Game changes must dirty realtime');
    $assertSame(false, $dirty['weekly_bonus'] ?? null, 'An unfinished game must not dirty weekly progress');

    $db->transaction(static function (array &$data): void {
        $data['games']['g1']['status'] = 'finished';
        $data['games']['g1']['finished_at'] = '2026-08-09T00:02:00Z';
    });
    $dirty = $GLOBALS['mgw_runtime_bridge_dirty'] ?? null;
    $assertSame(true, $dirty['realtime'] ?? null, 'Finishing a game must dirty realtime');
    $assertSame(true, $dirty['weekly_bonus'] ?? null, 'Finishing a Match game must dirty weekly progress');

    $db->transaction(static function (array &$data): void {
        $data['shop_orders'][] = ['id' => 'order1'];
    });
    $dirty = $GLOBALS['mgw_runtime_bridge_dirty'] ?? null;
    $assertSame(true, $dirty['shop'] ?? null, 'Shop order changes must dirty shop projection');

    $db->transaction(static function (array &$data): void {
        $data['payments'][] = ['id' => 'payment1'];
    });
    $dirty = $GLOBALS['mgw_runtime_bridge_dirty'] ?? null;
    $assertSame(true, $dirty['payments'] ?? null, 'Payment changes must dirty payment projection');

    fwrite(STDOUT, "RuntimeBridgeDomainDirtyTest: {$assertions} assertions passed\n");
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    @rmdir($temp);
}
