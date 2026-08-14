# MVP-14.9 — DB operations acceptance runbook

Status: **ORIGINAL-SPEC COMPLIANCE REQUIRED**

This runbook restores the full acceptance scope that was defined for MVP-14.9 before the later closure checkpoint narrowed it. It deliberately reuses the existing DB-primary owners instead of introducing a second backup, health, retry, Cron, or database-write implementation.

## Canonical owners that remain unchanged

- DB-primary checkpoint: `ops/runtime/create-mvp14r-safety-checkpoint.sh`
- checkpoint verification / isolated file restore: `ops/runtime/verify-mvp14r-safety-checkpoint.sh`
- guarded runtime rollback/recovery: existing production-primary rollback export/restore services
- DB readiness/health: `bot/health.php`
- bounded local latency regression: `ops/checks/mvp14r2-latency-acceptance-local.sh`

The historical `ops/backup/*` directory is the pre-DB JSON backup owner. It is not the DB-primary backup system.

## Why another gate is required

Presence of the owners above proves that checkpoint/recovery mechanisms exist. It does **not** by itself prove the original MVP-14.9 operational outcomes:

1. a recent DB-primary checkpoint exists;
2. an independent/off-host copy of that exact checkpoint exists;
3. restore has actually been rehearsed;
4. RPO is defined and met;
5. RTO is defined, measured and met;
6. slow-query visibility/metrics have been checked;
7. bounded load evidence exists for the target Hostinger runtime;
8. daily backup responsibility is actually scheduled/owned;
9. monthly restore rehearsal is actually scheduled/owned;
10. quarterly load verification is actually scheduled/owned.

`ops/runtime/verify-mvp14-9-ops-compliance.sh` is the fail-closed evidence verifier for those outcomes. It performs no remote calls and no database writes.

## Evidence bundle

Create a private evidence directory outside the public web root. Do not commit database dumps, user data, secrets, private configuration, access tokens, or raw production logs to Git.

Required files:

- `manifest.env`
- `checkpoint-verification.txt`
- `offhost-proof.txt`
- `restore-proof.txt`
- `slow-query-evidence.txt`
- `load-evidence.txt`
- `schedule-evidence.txt`

Run:

```bash
bash ops/runtime/verify-mvp14-9-ops-compliance.sh --evidence-root /private/path/mvp14-9-evidence
```

A PASS is acceptance evidence; a missing field/file is a FAIL, not a warning.

## Manifest fields

```text
environment=staging|production
exact_ref=<40-char git SHA>
verified_at_unix=<unix seconds>
checkpoint_created_unix=<unix seconds>
checkpoint_sha256=<canonical checkpoint SHA-256>
offhost_sha256=<SHA-256 independently verified after copy>
restore_result=PASS
restore_elapsed_seconds=<measured seconds>
rpo_target_seconds=<approved target seconds>
rto_target_seconds=<approved target seconds>
slow_query_result=PASS
load_result=PASS
daily_backup_schedule=PROVEN
monthly_restore_schedule=PROVEN
quarterly_load_schedule=PROVEN
```

The verifier requires the canonical and off-host digests to match, checkpoint age to be within RPO, restore time to be within RTO, and all operational obligations to be explicitly proven.

## 1. Canonical DB checkpoint

Use only the existing owner:

```bash
bash ops/runtime/create-mvp14r-safety-checkpoint.sh ...
```

Then verify the produced checkpoint with the existing verifier. Record the exact candidate SHA and the verifier output in `checkpoint-verification.txt`.

Do not create another `mysqldump` path merely for this gate.

## 2. Independent / off-host copy

Copy the completed checkpoint archive to storage whose failure domain is independent of the Hostinger application runtime. The transport/provider is an operator decision and must not be hard-coded into product runtime.

After the copy, calculate SHA-256 independently at the destination or after a destination read-back. Record only non-secret proof in `offhost-proof.txt`, including destination class/provider, verification time, object/version identifier if available, and digest. The digest must equal `checkpoint_sha256`.

A second directory on the same application host is **not** accepted as off-host evidence.

## 3. Restore rehearsal and RTO

Use the existing isolated verification/recovery owners. Measure wall-clock restore/recovery elapsed time. Record:

- exact checkpoint;
- isolated target;
- start/end timestamps;
- measured elapsed seconds;
- result;
- validation performed after restore.

Put the evidence in `restore-proof.txt` and the measured value in the manifest.

Do not run a live production restore without separate authorization.

## 4. RPO

The original roadmap requires daily backups. Therefore the operating policy must support at least one current checkpoint per day unless a stricter target is explicitly approved. Put the actual approved target in `rpo_target_seconds`; do not invent a more favorable value after a failure.

The verifier calculates checkpoint age at evidence-verification time and fails when it exceeds the target.

## 5. Slow-query visibility

`bot/health.php` owns readiness, not query-performance telemetry. `slow-query-evidence.txt` must therefore record how slow queries are observed on the actual DB/Hostinger environment and the result of the review for the candidate period.

Acceptable proof can be provider/database slow-query statistics, query timing metrics, or another existing platform-native source. Do not add a second application poller solely for this requirement.

Never include SQL containing sensitive user data in the repository.

## 6. Bounded load / real limits

`ops/checks/mvp14r2-latency-acceptance-local.sh` remains useful regression evidence but explicitly is not production evidence. `load-evidence.txt` must contain a bounded staging/Hostinger runtime test or equivalent provider evidence that establishes the tested concurrency/latency envelope and its result.

The test must be bounded, reversible, and must not intentionally overload production. Production traffic/load testing requires separate authorization.

## 7. Recurring operational ownership

`schedule-evidence.txt` must identify the real owner/scheduler for:

- daily DB-primary checkpoint/off-host copy;
- monthly restore rehearsal;
- quarterly bounded load verification.

The gate does not install or edit Cron. Scheduling/activation is an environment operation and remains blocked until explicitly authorized where required.

A written intention without an actual scheduler/owner is not `PROVEN`.

## Closure rule

MVP-14.9 original-spec compliance is closed only when:

1. focused repository self-test `ops/checks/mvp14-9-ops-compliance-local.sh` passes;
2. the evidence verifier passes against a real private evidence bundle for the accepted environment/exact ref;
3. no second DB backup/monitor/restore owner was introduced;
4. the resulting evidence is recorded without committing sensitive data.

Until then the correct status is **IMPLEMENTATION EXISTS / ORIGINAL-SPEC OPS EVIDENCE PENDING**, not `FULLY CLOSED`.
