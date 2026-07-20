# MVP-14.9 — controlled production JSON → DB cutover

This release adds the controlled production cutover. Deploying the release does not switch production. JSON remains the global storage and exact rollback source until an explicitly approved operation reaches its protected validation state and is then separately released.

## Hard gates

The cutover runner refuses execution unless all of the following are true:

- environment is exactly `production`;
- build is exactly `v103-mvp14-production-cutover`;
- production DB is connected and has zero pending migrations;
- a fresh read-only production preflight is clean;
- the private approval is enabled, bound to this build, bound to the exact current preflight plan fingerprint and not expired;
- the approval expiry is an ISO-8601 string containing `Z` or an explicit UTC offset;
- no more than 30 minutes remain until approval expiry;
- maintenance and financial read-only modes are disabled before the operation;
- JSON is still the active global/rollback driver;
- production DB module routing is disabled before the operation;
- primary and external backup locations are configured.

A gate failure before the private runtime backup is created returns `action: cutover_blocked`, writes no terminal cutover state and remains safely retryable after the blocker is resolved.

## Private approval

Add only to the private production `config.php`, never to GitHub:

```php
'production_cutover' => [
    'enabled' => true,
    'expected_build' => 'v103-mvp14-production-cutover',
    'approval_plan_fingerprint' => '<exact fingerprint from the v103 preflight>',
    'approval_expires_at_utc' => '<ISO-8601 timestamp no more than 30 minutes ahead>',
    'require_primary_backup' => true,
    'require_external_copy' => true,
],
```

Unix timestamps, date strings without an explicit offset and approvals more than 30 minutes in the future are rejected. Disable the approval immediately after `--run` returns `cutover_awaiting_release` or after any rollback. `--release` does not reuse the approval; the protected persisted state and explicit release command are the release authority.

## Commands

Start the protected cutover:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --run
```

Read status at any time:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --status
```

Release production only after every read-only smoke check passes:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --release
```

Explicit rollback:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --rollback
```

