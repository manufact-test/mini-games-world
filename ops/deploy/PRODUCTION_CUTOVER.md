# MVP-14.9 — controlled production JSON → DB cutover

This release adds the controlled production cutover. Deploying the release does not switch production. JSON remains the global storage and exact rollback source until an explicitly approved operation completes.

## Hard gates

The cutover runner refuses execution unless all of the following are true:

- environment is exactly `production`;
- build is exactly `v103-mvp14-production-cutover`;
- production DB is connected and has zero pending migrations;
- a fresh read-only production preflight is clean;
- the private approval is enabled, bound to this build, bound to the exact current preflight plan fingerprint and not expired;
- the approval expiry is an ISO-8601 string containing `Z` or an explicit UTC offset;
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
    'approval_expires_at_utc' => '<short-lived ISO-8601 timestamp with Z or explicit UTC offset>',
    'require_primary_backup' => true,
    'require_external_copy' => true,
],
```

Unix timestamps and date strings without an explicit offset are rejected. The approval must be disabled immediately after the cutover command completes or rolls back.

## Commands

Run:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --run
```

Status:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --status
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

Emergency `--status`, `--rollback` and reviewed `--rearm` do **not** initialize the production DB or backup manager and do **not** acquire the managed-migrations lock. They still require the exact v103 build, production environment and private config/runtime directory.

The cutover CLI intentionally boots from the private base configuration without automatically executing `runtime.php` or the staging rehearsal control file. After recovery-state checks, the runner reads and merges `runtime.php` explicitly for the production preflight and the actual operation. This keeps `--status` and `--rollback` usable when `runtime.php` is malformed or interrupted. Normal web, API, webhook, bot and Cron bootstrap behavior is unchanged because the bypass constant exists only inside this cutover CLI entrypoint.

`--status` is read-only and does not acquire the exclusive cutover lock, so it can report an active or interrupted operation from atomically published state/runtime files. It also validates the persisted state against the actual runtime, write block, preserved backup and enabled module set. A mismatched terminal state returns `ok: false`, `state_contract_error` and `operator_action_required: true`.

`--rollback` and `--rearm` do acquire the exclusive cutover lock. If another cutover process still holds that lock, these commands return a non-success `*_blocked` report and exit non-zero instead of falsely claiming a successful no-op. Stop or confirm the active process and retry the emergency command.

Hostinger scheduling uses one temporary five-minute Cron for the selected mode. Repeated `--run` ticks are locked and idempotent. Do not create the `--run` Cron until the exact build, preflight fingerprint and short-lived approval are confirmed.

## Automatic sequence

1. Repeat the production preflight and validate exact approval.
2. Save an exact private `runtime.php` backup outside the deployment.
3. Enable maintenance and financial read-only mode.
4. Disable new matchmaking, invitations, payments and shop orders.
5. Clear stale queue/search state and require all traffic/financial counters to be zero.
6. Seal JSON writes with the cutover write barrier.
7. Create and verify a fresh primary and external production backup pair.
8. Import accounts, opening balances, account ownership, normalized realtime data and the legacy financial archive.
9. Install/verify the shop, payment and weekly-bonus runtime schemas.
10. Synchronize all nine runtime modules and run the full DB regression before publication.
11. Publish the all-module DB routing marker bound to build v103 while JSON remains the rollback driver.
12. Remove the write barrier, prove the JSON fingerprint did not change and repeat the full DB regression.
13. Release maintenance/read-only mode and run the final regression.
14. Keep the private runtime backup for the manual validation window.

## Failure states

### Blocked before mutation

A failed preflight, missing/expired approval, fingerprint mismatch or other gate failure before the runtime backup is created returns:

- `action: cutover_blocked`;
- `production_changed: false`;
- `rollback.attempted: false`;
- no `rolled_back` state file.

Resolve the blocker, generate a fresh preflight/fingerprint and try again only after a new explicit approval.

### Recovery evidence missing

An active, unknown or terminal cutover state without the exact recovery evidence returns `action: recovery_blocked`. The report uses `production_changed: null` and `storage_driver: unknown`; it never guesses that production is safe. Preserve the state and runtime files and perform immediate operator review.

### Automatic rollback after mutation begins

Any exception after recovery artifacts exist attempts to restore the exact prior private runtime, remove the JSON write barrier and verify DB routing is disabled. Database rows are preserved for incident analysis; schema and ledger rows are never deleted automatically.

A non-noop rollback requires `production-cutover.runtime.backup`. A write-block file or an apparently disabled DB router is not a substitute for the exact prior runtime. If that backup is missing, the runner removes the write block when possible, records `state: rollback_failed`, leaves `storage_driver: unknown` and requires manual recovery.

A successful rollback records `state: rolled_back`. Later `--run` ticks remain a safe no-op until an operator reviews the incident.

The report is intentionally fail-closed: `rollback_succeeded` is true only when the exact runtime restore, write-block removal, DB-route disablement and terminal-state persistence all succeed. JSON is reported as the active storage driver only after all of those checks pass.

### Rearm after review

Before `--rearm`:

1. disable the private `production_cutover.enabled` approval;
2. require state exactly `rolled_back`, not `rollback_failed`;
3. require `runtime_restored`, `json_write_block_removed` and `database_runtime_disabled` to be true in the persisted state;
4. verify `--status` shows DB runtime disabled and no JSON write block;
5. preserve the exact non-empty `production-cutover.runtime.backup`;
6. review the rollback report and preserve any external incident notes.

`--rearm` refuses unresolved or partially recovered incidents. After every check passes, it archives the reviewed state and exact runtime backup outside the deployment. It does not change production routing. A fresh preflight and a new exact short-lived approval are mandatory before another `--run`.

## Required success result

- `ok: true`
- `action: cutover_completed`
- `runtime_route: database`
- all nine modules in `enabled_modules`
- `rollback_driver: json`
- `maintenance_released: true`
- `financial_read_only_released: true`
- `json_write_block_removed: true`
- `json_snapshot_unchanged: true`
- all three regression reports `ok: true`
- `automatic_rollback_available: true`

A successful command is followed immediately by the manual production smoke checklist. Any identity, balance, history, invite, match, shop, payment or weekly-bonus discrepancy triggers `--rollback`.
