# MVP-14.8.6j — rollback-only staging DB-primary synthetic suite

This stacked sub-MVP exercises real application service behavior on the guarded DB-primary adapter inside one transaction that is deliberately rolled back.

## Safety boundary

- staging only;
- requires the guarded storage resolution from MVP-14.8.6i;
- requires the resolver latch, activation approval and fresh evidence v2;
- uses a private `0600` non-blocking lock;
- real API and webhook entrypoints are not invoked or modified;
- no HTTP route is added;
- no production access;
- no committed state revision;
- no projection outbox event;
- no synthetic identifiers are printed;
- deploying the code performs nothing.

## Transaction model

1. Resolve the DB-primary adapter through the full readiness guard.
2. Capture state status, canonical state SHA and outbox summary.
3. Open exactly one `DatabasePrimaryStateStorageAdapter::transaction()`.
4. Run synthetic service scenarios against the decoded compatibility state.
5. Throw `RuntimePrimarySyntheticRollback` after every assertion passes.
6. Require the database transaction layer to roll back the exception.
7. Capture state status, canonical state SHA and outbox summary again.
8. Fail unless all three are byte/structure equivalent to their pre-test values.

If the transaction commits or the rollback signal is missing, the suite fails.

## Covered real services

The suite uses actual project classes:

- `UserService`
  - create two users;
  - update an existing profile idempotently;
  - public-user projection;
  - profile statistics from a finished synthetic match;
- `WeeklyMatchEconomyService`
  - first welcome Match grant;
  - duplicate-grant prevention;
  - transaction creation;
- `NotificationService`
  - welcome notification;
  - payment-decision idempotency;
  - shop-order-decision idempotency;
  - unread count;
  - mark-all-read cycle.

All synthetic records exist only inside the rolled-back transaction.

## CLI

```bash
php ops/runtime/run-staging-db-primary-synthetic-suite.php
```

The command accepts no arguments. It first resolves guarded staging storage, then runs the rollback-only service suite.

Successful output includes:

- `action: synthetic_suite_passed_and_rolled_back`;
- `transaction_rolled_back: true`;
- `state_unchanged: true`;
- `snapshot_unchanged: true`;
- `outbox_unchanged: true`;
- scenario booleans/counts without synthetic IDs;
- `application_entrypoint_routed: false`;
- explicit unchanged Cron/production flags.

## Focused verification

```bash
bash ops/checks/db-primary-staging-synthetic-suite-local.sh
```

The suite runs every previous resolver/activation/evidence check and adds:

- actual service behavior inside a DB-primary transaction;
- explicit rollback sentinel handling;
- failure if transaction commits;
- state/status/snapshot/outbox equivalence after rollback;
- forced post-rollback drift rejection;
- staging-only and guarded-resolution requirements;
- private CLI lock and non-sensitive output contracts;
- no real entrypoint/Cron/production references.

## Next prerequisite

After a real staging run passes, a later sub-MVP may add a guarded selector to the real application entrypoints, still restricted to staging and disabled by default. The selector must preserve a one-step JSON rollback path and must not be enabled in production.
