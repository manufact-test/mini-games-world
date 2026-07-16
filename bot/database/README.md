# Mini Games World database migrations

MVP-14.2 prepares a separate MySQL/MariaDB schema. The live product continues to use
`storage_driver = json`; this migration tool does not import or edit `mgw_data`.

## Private configuration

Add the following only to the environment's private `_private_mgw/config.php`:

```php
'storage_driver' => 'json',
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
```

Staging additionally requires `environment_guard.production_database_sha256`. The
fingerprint contains only connection identity fields (host, port, database name and
optional DSN), never the database password.

## CLI commands

Run from Hostinger Cron Jobs as a temporary Custom command or over SSH. The script
is CLI-only and the directory is denied over HTTP.

```bash
/usr/bin/php /absolute/path/public_html/bot/database/migrate.php --status
/usr/bin/php /absolute/path/public_html/bot/database/migrate.php --dry-run
/usr/bin/php /absolute/path/public_html/bot/database/migrate.php --migrate
```

`--migrate` on production is blocked unless both the private config explicitly sets
`database_migrations_allow_production = true` and the CLI command also includes
`--allow-production`. MVP-14.2 uses migrations only in the test environment.

## Guarantees

- migration filenames and declared versions must match;
- applied migration checksums may not change;
- repeated runs are idempotent;
- each migration is recorded only after its transaction succeeds;
- MySQL/MariaDB runs use a named advisory lock to prevent concurrent migration jobs;
- schema tables use InnoDB and utf8mb4;
- errors and status output never print database credentials;
- no destructive/down migration is included in this MVP.

## Rollback

Before product data is stored in MySQL/MariaDB, rollback is simply disabling the
private `database.enabled` flag and dropping the empty test schema if required. JSON
remains the active storage until the later cutover MVP.
