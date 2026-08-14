# MVP-14.9 — Database operations closure

Status: **IMPLEMENTATION EXISTS / ORIGINAL-SPEC OPS EVIDENCE PENDING**

This checkpoint consolidates existing, already-separated operational owners. It does not add a second backup service, health poller, monitor, retry loop, Cron job, production switch, or database writer.

The previous closure rule in this file was too narrow: presence of the canonical owners plus a focused repository test proves implementation boundaries, but does not prove the complete original MVP-14.9 operational acceptance (off-host copy, measured RPO/RTO, slow-query visibility, real bounded Hostinger load evidence, and recurring operational ownership). The original acceptance scope is restored by `MVP14_9_DATABASE_OPERATIONS_RUNBOOK.md` and the fail-closed verifier `verify-mvp14-9-ops-compliance.sh`.

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

### 3. Database readiness / health

`bot/health.php`

This is the canonical readiness owner. When the database is enabled it checks connectivity with `SELECT 1`, inspects migration status, publishes `connected`, `schema_current`, applied/pending migration counts, and returns HTTP 503 when storage/database readiness is not satisfied.

It is not slow-query telemetry. Slow-query visibility must be proven separately through the accepted environment/provider evidence described in the runbook.

### 4. Local latency regression

`ops/checks/mvp14r2-latency-acceptance-local.sh`

This remains the bounded local latency regression owner. It explicitly does not contact production/network/DB and therefore cannot, by itself, establish the real Hostinger load envelope. The official two-context staging suite and historical Phase B concurrency evidence remain useful complementary evidence, but original-spec load acceptance still requires bounded target-environment evidence.

## Correct closure rule

Repository implementation is green when the canonical owners remain intact and:

```bash
bash ops/checks/mvp14-9-ops-compliance-local.sh
```

passes.

Original-spec MVP-14.9 operations compliance is closed only when, in addition, the following command passes against a **real private evidence bundle**:

```bash
bash ops/runtime/verify-mvp14-9-ops-compliance.sh --evidence-root /private/path/mvp14-9-evidence
```

That bundle must prove:

- exact candidate/environment;
- current canonical DB checkpoint verification;
- independent/off-host copy with matching SHA-256;
- actual restore rehearsal;
- defined and met RPO;
- defined, measured and met RTO;
- slow-query visibility/review;
- bounded target-environment load evidence;
- real ownership/scheduling for daily backup, monthly restore rehearsal, and quarterly load verification.

Until this evidence passes, the status must remain **IMPLEMENTATION EXISTS / ORIGINAL-SPEC OPS EVIDENCE PENDING**. A green source-level test must never be used again as a substitute for operational evidence.

See `ops/runtime/MVP14_9_DATABASE_OPERATIONS_RUNBOOK.md` for the evidence contract and safety boundaries.

## Explicit non-actions in this remediation

- no production database contact;
- no production database write;
- no production restore execution;
- no production runtime switch;
- no webhook change;
- no Cron change;
- no `main` or production deployment;
- no gameplay/economy/account behavior change.
