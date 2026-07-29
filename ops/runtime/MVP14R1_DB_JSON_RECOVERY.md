# MVP-14R.1 — current DB-to-JSON recovery and temporary JSON runtime

## Purpose

MVP-14R.1 qualifies the already hardened production recovery components for the clean relational rebuild:

1. export the exact current DB-primary compatibility state into a fresh verified JSON artifact;
2. restore that artifact only into an isolated directory first;
3. under a separate explicit authorization, install the verified JSON artifact as the temporary production runtime;
4. retain the previous live JSON directory and the current MySQL database for rollback and evidence.

This branch is code and CI only. It does not run the export, enter maintenance, change runtime routing, replace live JSON, change webhook registration or alter Cron.

## Required predecessor

MVP-14R.0 must already be complete. The accepted production checkpoint is:

`MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD`

The isolated verifier must have returned:

- `MGW_MVP14R_SAFETY_CHECKPOINT_VERIFY=PASSED`
- `CHECKSUMS_VERIFIED=true`
- `DATABASE_DUMP_GZIP_VALID=true`
- `ARCHIVE_PATHS_SAFE=true`
- `PUBLIC_RESTORE_VALID=true`
- `PRIVATE_RESTORE_VALID=true`
- `JSON_RESTORE_VALID=true`
- `PRODUCTION_RUNTIME_CHANGED=false`
- `DATABASE_WRITE_EXECUTED=false`

## Reused guarded components

The recovery path intentionally reuses the previously tested production components instead of creating a second rollback implementation:

- `ops/runtime/run-production-primary-rollback-export.php`
- `ProductionPrimaryRollbackExportGate`
- `ProductionPrimaryRollbackExportService`
- `ProductionPrimaryRollbackExportVerifier`
- `ProductionPrimaryRollbackRestoreService`
- `ops/runtime/run-production-primary-live-rollback.php`
- `ProductionPrimaryLiveRollbackGate`
- `ProductionPrimaryLiveRollbackService`
- `ProductionPrimaryLiveRollbackStateStore`
- `ProductionPrimaryRuntimeOverlayWriter`

The export is read-only at the SQL layer. It locks the singleton compatibility-state row, verifies the exact revision and state SHA, verifies the completed outbox chain, runs nine-module parity checks, writes the recovery artifact outside the deployed project, verifies every file and then performs an isolated restore.

The temporary JSON transition retains the old live JSON directory, verifies the fresh export again, swaps the JSON directory before disabling DB routing, proves the JSON-only route, releases the JSON write block only after the route is sealed and keeps maintenance/read-only enabled on any unsafe partial failure.

## Added in MVP-14R.1

### Read-only static readiness inspector

`ops/runtime/inspect-mvp14r1-recovery-readiness.php`

The inspector checks only filesystem and package prerequisites:

- exact canonical project, private, checkpoint and export-root paths;
- private input files and permissions;
- checkpoint completeness;
- dedicated export-root permissions;
- presence of both guarded production CLIs.

It never contacts MySQL, executes the export, changes JSON, rewrites configuration, changes webhook registration or changes Cron.

Exact confirmation phrase:

`INSPECT_READ_ONLY_MVP14R1_RECOVERY_READINESS`

Expected success marker:

`MGW_MVP14R1_STATIC_READINESS=PASSED`

### Focused package check

`ops/checks/mvp14r1-db-json-recovery-local.sh`

This check runs the focused exporter, verifier, isolated-restore, live-rollback, state-store and runtime-overlay tests. The full repository CI still runs every test in the project.

Expected success marker:

`MGW_MVP14R1_RECOVERY_PACKAGE=PASSED`

## Production execution boundary

Merging and deploying this branch does not authorize any production recovery action.

The production operation remains split into separate approvals:

1. **Static readiness only** — no DB contact and no production change.
2. **Maintenance/read-only preparation** — separate approval; DB routing remains active.
3. **Fresh DB-to-JSON export** — separate short-lived authorization; SQL reads only.
4. **Artifact and isolated-restore verification** — no live JSON replacement.
5. **Temporary JSON runtime transition** — separate short-lived authorization and manual maintenance window.
6. **Manual acceptance smoke** — Telegram entry, account identity, balance/history, matchmaking, game flow, invite flow and weekly-bonus visibility.

No step may be combined implicitly with another step.

## Static readiness command shape

The exact production paths must be supplied explicitly. No defaults are guessed:

```bash
/opt/alt/php83/usr/bin/php \
  /absolute/public_html/ops/runtime/inspect-mvp14r1-recovery-readiness.php \
  --project-root=/absolute/public_html \
  --private-root=/absolute/_private_mgw \
  --checkpoint-dir=/absolute/mgw_checkpoints/MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD \
  --expected-checkpoint-id=MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD \
  --output-root=/absolute/mgw_rollback_exports \
  --confirm=INSPECT_READ_ONLY_MVP14R1_RECOVERY_READINESS
```

The output root must already exist as a dedicated canonical directory with exact mode `0700`. Creating it is a separate operator step and is not performed by the inspector.

## Fresh export contract

The guarded export CLI still requires all six exact options:

- `--config=/absolute/private/config.php`
- `--cutover-state=/absolute/private/production-cutover.json`
- `--authorization=/absolute/private/production-rollback-export-authorization.json`
- `--output-root=/absolute/private/rollback-exports`
- `--request-id=<32 lowercase hex>`
- `--confirm=EXPORT_DB_PRIMARY_TO_JSON_ROLLBACK`

The export authorization has a maximum TTL of 900 seconds and is bound to the current state revision/SHA, database identity, activation fingerprints and output-root fingerprint.

Expected success markers include:

- `PRODUCTION_ROLLBACK_EXPORT=PASSED`
- `STATE_ROW_LOCKED=true`
- `BACKUP_MANAGER_COMPATIBLE=true`
- `ISOLATED_RESTORE_REQUIRED=true`
- `DATABASE_WRITE_EXECUTED=false`
- `LIVE_JSON_CHANGED=false`
- `PERSISTENT_CONFIG_CHANGED=false`
- `WEBHOOK_CHANGED=false`
- `CRON_CHANGED=false`
- `PRODUCTION_CHANGED=false`

## Temporary JSON runtime contract

The guarded live transition requires a separately verified export and a separate authorization bound to the exact export, current runtime overlay, current cutover state and live JSON directory.

Exact confirmation phrase:

`ROLL BACK PRODUCTION TO VERIFIED JSON`

The transition is fail-closed:

- before DB routing is disabled, an unsafe failure restores the prior filesystem and control files;
- after DB routing is disabled, JSON remains sealed and maintenance/read-only remain enabled until an exact resume succeeds;
- the previous live JSON directory is retained;
- MySQL is never deleted;
- webhook and Cron remain unchanged.

## Acceptance criteria

MVP-14R.1 code preparation is accepted only when:

- the new static inspector functional test passes;
- every existing DB-to-JSON export and isolated-restore test passes;
- every existing live JSON transition, state-store and runtime-overlay test passes;
- full repository CI is green;
- the PR contains no production execution output;
- no DB-to-JSON export has been run by this PR;
- no production route, JSON, webhook or Cron state has changed.

Production acceptance remains pending until the later manually authorized export, isolated verification and temporary JSON transition are executed and manually smoke-tested.
