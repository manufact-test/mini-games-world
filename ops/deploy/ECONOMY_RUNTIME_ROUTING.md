# MVP-14.8.4d — staging economy DB runtime

This step keeps JSON as the global and rollback source while synchronizing every successful staging economy mutation into the normalized balance and immutable ledger tables.

## Scope

- enabled only when `database_runtime.accounts` and `database_runtime.economy` are both true;
- attaches to successful `api.php` responses and completed `webhook.php` handling;
- reads one immutable JSON snapshot per synchronization;
- updates the exact economy shadow;
- applies only the balance difference through idempotent `LedgerWriteService` entries;
- creates guarded ownership and opening balances for new staging users;
- verifies balance totals, ownership, ledger hash chains and zero active reservations;
- does not change product prices, Match/Gold rules, shop rules, payments or weekly bonus rules.

## Safety

- environment remains `staging`;
- global `storage_driver` remains `json`;
- rollback driver remains `json`;
- production routing is forbidden by `RuntimeStorageRouter`;
- no balance row is overwritten directly;
- repeat synchronization is idempotent;
- activation uses a private recovery backup and restores the previous runtime config on failure;
- a completed temporary Cron tick becomes a no-op;
- output contains aggregate counts, totals and fingerprints only.

## One-shot deployment check

1. Merge after CI.
2. Deploy the code to staging with `economy` still disabled.
3. Confirm `/bot/health.php` reports build `v95-mvp14-db-economy-routing`, JSON global storage and the previous enabled modules only.
4. Run one temporary Cron command:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/economy-runtime-activate.php --run
```

The command atomically:

1. backs up the private `runtime.php`;
2. enables only `database_runtime.modules.economy`;
3. synchronizes the current JSON economy snapshot;
4. bootstraps missing runtime ownership/balance rows safely;
5. applies differences only as immutable ledger entries;
6. runs the read-only parity and integrity audit;
7. deletes the recovery backup after success;
8. restores the previous runtime config automatically if any step fails.

Expected result:

- `ok: true`;
- `state: completed`;
- `economy_enabled: true`;
- `shadow_delta_count: 0` in the final audit;
- reconciliation `ready: true`;
- source and database totals equal;
- `planned_delta_count: 0`;
- `integrity_failure_count: 0`;
- `active_reservation_count: 0`;
- blockers empty;
- production unchanged.

After one completed output, delete the temporary Cron. Repeated ticks are safe no-ops.

## Status and rollback

```bash
/usr/bin/php ops/deploy/economy-runtime-activate.php --status
/usr/bin/php ops/deploy/economy-runtime-activate.php --disable --reason="staging rollback"
```

Rollback disables only `database_runtime.modules.economy`. Do not delete JSON, balance rows, ledger entries or shadow rows. JSON remains the immediate runtime and rollback source.
