# Permanent staging operations runner

`ops/deploy/staging-operations-runner.php` is the single permanent staging Cron entrypoint for controlled deployment operations.

## Cron

Run every five minutes:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/staging-operations-runner.php --run
```

This replaces temporary per-operation Cron commands. Do not change the permanent backup or managed-migration Cron jobs.

## Contract

- CLI and staging only.
- JSON remains the global and rollback driver until the final cutover.
- The database schema must be current.
- Operations are allow-listed in `StagingOperationRegistry`.
- Every operation is tied to one exact application build.
- Operation IDs are revisioned and immutable.
- One eligible operation runs per Cron tick.
- Completed operations become idempotent no-ops.
- Failed operations do not retry forever; a corrected deployment uses a new operation revision.
- Interrupted `running` state is resumed once on the next tick.
- Optional rollback hooks run automatically after a failed report or exception.
- The runner shares the managed-migration lock, so schema migration and deployment operations cannot overlap.
- Private state and lock files stay outside `public_html` with mode `0600`.
- Reports contain aggregate counts and fingerprints only.

## Current baseline operation

`mvp-14.8.4f-runtime-baseline-v1` verifies:

- required DB runtime modules: accounts, realtime, invites, notifications, economy, history;
- JSON rollback storage;
- current database schema;
- realtime shadow synchronization;
- economy shadow integrity;
- history JSON/DB parity;
- economy totals and immutable ledger integrity;
- absence of active reservations and migration blockers.

It does not switch production and does not change the global storage driver.

## Status

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/staging-operations-runner.php --status
```

Expected after the baseline completes:

- `ok: true`
- `runner_state: idle` or `completed`
- completed count `1`
- failed count `0`
- `production_changed: false`
- `storage_driver: json`
- `rollback_driver: json`

## Adding future operations

Add a new `StagingOperationDefinition` to the registry with:

1. a new immutable revisioned ID;
2. the exact new build value;
3. an execution callback returning an aggregate report;
4. a rollback callback whenever the operation changes configuration or data.

Never reuse a completed operation ID for changed behavior.
