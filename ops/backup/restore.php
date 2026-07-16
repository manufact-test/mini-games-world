<?php
declare(strict_types=1);

require_once __DIR__ . '/BackupCli.php';

$options = getopt('', [
    'backup:',
    'target:',
    'source::',
    'backup-root::',
    'external-dir::',
    'data-dir::',
]);

foreach ([
    'backup-root' => 'MGW_BACKUP_ROOT',
    'external-dir' => 'MGW_BACKUP_EXTERNAL_DIR',
] as $option => $environmentKey) {
    if (isset($options[$option]) && trim((string)$options[$option]) !== '') {
        putenv($environmentKey . '=' . trim((string)$options[$option]));
    }
}

try {
    $target = trim((string)($options['target'] ?? ''));
    if ($target === '') {
        throw new RuntimeException('Restore target is required: use --target=/absolute/path.');
    }

    $context = BackupCli::boot();
    $manager = BackupCli::manager($context, [
        'data_dir' => isset($options['data-dir']) ? (string)$options['data-dir'] : null,
    ]);
    $backup = trim((string)($options['backup'] ?? 'latest'));
    if ($backup === '' || $backup === 'latest') {
        $source = strtolower(trim((string)($options['source'] ?? 'primary')));
        $root = $source === 'external'
            ? (string)($context['settings']['external_dir'] ?? '')
            : (string)$context['settings']['backup_root'];
        if ($root === '') {
            throw new RuntimeException('Requested backup source is not configured.');
        }
        $backup = $manager->latestSnapshot($root);
    }
    BackupCli::printJson($manager->restore($backup, $target));
} catch (Throwable $e) {
    BackupCli::fail($e);
}
