# MVP-14.8.6a — DB-primary compatibility state foundation

This sub-MVP adds an inactive database-backed implementation of `StorageAdapterInterface`. It preserves the current array-based service contract while moving the authoritative compatibility snapshot into a transactional DB row.

## Safety boundary

- no application entrypoint is switched by this sub-MVP;
- `bot/api.php` and `WebhookHandler.php` remain JSON-first until later reviewed sub-MVPs;
- production config is not changed;
- no schema is installed automatically on bootstrap;
- no JSON data is imported automatically;
- existing `StorageFactory::createJson(...)` call sites remain unchanged;
- rollback JSON data remains untouched.

## Components

### `RuntimePrimaryStateSchemaInstaller`

Creates and verifies the singleton table `mgw_runtime_primary_state` for MySQL/MariaDB or SQLite. The table stores:

- singleton ID `1`;
- monotonic revision;
- canonical state JSON;
- SHA-256 fingerprint;
- created/updated UTC timestamps.

The installer is explicit and idempotent. Merely deploying the code does not create the table.

### `DatabasePrimaryStateStorageAdapter`

Implements the existing storage interface:

- `initializeFromSnapshot()` seeds the singleton once and rejects a different second seed;
- `transaction()` locks the singleton, verifies its fingerprint, executes the existing array callback and commits with optimistic revision checking;
- callback exceptions roll back the DB transaction;
- unchanged callbacks do not advance the revision;
- `readOnly()` verifies the stored fingerprint before exposing data;
- `status()` returns only non-sensitive revision and fingerprint metadata.

### `StorageFactory`

Adds the explicit `database` driver and `createDatabasePrimary()`. Nothing selects this driver automatically in this sub-MVP.

## Focused verification

```bash
bash ops/checks/db-primary-state-local.sh
```

The focused regression uses a strict fake DB connection, so transaction, rollback, revision and fingerprint checks run even when `pdo_sqlite` is unavailable. A later integration sub-MVP will add staging MySQL installation, seed, parity and concurrency rehearsals before any entrypoint can select this adapter.

## Next prerequisite

The next sub-MVP must add a projection coordinator that updates the normalized runtime module tables from each committed DB-primary compatibility state transaction. API and webhook entrypoints must not switch until that projection is transactional, all nine modules pass parity, and the cutover contract is satisfied.