Rearm after an explicitly reviewed rollback:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --rearm
```

`--run` requires live JSON storage, a healthy production DB, current migrations, backup configuration and the managed-migrations lock.

`--release` requires live JSON storage, a healthy production DB, the managed-migrations lock, a healthy exact `awaiting_release` state and the preserved exact runtime backup. It does not create another backup and cannot run from any other state.

Emergency `--status`, `--rollback` and reviewed `--rearm` do **not** initialize the production DB or backup manager and do **not** acquire the managed-migrations lock. They still require the exact v103 build, production environment and private config/runtime directory.

The cutover CLI intentionally boots from the private base configuration without automatically executing `runtime.php` or the staging rehearsal control file. After recovery-state checks, the runner reads and merges `runtime.php` explicitly. This keeps `--status` and `--rollback` usable when `runtime.php` is malformed or interrupted. Normal web, API, webhook, bot and Cron bootstrap behavior is unchanged because the bypass constant exists only inside this cutover CLI entrypoint.

`--status` is read-only and does not acquire the exclusive cutover lock. It validates the persisted state against the actual runtime, exact activation fingerprints, write block, preserved backup and enabled module set. A protected healthy state reports `state: awaiting_release`, `release_required: true` and `operator_action_required: true`.

`--release`, `--rollback` and `--rearm` acquire the exclusive cutover lock. If another cutover process still holds that lock, they return a non-success `*_blocked` report and exit non-zero instead of falsely claiming success.

## Two-phase sequence

### Phase 1 — `--run`

1. Repeat the production preflight and validate the exact short-lived approval.
2. Save an exact private `runtime.php` backup outside the deployment.
3. Enable maintenance and financial read-only mode.
4. Disable new matchmaking, invitations, payments and shop orders.
5. Clear stale queue/search state and require all traffic/financial counters to be zero.
6. Seal JSON writes with the cutover write barrier.
7. Create and verify a fresh primary and external production backup pair.
8. Import accounts, opening balances, account ownership, normalized realtime data and the legacy financial archive.
9. Reject any failed nested import, unknown financial status or incomplete runtime schema.
10. Synchronize all nine runtime modules and run the full DB regression before publication.
11. Publish the all-module DB routing marker bound to build v103 while JSON remains the rollback driver.
12. Prove the sealed JSON fingerprint did not change and repeat the full DB regression.
13. Persist `state: awaiting_release`.
14. Keep maintenance, financial read-only and the JSON write block active.

Required phase-1 result:

- `ok: true`;
- `action: cutover_awaiting_release`;
- `state: awaiting_release`;
- `runtime_route: database`;
- all nine modules in `enabled_modules`;
- `maintenance_released: false`;
- `financial_read_only_released: false`;
- `json_write_block_removed: false`;
- `manual_smoke_required: true`;
- `release_required: true`.

Repeated `--run` calls in this state return `awaiting_release_noop`; they do not rerun imports, remove protection or open production.

### Protected manual smoke window

While state is `awaiting_release`:

- production remains in maintenance;
- financial writes remain blocked;
- JSON writes remain sealed;
- DB routing is active for all nine modules;
- only read-only smoke checks are allowed;
- any identity, balance, history, invite, match, shop, payment or weekly-bonus discrepancy requires `--rollback`, never `--release`.

### Phase 2 — `--release`

1. Require exact `awaiting_release` state and build.
2. Require the preserved exact runtime backup and active JSON write block.
3. Require maintenance and financial read-only to remain active.
4. Match the state plan/source fingerprints to the active runtime markers.
5. Prove the sealed JSON fingerprint is unchanged.
6. Repeat the complete DB runtime regression.
7. Restore the original maintenance/feature settings while preserving all-module DB routing.
8. Remove the JSON write block.
9. Run the final DB runtime regression.
10. Persist `state: completed`.

Required final result:

- `ok: true`;
- `action: cutover_completed`;
- `runtime_route: database`;
- all nine modules in `enabled_modules`;
- `rollback_driver: json`;
- `maintenance_released: true`;
- `financial_read_only_released: true`;
- `json_write_block_removed: true`;
- `json_snapshot_unchanged: true`;
- release regressions `ok: true`;
- `automatic_rollback_available: true`.

## Failure states

### Blocked before mutation

A failed preflight, missing/expired approval, fingerprint mismatch or other gate failure before the runtime backup is created returns:

- `action: cutover_blocked`;
- `production_changed: false`;
- `rollback.attempted: false`;
- no `rolled_back` state file.

Resolve the blocker, generate a fresh preflight/fingerprint and try again only after a new explicit approval.

### Recovery evidence missing

An active, unknown or terminal cutover state without exact recovery evidence returns `action: recovery_blocked`. The report uses `production_changed: null` and `storage_driver: unknown`; it never guesses that production is safe. Preserve the state and runtime files and perform immediate operator review.

### Automatic rollback after mutation begins

Any exception after recovery artifacts exist attempts to restore the exact prior private runtime, remove the JSON write barrier and verify DB routing is disabled. This includes failures during `--release`. Database rows are preserved for incident analysis; schema and ledger rows are never deleted automatically.

A non-noop rollback requires `production-cutover.runtime.backup`. A write-block file or an apparently disabled DB router is not a substitute for the exact prior runtime. If that backup is missing, the runner removes the write block when possible, records `state: rollback_failed`, leaves `storage_driver: unknown` and requires manual recovery.

A successful rollback records `state: rolled_back`. Later `--run` ticks remain a safe no-op until an operator reviews the incident.

The report is intentionally fail-closed: `rollback_succeeded` is true only when the exact runtime restore, write-block removal, DB-route disablement and terminal-state persistence all succeed. JSON is reported as the active storage driver only after all of those checks pass.

### Rearm after review

Before `--rearm`:

1. disable the private `production_cutover.enabled` approval;
2. require state exactly `rolled_back`, not `rollback_failed`;
3. require the state build to match v103;
4. require `runtime_restored`, `json_write_block_removed` and `database_runtime_disabled` to be true;
5. verify `--status` shows DB runtime disabled and no JSON write block;
6. preserve the exact non-empty `production-cutover.runtime.backup`;
7. review the rollback report and preserve any external incident notes.

`--rearm` refuses unresolved or partially recovered incidents. After every check passes, it archives the reviewed state and exact runtime backup outside the deployment. It does not change production routing. A fresh preflight and a new exact short-lived approval are mandatory before another `--run`.
