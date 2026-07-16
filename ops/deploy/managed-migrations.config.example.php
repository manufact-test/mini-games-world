<?php
declare(strict_types=1);

// Merge this fragment into the private _private_mgw/database.php file.
// Never commit real production approval fingerprints or private paths.
return [
    'managed_migrations' => [
        // Staging may keep this true permanently.
        // Production must normally keep it false.
        'enabled' => false,

        // Used only in staging. The controller still performs a dry-run first.
        'auto_run_staging' => true,

        // Production only: exact SHA-256 reported by --status.
        // Remove immediately after the approved migration succeeds.
        'production_approval_fingerprint' => '',
        'production_approval_expires_at_utc' => null,

        'require_production_backup' => true,
        'require_production_external_copy' => true,

        // Optional. Defaults are beside the private config, outside public_html.
        // 'log_file' => dirname(__FILE__) . '/managed-migrations.log',
        // 'lock_file' => dirname(__FILE__) . '/managed-migrations.lock',
    ],

    // Production only: also must be true for the approved run, then set false.
    'database_migrations_allow_production' => false,
];
