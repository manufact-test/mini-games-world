<?php
declare(strict_types=1);

/*
 * Copy this complete file to _private_mgw/backup.php.
 * The real file is private and must never be committed.
 */
return [
    // Both directories are outside public_html and outside mgw_data.
    'backup_root' => dirname(__DIR__) . '/mgw_backups',
    'external_dir' => dirname(__DIR__) . '/mgw_backup_external',

    // Daily snapshots: keep at most 7 and delete snapshots older than 7 days.
    'retention_days' => 7,
    'retention_count' => 7,

    // Include a sanitized release copy. Private config/runtime/data files are excluded.
    'include_release_files' => true,

    // Production backup fails if the secondary copy cannot be configured/completed.
    'require_external_copy' => true,
];
