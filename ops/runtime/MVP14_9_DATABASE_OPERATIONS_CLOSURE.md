# MVP-14.9 — Database operations closure

Status: **CLOSURE GATE**

This checkpoint consolidates existing, already-separated operational owners. It does not add a second backup service, health poller, monitor, retry loop, Cron job, production switch, or database writer.

## Canonical owners

### 1. DB-primary backup

`ops/runtime/create-mvp14r-safety-checkpoint.sh`

This is the DB-primary backup owner. It creates a MariaDB/MySQL single-transaction dump, archives the exact deployed application/private/JSON rollback material, writes SHA-256 checksums, and publishes atomically only after the checkpoint is complete.

The older `ops/backup/*` tooling remains the historical pre-DB JSON backup owner and must not be treated as the current DB-primary backup.

### 2. Backup verification / isolated restore evidence

`ops/runtime/verify-mvp14r-safety-checkpoint.sh`

The verifier checks all checkpoint checksums, validates the compressed SQL dump, rejects unsafe archive paths, restores file/JSON material only into an isolated temporary location, and performs no DB write.

For runtime recovery, the existing guarded DB-primary → JSON rollback path remains owned by:

- `ops/runtime/run-production-primary-rollback-export.php`
- `bot/runtime/ProductionPrimaryRollbackExportService.php`
- `bot/runtime/ProductionPrimaryRollbackRestoreService.php`
- `ops/runtime/run-production-primary-live-rollback.php`

Those components provide the tested fail-closed recovery route without creating a competing primary-storage owner.

### 3. Database monitoring

`bot/health.php`

This is the canonical health owner. When the database is enabled it checks connectivity with `SELECT 1`, inspects migration status, publishes `connected`, `schema_current`, applied/pending migration counts, and returns HTTP 503 when storage/database readiness is not satisfied.

MVP-14.9 does not add another health endpoint or polling daemon.

### 4. Load / latency acceptance

`ops/checks/mvp14r2-latency-acceptance-local.sh`

This remains the bounded latency acceptance owner. In addition, the official two-context staging suite and the Phase B concurrent API evidence historically exercised simultaneous clients across DB projection/runtime paths. No new stress loop or artificial retry/sleep owner is introduced here.

## Closure rule

MVP-14.9 is considered closed when the focused `Mvp14DatabaseOperationsClosureTest` is green on the exact candidate and the branch contains all canonical owners above unchanged in their safety boundaries.

A future operational change to DB backup, recovery, health, or load acceptance must modify the corresponding canonical owner rather than adding a parallel implementation.

## Explicit non-actions

- no production database contact;
- no production database write;
- no production restore execution;
- no production runtime switch;
- no webhook change;
- no Cron change;
- no `main` or production deployment;
- no gameplay/economy/account behavior change.
