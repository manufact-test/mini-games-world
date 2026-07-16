<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/ops/backup/BackupManager.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$root = sys_get_temp_dir() . '/mgw-backup-test-' . bin2hex(random_bytes(5));
$project = $root . '/public_html';
$data = $root . '/mgw_data';
$backups = $root . '/mgw_backups';
$external = $root . '/mgw_backup_external';
$restore = $root . '/mgw_restore_test';

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
};

try {
    mkdir($project . '/app', 0755, true);
    mkdir($project . '/bot/config', 0755, true);
    mkdir($project . '/bot/data', 0755, true);
    mkdir($project . '/ops', 0755, true);
    mkdir($project . '/site', 0755, true);
    mkdir($data, 0755, true);

    file_put_contents($project . '/app/index.html', '<main>test</main>');
    file_put_contents($project . '/bot/webhook.php', '<?php echo "ok";');
    file_put_contents($project . '/bot/config/config.php', '<?php return ["bot_token" => "SECRET"];');
    file_put_contents($project . '/bot/config/runtime.php', '<?php return [];');
    file_put_contents($project . '/bot/data/users.json', '[{"must_not_be_copied":true}]');
    file_put_contents($project . '/site/index.html', '<main>site</main>');
    file_put_contents($project . '/.htaccess', 'Options -Indexes');

    $users = [['id' => 'tg_1', 'balance_match' => 58]];
    $games = [['id' => 'game_1', 'status' => 'finished', 'winner_id' => 'tg_1']];
    file_put_contents($data . '/users.json', json_encode($users, JSON_THROW_ON_ERROR));
    file_put_contents($data . '/games.json', json_encode($games, JSON_THROW_ON_ERROR));
    file_put_contents($data . '/system.json', json_encode(['fees_match' => 2], JSON_THROW_ON_ERROR));
    file_put_contents($data . '/app.lock', '');

    $manager = new BackupManager($project, $data, $backups, $external, 14, 2, true);
    $first = $manager->create('staging', 'test-build');
    $assertSame(true, $first['ok'], 'Backup must succeed');
    $assertSame(true, $first['external_copy']['copied'], 'External copy must be created');

    $snapshot = $manager->latestSnapshot($backups);
    $externalSnapshot = $manager->latestSnapshot($external);
    $assertTrue(is_file($snapshot . '/manifest.json'), 'Primary manifest must exist');
    $assertTrue(is_file($externalSnapshot . '/manifest.json'), 'External manifest must exist');
    $assertTrue(!file_exists($snapshot . '/release/bot/config/config.php'), 'Private bot config must not be copied');
    $assertTrue(!file_exists($snapshot . '/release/bot/config/runtime.php'), 'Private runtime config must not be copied');
    $assertTrue(!file_exists($snapshot . '/release/bot/data/users.json'), 'Runtime project data must not be copied as release files');
    $assertTrue(is_file($snapshot . '/data/users.json'), 'Live data users file must be copied');

    $manifestText = file_get_contents($snapshot . '/manifest.json') ?: '';
    $assertTrue(!str_contains($manifestText, $root), 'Manifest must not expose absolute filesystem paths');
    $verified = $manager->verify($snapshot);
    $assertSame(true, $verified['ok'], 'Created backup must verify');

    $restored = $manager->restore($snapshot, $restore);
    $assertSame(true, $restored['target_ready'], 'Restore target must be ready');
    $assertSame($users, json_decode(file_get_contents($restore . '/users.json') ?: '[]', true, 512, JSON_THROW_ON_ERROR), 'Users must restore exactly');
    $assertSame($games, json_decode(file_get_contents($restore . '/games.json') ?: '[]', true, 512, JSON_THROW_ON_ERROR), 'Games must restore exactly');
    $assertTrue(is_file($restore . '/app.lock'), 'Restore must create storage lock');

    $liveBlocked = false;
    try {
        $manager->restore($snapshot, $data);
    } catch (RuntimeException $e) {
        $liveBlocked = str_contains($e->getMessage(), 'live data');
    }
    $assertTrue($liveBlocked, 'Restore must refuse the live data directory');

    file_put_contents($snapshot . '/data/users.json', 'tampered');
    $tamperDetected = false;
    try {
        $manager->verify($snapshot);
    } catch (RuntimeException $e) {
        $tamperDetected = str_contains($e->getMessage(), 'checksum mismatch');
    }
    $assertTrue($tamperDetected, 'Checksum verification must detect tampering');

    $remove($snapshot);
    $manager->create('staging', 'test-build-2');
    usleep(1000);
    $manager->create('staging', 'test-build-3');
    usleep(1000);
    $manager->create('staging', 'test-build-4');
    $primaryCompleted = array_values(array_filter(scandir($backups) ?: [], static fn(string $name): bool => !str_starts_with($name, '.') && is_dir($backups . '/' . $name)));
    $externalCompleted = array_values(array_filter(scandir($external) ?: [], static fn(string $name): bool => !str_starts_with($name, '.') && is_dir($external . '/' . $name)));
    $assertSame(2, count($primaryCompleted), 'Primary retention must keep two snapshots');
    $assertSame(2, count($externalCompleted), 'External retention must keep two snapshots');

    fwrite(STDOUT, "BackupRestoreTest: {$assertions} assertions passed\n");
} finally {
    $remove($root);
}
