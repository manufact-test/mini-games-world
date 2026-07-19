# MVP-14.8.4i — Weekly bonus DB runtime

## Scope

- Staging/local only.
- JSON remains the global storage and rollback source.
- The `weekly_bonus` runtime module mirrors user qualification/grant state and its public status into MySQL.
- Weekly balance changes continue to pass through the immutable economy ledger.
- Match qualification continues to read the staged realtime DB mirror.
- Weekly notifications are synchronized to the notification DB runtime after successful JSON writes.
- Production routing is forbidden.

## Automatic activation

The permanent staging operations runner executes these operations in order after deployment:

1. `mvp-14.8.4i-weekly-bonus-runtime-v1`
   - installs and verifies the 12-column weekly runtime schema;
   - enables only `database_runtime.modules.weekly_bonus`;
   - synchronizes realtime, economy, notifications and weekly state;
   - performs a read-only parity audit;
   - restores the previous private runtime config automatically on failure.

2. `mvp-14.8.4j-db-runtime-regression-v1`
   - verifies all nine runtime modules;
   - audits account ownership, realtime, invites, notifications, history, economy, shop, payments and weekly bonus;
   - verifies shop/payment/weekly runtime schemas;
   - reports aggregate counts and fingerprints only.

The permanent Cron command is unchanged:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/staging-operations-runner.php --run
```

## Safety invariants

- Do not switch `storage_driver` away from `json` during this stage.
- Do not enable DB runtime outside staging/local.
- Do not delete extra DB rows automatically; treat them as blockers.
- Do not alter production or permanent backup/migration schedules.
- A failed activation disables only `weekly_bonus`; the idempotent schema remains for repair and retry.
