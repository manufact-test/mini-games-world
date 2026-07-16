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

The production config may keep `database.enabled = false` while reserving its real
host/port/name. Its CLI-only fingerprint command still produces the protected
identity hash needed by the test environment. The fingerprint never contains the
user or password.

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
config. Do not copy database credentials between environments.

`--migrate` on production is blocked unless both the private config explicitly sets
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

Before product data is stored in MySQL/MariaDB, rollback is simply disabling the
private `database.enabled` flag and dropping the empty test schema if required. JSON
remains the active storage until the later cutover MVP.
