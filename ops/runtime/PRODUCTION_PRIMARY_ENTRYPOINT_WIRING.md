# MVP-14.10b — guarded production API and webhook wiring

This sub-MVP connects the existing application storage calls to a production DB-primary request context in code only. Nothing in this branch is deployed or activated.

## Activation boundary

Production DB-primary wiring is considered only when all of the following are exact:

- environment is `production`;
- global `storage_driver` remains `json` as the rollback source;
- `database_runtime.enabled` is boolean `true`;
- `database_runtime.production_activated` is boolean `true`;
- activation build is `v103-mvp14-production-cutover`;
- activation plan and source fingerprints are lowercase SHA-256 values;
- all nine approved modules are present with strict boolean `true` and no unknown keys;
- private activation contract passes;
- cutover state is `completed`, not `awaiting_release`;
- the JSON write block is absent;
- database identity matches activation evidence.

Absent activation markers keep production JSON-first. Inconsistent or incomplete markers fail closed.

## Request wiring

`StorageFactory::createJson()` remains the stable call used throughout API, webhook handlers and admin guards. For an exact completed production activation it returns the request-scoped `ProductionPrimaryAtomicStorageAdapter`; otherwise it returns the existing JSON adapter or the existing staging context.

The webhook entrypoint initializes storage before any guard or handler. This prevents a storage-free webhook path from executing a legacy JSON-to-DB success bridge after cutover. While production DB-primary is explicitly active, an internal webhook failure returns HTTP 503 so Telegram can retry the update. Existing JSON-mode failures preserve the previous HTTP 200 behavior.

Legacy runtime bridges are suppressed when either production or staging DB-primary context is installed.

## Atomic write contract

A production mutation executes inside one outer MySQL transaction:

1. lock the compatibility-state singleton;
2. verify the complete projection queue and all-nine-module baseline parity;
3. run the existing callback against the compatibility snapshot;
4. write exactly one new state revision and its outbox event when data changed;
5. run exactly one projection worker tick;
6. project all nine normalized modules;
7. verify final state, outbox and all-module parity;
8. commit only after every check succeeds.

Nested storage and worker transactions use PDO savepoints. Any callback, state, outbox, projection or audit failure bubbles to the outer transaction and rolls back the entire request.

Direct coordinator execution remains forbidden. Only the guarded atomic storage context may execute a request.

## Rollback boundary

The pre-cutover JSON snapshot is still the emergency rollback source. After production accepts DB-primary writes, returning to JSON safely requires a fresh verified DB-to-JSON export immediately before rollback. This sub-MVP exposes that requirement but does not implement the export.

Therefore this branch does **not** authorize:

- merging into `main`;
- deploying production;
- changing private production config;
- starting or releasing cutover;
- changing webhook registration or Cron;
- contacting or mutating the production database;
- rolling back from an already active DB-primary runtime.

The next sub-MVP must implement and test the fresh DB-to-JSON rollback export and its restoration contract before any production cutover approval can be considered.
