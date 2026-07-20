# MVP-14.9 — controlled production JSON → DB cutover

This release adds the controlled production cutover. It does not switch production merely by being deployed. JSON remains the global storage and exact rollback source.

## Hard gates

The cutover runner refuses execution unless all of the following are true:

- environment is exactly `production`;
- build is exactly `v103-mvp14-production-cutover`;
- production DB is connected and has zero pending migrations;
- a fresh read-only production preflight is clean;
- the private approval is enabled, bound to this build, bound to the exact current preflight plan fingerprint and not expired;
- JSON is still the active global/rollback driver;
- production DB module routing is still disabled;
- primary and external backup locations are configured.

## Private approval

Add only to the private production `config.php`, never to GitHub:

```php
'production_cutover' => [
    'enabled' => true,
    'expected_build' => 'v103-mvp14-production-cutover',
    'approval_plan_fingerprint' => '<exact fingerprint from the v103 preflight>',
    'approval_expires_at_utc' => '<short-lived UTC timestamp>',
    'require_primary_backup' => true,
    'require_external_copy' => true,
],
```

The approval must be disabled immediately after the cutover command completes.

## Command

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --run
```

Hostinger scheduling uses one temporary five-minute Cron. Repeated ticks are locked and idempotent.

Status:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --status
```

Explicit rollback:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/production-cutover.php --rollback
```

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
11. Publish the all-module DB routing marker while JSON remains the rollback driver.
12. Remove the write barrier, prove the JSON fingerprint did not change and repeat the full DB regression.
13. Release maintenance/read-only mode and run the final regression.
14. Keep the private runtime backup for the manual validation window.

## Automatic rollback

Any exception or recovered interruption restores the exact prior private runtime, removes the JSON write barrier and verifies DB routing is disabled. Database rows are preserved for incident analysis; schema and ledger rows are never deleted automatically.

A completed cutover remains idempotent on later Cron ticks. A rolled-back cutover remains a safe no-op until an operator reviews the failure.

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
