# MVP-14.8.1 — MySQL/MariaDB cutover readiness contract

This runbook is the contract for preparing the current JSON product for a later
MySQL/MariaDB runtime cutover. MVP-14.8.1 does **not** switch storage, write to
production, change product rules, or edit live `mgw_data`.

## Current architecture confirmed by the audit

- `StorageFactory` currently exposes only the `json` runtime driver.
- Several runtime entrypoints still force `StorageFactory::createJson()`.
- The shared storage contract still passes one legacy whole-array snapshot into
  `transaction()` and `readOnly()` callbacks.
- Normalized DB foundations already exist for accounts, realtime records,
  balances/ledger, legacy financial archives and final reconciliation.
- Therefore a config-only switch to MySQL/MariaDB is forbidden. MVP-14.8.2 must
  introduce a staging-only DB runtime adapter and migrate paths incrementally.

## Readiness command

Run manually in the test environment only:

```bash
/usr/bin/php /absolute/path/public_html/ops/deploy/cutover-readiness.php
```

This command is CLI-only and read-only with respect to JSON and DB data. It:

1. verifies the environment is `staging` or `local`;
2. confirms JSON is still the active runtime source;
3. checks DB connectivity and that all migrations are applied;
4. repeats the final JSON ↔ normalized DB reconciliation;
5. verifies the latest primary and external JSON backup snapshots;
6. scans the repository for direct `JsonDatabase` construction, forced JSON
   factory calls and whole-array storage callbacks;
7. returns a stable source inventory and readiness fingerprint.

Do not schedule this as a Cron. It is a manual preflight report.

## Required successful result

The report must contain:

```text
ok: true
ready_for_mvp_14_8_2: true
production_cutover_allowed: false
production_switch_performed: false
blockers: []
execution_mode: read-only
```

`runtime_adapter_work_items` are expected findings for MVP-14.8.2. They are not
permission to switch production. Any item under `blockers` must be resolved and
the command repeated.

## Preconditions for MVP-14.8.2

- test environment is isolated from production;
- JSON remains authoritative and writable;
- DB connection is healthy;
- migration count is current with zero pending migrations;
- final reconciliation has no blockers or migration gaps;
- both primary and external JSON snapshots verify by checksum;
- no code outside `JsonStorageAdapter` creates `JsonDatabase` directly;
- the source inventory fingerprint is recorded in the PR/test evidence.

## Contract for the staging DB runtime adapter

MVP-14.8.2 must satisfy all of the following:

1. The DB runtime is enabled only by a staging-safe feature flag.
2. Unknown/mixed driver configuration fails closed.
3. JSON stays available as the immediate rollback source.
4. No active match is moved between drivers mid-match.
5. Accounts resolve to the existing MGW-ID and account ownership.
6. Match and Gold remain separate assets.
7. Hidden Battleship/Domino state remains server/private only.
8. Repeated callbacks, imports and settlement operations stay idempotent.
9. Each migrated read/write path has a DB regression and a JSON rollback test.
10. `/app`, `/site`, product rules, production config and live data are not
    changed by the infrastructure adapter MVP.

## Freeze and drain contract for the later rehearsal

The later MVP-14.8.4 rehearsal must use this order:

1. enable maintenance for new matchmaking/invites only;
2. keep active matches running on their original JSON runtime;
3. wait until active matches drain to zero or explicitly abort the rehearsal;
4. create and verify a frozen primary + external JSON snapshot;
5. run final delta import and reconciliation;
6. switch the test feature flag to DB;
7. run immediate auth/game/invite/economy/history smoke checks;
8. rehearse rollback to the exact frozen JSON snapshot;
9. confirm the same users, balances, matches and notifications after rollback.

## Rollback triggers

Rollback is mandatory if any of these occurs:

- DB health or schema-current check fails;
- JSON ↔ DB count/hash reconciliation differs;
- Telegram identity opens another MGW-ID;
- Match/Gold totals or immutable ledger integrity differ;
- queue creates duplicate matches;
- an active match cannot reconnect or finish;
- private game state is exposed to another player;
- shop/payment/history/weekly bonus differs from the current product;
- rollback snapshot cannot be verified before the switch.

## Rollback action contract

Before production cutover, rollback must be executable without schema deletion:

1. stop new DB-routed requests;
2. restore the runtime flag to JSON;
3. point JSON storage to the verified frozen snapshot according to the approved
   restore runbook;
4. restart only after health reports `storage_driver=json`;
5. verify Telegram auth, one user, balances, history, invite and one full match;
6. keep DB data intact for incident analysis; do not rewrite ledger rows;
7. record the reason, timestamps, snapshot hash and affected checks.

Production cutover remains prohibited until MVP-14.8.2–14.8.5 are closed and the
user explicitly approves the production window.
