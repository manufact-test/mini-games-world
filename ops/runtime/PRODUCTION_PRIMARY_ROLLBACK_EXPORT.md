# MVP-14.10c — fresh production DB-to-JSON rollback export

This layer creates and verifies a fresh JSON rollback snapshot from the DB-primary compatibility state. It does not replace live JSON, alter production configuration or perform a rollback.

## Why this layer exists

After production accepts DB-primary writes, the old pre-cutover JSON snapshot is no longer current. A safe rollback therefore requires a new snapshot exported from the exact latest database revision while production mutations are stopped.

## Required operating state

The export gate accepts only an exact production state:

- environment is `production`;
- global rollback driver remains `json`;
- maintenance mode is exact boolean `true`;
- financial read-only is exact boolean `true`;
- DB-primary runtime and production activation are exact boolean `true`;
- activation build is `v103-mvp14-production-cutover`;
- activation plan/source fingerprints are valid and match cutover state;
- all nine approved runtime modules are exact boolean `true` with no unknown keys;
- cutover state is `completed`;
- runtime backup and DB route publication are recorded;
- the old cutover JSON write block is released;
- database identity matches the authorization.

## One-use authorization

The CLI requires a private file named:

`production-rollback-export-authorization.json`

The file must have mode `0600` and share the exact external private directory with `config.php` and `production-cutover.json`.

Authorization is bound to:

- a 32-character lowercase hexadecimal request ID;
- expected DB state revision and SHA-256;
- database identity fingerprint;
- activation plan/source fingerprints;
- exact output-root fingerprint;
- request and expiry times;
- a non-empty reason represented in reports only by SHA-256.

The allowed TTL is 60–900 seconds. The request ID becomes one-use because the final export directory is named `rollback-<request-id>` and an existing or partial directory blocks reuse.

## Export transaction

The exporter opens one MySQL transaction and holds the singleton compatibility-state row with `FOR UPDATE` while it:

1. verifies the authorized revision and state SHA;
2. verifies the exact 10-key JSON compatibility schema;
3. verifies a contiguous completed outbox chain from revision 1 through the current revision;
4. verifies zero pending, processing or failed outbox events;
5. runs a read-only parity audit across all nine normalized modules;
6. writes a private temporary JSON export;
7. verifies every checksum and reconstructs the complete state SHA;
8. atomically renames the temporary directory to its final one-use name;
9. verifies the final artifact again before releasing the DB lock.

The exporter performs no SQL writes. A real MySQL CI test compares state and outbox rows before and after export.

## Export format

The artifact is compatible with the existing `BackupManager` format:

- `data/users.json`
- `data/games.json`
- `data/queue.json`
- `data/transactions.json`
- `data/support.json`
- `data/shop_orders.json`
- `data/payments.json`
- `data/notifications.json`
- `data/invites.json`
- `data/system.json`
- `rollback.json`
- `checksums.sha256`
- `manifest.json`
- `COMPLETE`

Export and data directories use exact mode `0700`; every file uses exact mode `0600`.

## Restore boundary

`ProductionPrimaryRollbackRestoreService` restores only through the existing `BackupManager` into a separate empty directory outside the deployed project. It then secures permissions and reconstructs the full state SHA again.

This sub-MVP does **not** implement live JSON replacement. It does not remove DB routing, change runtime flags, release maintenance, change webhook registration or alter Cron.

## CLI contract

The CLI is:

`ops/runtime/run-production-primary-rollback-export.php`

It requires all six explicit options:

- `--config=/absolute/private/config.php`
- `--cutover-state=/absolute/private/production-cutover.json`
- `--authorization=/absolute/private/production-rollback-export-authorization.json`
- `--output-root=/absolute/private/rollback-exports`
- `--request-id=<32 lowercase hex>`
- `--confirm=EXPORT_DB_PRIMARY_TO_JSON_ROLLBACK`

No default production paths are guessed. Backslashes, relative paths, duplicate options, wrong filenames and wrong permissions fail before database contact.

## What remains blocked

Production cutover and live rollback remain blocked. A later sub-MVP must implement and verify the final controlled live JSON replacement plus DB-route deactivation/recovery ordering. That later action still requires fresh explicit production approval.
