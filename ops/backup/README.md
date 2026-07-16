# Mini Games World JSON backup and restore

This runbook protects the current JSON storage before the database migration.
The commands are CLI-only and must never be exposed as browser tools.

## What a snapshot contains

- a shared-lock copy of every JSON file from the configured `data_dir`;
- a sanitized release copy of `/app`, `/bot`, `/ops`, `/site` and safe root files;
- `manifest.json` with build/environment/counts but no absolute server paths;
- `checksums.sha256` and a `COMPLETE` marker;
- a verified secondary copy when `external_dir` is configured.

The release copy excludes `_private_mgw`, `.env`, `.htpasswd`, live JSON data,
`bot/config/config.php`, `config.local.php` and `runtime.php`.

## Private configuration

Copy `ops/backup/backup.config.example.php` to:

```text
_private_mgw/backup.php
```

Both `backup_root` and `external_dir` must be outside `public_html`, outside the
live `mgw_data` directory and different from each other. Production requires the
secondary copy. For a real off-host copy, sync/download completed snapshots from
`external_dir` to a separate device or storage provider.

## Commands

Create and verify a primary + external snapshot:

```bash
php /absolute/path/public_html/ops/backup/backup.php
```

Verify the latest primary snapshot:

```bash
php /absolute/path/public_html/ops/backup/verify.php --backup=latest
```

Verify the latest external snapshot:

```bash
php /absolute/path/public_html/ops/backup/verify.php --backup=latest --source=external
```

Restore the latest external snapshot into a new, separate directory:

```bash
php /absolute/path/public_html/ops/backup/restore.php \
  --backup=latest \
  --source=external \
  --target=/absolute/path/mgw_restore_test
```

The restore command refuses the configured live data directory, verifies every
checksum first, requires an empty/new target, and writes only into that target.

## Daily retention

Default policy:

- run once per day;
- keep at most 30 completed snapshots;
- delete completed snapshots older than 14 days;
- never delete the newest completed snapshot;
- apply the same policy to the external directory.

`backup-status.json` stores the latest result and `backup-history.jsonl` keeps an
append-only operational history. Both live outside the public site.

## Monthly staging restore rehearsal

1. Put staging into maintenance mode so no new test match starts.
2. Restore the latest **external** snapshot to a new sibling directory such as
   `mgw_staging_restore_test`.
3. Keep the current `mgw_staging_data` directory as rollback; do not delete it.
4. Rename `mgw_staging_data` to `mgw_staging_data_before_restore`.
5. Rename `mgw_staging_restore_test` to `mgw_staging_data`.
6. Return staging runtime flags to normal.
7. Verify health, `/start`, existing users, history and one complete test match.
8. Roll back by reversing the two directory names.
9. Delete only the temporary restored copy after the rehearsal is accepted.

Never perform this rename procedure on production during MVP-13.5. A production
restore requires a separate incident/cutover checklist.

## Failure and rollback

- An incomplete `.partial-*` directory is never treated as a valid backup.
- A checksum/JSON failure stops backup or restore immediately.
- Deleting the separate restore target rolls back a failed rehearsal.
- Live `mgw_data` is read under a shared lock and is never edited by these tools.
