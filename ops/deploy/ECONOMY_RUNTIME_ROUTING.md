# MVP-14.8.4d — staging economy DB runtime

This step keeps JSON as the global and rollback source while synchronizing every successful staging economy mutation into the normalized balance and immutable ledger tables.

## Scope

- enabled only when `database_runtime.accounts` and `database_runtime.economy` are both true;
- attaches to successful `api.php` responses and completed `webhook.php` handling;
- reads one immutable JSON snapshot per synchronization;
- updates the exact economy shadow;
- applies only the balance difference through idempotent `LedgerWriteService` entries;
- verifies balance totals, ownership, ledger hash chains and zero active reservations;
- does not change product prices, Match/Gold rules, shop rules, payments or weekly bonus rules.

## Safety

- environment remains `staging`;
- global `storage_driver` remains `json`;
- rollback driver remains `json`;
- production routing is forbidden by `RuntimeStorageRouter`;
- no balance row is overwritten directly;
- repeat synchronization is idempotent;
- output contains aggregate counts, totals and fingerprints only.

## Deployment order

1. Merge after CI.
2. Deploy the code to staging with `economy` still disabled.
3. Confirm `/bot/health.php` reports build `v95-mvp14-db-economy-routing`, JSON global storage and the previous enabled modules only.
4. Enable only `database_runtime.modules.economy` in staging private `runtime.php`.
5. Open the staging Mini App once and confirm the existing Match/Gold balances and profile load normally.
6. Run one temporary read-only audit:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/economy-runtime-audit.php
```

Expected result:

- `ok: true`;
- `shadow_delta_count: 0`;
- reconciliation `ready: true`;
- source and database totals equal;
- `planned_delta_count: 0`;
- `integrity_failure_count: 0`;
- `active_reservation_count: 0`;
- blockers empty;
- production unchanged.

7. Delete the temporary audit Cron.

## Rollback

Disable only `database_runtime.modules.economy` in staging private `runtime.php`. Do not delete JSON, balance rows, ledger entries or shadow rows. JSON remains the immediate runtime and rollback source.
