# Managed database migrations

`managed-migrations.php` is a CLI-only, cron-safe release migration controller.

## Why it exists

Hostinger shared Git deployment has no project-controlled post-deploy shell hook. A single permanent Cron can therefore check the deployed migration catalog and safely apply only new staging migrations. It replaces temporary `--status` / `--dry-run` / `--migrate` Cron jobs.

## Staging

Private database configuration may omit `managed_migrations`; staging defaults are safe:

- enabled;
- dry-run before every execution;
- exact plan fingerprint rechecked before execution;
- database advisory migration lock plus process file lock;
- repeated execution is a no-op;
- append-only private log outside `public_html`;
- health stays degraded while a required migration is pending or failed.

Permanent Cron example:

```cron
*/5 * * * * /usr/bin/php /home/ACCOUNT/domains/STAGING_DOMAIN/public_html/ops/deploy/managed-migrations.php --run
```

Status-only command:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/managed-migrations.php --status
```

## Hostinger deployment troubleshooting

A deployment that fails immediately with `0s` and an empty build log did not reach the application build. Check the Hostinger Git connection and deployment branch before changing application code. Do not treat such a record as a PHP, migration or CI failure.

Keep the staging deployment branch moving forward from the last successfully deployed commit. After a squash merge, avoid force-moving the connected Hostinger branch to unrelated history; publish a normal follow-up commit or reconnect the Git deployment if Hostinger no longer accepts new revisions.

## Production

Production defaults to disabled. A production migration requires all of the following at the same time:

1. `managed_migrations.enabled=true`;
2. existing `database_migrations_allow_production=true`;
3. the exact current plan SHA-256 in `production_approval_fingerprint`;
4. a non-expired UTC approval time;
5. a successful backup;
6. a successful external backup copy when required.

The fingerprint is not a reusable secret. It approves only the exact pending versions and checksums. Any code or migration change produces a different fingerprint and fails closed.

After a production migration succeeds, remove the approval fingerprint and return `database_migrations_allow_production` to `false`.

## Private config fragment

See `managed-migrations.config.example.php`. Keep real values only in `_private_mgw/database.php`; never commit them.

## Logs and locks

Defaults:

- `_private_mgw/managed-migrations.log`;
- `_private_mgw/managed-migrations.lock`.

Both paths must be outside the deployed project directory. Output and health summaries never expose database credentials or the production approval fingerprint.
