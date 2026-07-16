<?php
declare(strict_types=1);

require_once __DIR__ . '/BackupCli.php';

$options = getopt('', [
    'backup-root::',
    'external-dir::',
    'retention-days::',
    'retention-count::',
    'data-dir::',
    'no-release',
]);

foreach ([
    'backup-root' => 'MGW_BACKUP_ROOT',
    'external-dir' => 'MGW_BACKUP_EXTERNAL_DIR',
    'retention-days' => 'MGW_BACKUP_RETENTION_DAYS',
    'retention-count' => 'MGW_BACKUP_RETENTION_COUNT',
] as $option => $environmentKey) {
    if (isset($options[$option]) && $options[$option] !== false && trim((string)$options[$option]) !== '') {
        putenv($environmentKey . '=' . trim((string)$options[$option]));
    }
}
if (array_key_exists('no-release', $options)) {
    putenv('MGW_BACKUP_INCLUDE_RELEASE=false');
}

try {
    $context = BackupCli::boot();
    $manager = BackupCli::manager($context, [
        'data_dir' => isset($options['data-dir']) ? (string)$options['data-dir'] : null,
    ]);
    BackupCli::printJson($manager->create(
        (string)$context['environment'],
        (string)$context['build']
    ));
} catch (Throwable $e) {
    BackupCli::fail($e);
}
