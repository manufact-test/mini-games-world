# Mini Games World database migrations

MVP-14.2 prepares a separate MySQL/MariaDB schema. The live product continues to use
`storage_driver = json`; this migration tool does not import or edit `mgw_data`.

## Private configuration

Copy the complete template `bot/config/database.environment.example.php` to:

```text
_private_mgw/database.php
```

The standalone file is loaded beside the existing private `config.php`, so bot
tokens, admin IDs and current product settings do not need to be edited for database
work. Only these top-level keys are accepted in `database.php`:

- `database`;
- `database_migrations_allow_production`;
- `environment_guard.production_database_sha256`.

Example structure:

```php
<?php
declare(strict_types=1);

return [
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'PRIVATE_HOST',
        'port' => 3306,
        'name' => 'PRIVATE_DATABASE_NAME',
        'user' => 'PRIVATE_DATABASE_USER',
        'password' => 'PRIVATE_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'database_migrations_allow_production' => false,
    'environment_guard' => [
        'production_database_sha256' => '',
    ],
];
```

The production database identity may stay `enabled = false`. It can still produce
the protected identity fingerprint needed by the test environment. The fingerprint
never contains the database user or password.

`MGW_DATABASE_CONFIG_FILE` may override the standalone file path for local or future
infrastructure, but the Hostinger environments use `_private_mgw/database.php`.

## CLI commands

Run from Hostinger Cron Jobs as a temporary Custom command or over SSH. The scripts
are CLI-only and the directory is denied over HTTP.

```bash
/usr/bin/php /absolute/path/public_html/bot/database/fingerprint.php
/usr/bin/php /absolute/path/public_html/bot/database/migrate.php --status
/usr/bin/php /absolute/path/public_html/bot/database/migrate.php --dry-run
/usr/bin/php /absolute/path/public_html/bot/database/migrate.php --migrate
```

Copy only `database_identity_sha256` from the production fingerprint output into
`environment_guard.production_database_sha256` in the private test-environment
`database.php`. Do not copy database credentials between environments.

`--migrate` on production is blocked unless both the private file explicitly sets
`database_migrations_allow_production = true` and the CLI command also includes
`--allow-production`. MVP-14.2 uses migrations only in the test environment.

## Guarantees

- migration filenames and declared versions must match;
- applied migration checksums may not change;
- repeated runs are idempotent;
- each migration is recorded only after its `up()` method completes successfully;
- DML migrations may opt into an atomic transaction;
- MySQL/MariaDB schema DDL explicitly runs without a wrapping transaction because
  those servers implicitly commit DDL; schema migrations must therefore be
  expand-first and idempotent;
- MySQL/MariaDB runs use a named advisory lock to prevent concurrent migration jobs;
- schema tables use InnoDB and utf8mb4;
- errors and status output never print database credentials;
- no destructive/down migration is included in this MVP.

## Rollback

Before product data is stored in MySQL/MariaDB, rollback is simply setting
`database.enabled = false` or removing `_private_mgw/database.php`, then dropping the
empty test schema if required. JSON remains the active storage until the later
cutover MVP.
