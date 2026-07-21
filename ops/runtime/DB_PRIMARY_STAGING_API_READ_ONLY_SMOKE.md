# MVP-14.8.6n — guarded read-only staging API lifecycle smoke

This stacked sub-MVP adds a CLI-only smoke controller for the API lifecycle introduced by MVP-14.8.6m. It exercises the lazy storage selector, API coordinator, immutable request context, success-hook ordering and request finalizer without executing an HTTP route and without committing a new DB-primary revision or outbox event.

## Safety boundary

- CLI only;
- staging only;
- requires a private verified lifecycle evidence v4 file;
- persistent selector, request-session and activation latches must all be disabled;
- temporary latches exist only in the CLI process memory;
- the persistent private config file is not edited;
- `bot/api.php` is not required or executed;
- no HTTP route is added;
- webhook remains forbidden;
- no Cron is added;
- production remains forbidden;
- deployment alone performs nothing.

## In-memory overlay

`RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay` starts from the already loaded private staging config and rejects it when any persistent activation latch is enabled.

It then verifies:

- external private config location;
- evidence file location, permissions and size;
- lifecycle manifest version v4;
- current checkout commit;
- exact staging database identity;
- evidence fingerprint;
- lifecycle baseline revision and state SHA.

Only after verification does it create an in-memory copy containing short-lived values for:

- activation approval;
- API-only entrypoint selector;
- API-only request session.

The request session is bounded to:

- `max_revision_delta = 1`;
- `max_worker_ticks = 1`;
- `lease_seconds = 60`;
- TTL between 60 and 600 seconds.

The source config array and private config file remain unchanged.

## Lazy API lifecycle path

The CLI keeps its own file as the executed script during application bootstrap. After the in-memory overlay is ready it temporarily sets the script basename to `api.php` and calls:

```php
StorageFactory::createJson(...)
```

This exercises the real lazy path:

1. `StorageFactory` detects the API basename;
2. staging selector validates the API-only latch;
3. coordinator validates evidence v4 and bounded session;
4. dynamic readiness audits the current staging DB state;
5. request-local DB-primary storage context is installed;
6. finalizer is registered as the first success hook.

The CLI does not include or execute `bot/api.php`.

## Exact request-context contract

Before reading the staging DB, the smoke independently verifies that the installed request context contains:

- lifecycle evidence v4;
- the exact selector evidence contract;
- the exact bounded request-session contract;
- valid evidence, selector, session and database identity fingerprints;
- a positive state revision and valid state SHA;
- the exact DB-primary storage instance passed to the smoke;
- `dynamic_session_readiness = true`;
- `request_finalizer_registered = true`;
- `webhook_allowed = false`;
- `production_changed = false`.

It also calls `RuntimePrimaryEntrypointBridgeGuard::legacyJsonBridgeAllowed()` before and after finalization. The result must remain `false` for the whole smoke.

After the first DB capture, the immutable context revision/SHA must equal the exact current DB-primary status revision/SHA. A stale or substituted context blocks the smoke before success hooks.

## Read-only smoke contract

The smoke operation captures before-state evidence using only:

- `DatabasePrimaryStateStorageAdapter::status()`;
- `DatabasePrimaryStateStorageAdapter::readOnly()`;
- `DatabaseConnectionInterface::fetchAll()`.

It never calls storage transactions or database execute methods.

Before success hooks:

- current state revision/SHA must be valid;
- request context revision/SHA must match current state exactly;
- outbox must contain a contiguous completed chain from revision 1 through current revision;
- current outbox SHA must match current state SHA;
- read-only snapshot SHA must match status SHA;
- legacy JSON bridges must be suppressed.

Then it:

1. removes any stale finalization report;
2. invokes the prepared API success hooks;
3. requires finalizer completion with `worker_tick_count = 0`;
4. requires finalizer revision/SHA to equal the exact before-state;
5. verifies legacy JSON bridges are still suppressed;
6. applies API data filters to a sentinel payload and requires byte-structure equivalence;
7. captures state/outbox again;
8. requires exact before/after equality.

Any old evidence version, invalid fingerprint, stale context, enabled legacy bridge, worker tick, state change, outbox timestamp/status change, missing event, filter mutation or stale report blocks the smoke.

## CLI

The command is intentionally not run by deployment:

```bash
php ops/runtime/run-staging-db-primary-api-read-only-smoke.php \
  --evidence=/absolute/private/path/staging-db-primary-lifecycle-evidence-v4.json \
  --ttl-seconds=300
```

The CLI:

- validates arguments before bootstrap;
- requires staging environment;
- verifies external private config;
- holds the existing private rehearsal lock;
- builds only an in-memory overlay;
- opens the coordinator DB connection plus a separate read-only inspector connection;
- exercises the lazy selector/finalizer path;
- emits only safe fingerprints/counts and unchanged flags;
- restores process globals before JSON output and process exit.

Successful output requires:

- `staging_api_read_only_smoke_passed`;
- `worker_tick_count: 0`;
- `context_state_matched: true`;
- `lifecycle_v4_verified: true`;
- `legacy_json_bridges_suppressed: true`;
- `state_unchanged: true`;
- `snapshot_unchanged: true`;
- `outbox_unchanged: true`;
- `data_filters_unchanged: true`;
- `request_finalizer_completed: true`;
- `persistent_config_changed: false`;
- `http_route_added: false`;
- `webhook_allowed: false`;
- `production_changed: false`.

## Focused verification

```bash
bash ops/checks/db-primary-staging-api-read-only-smoke-local.sh
```

The focused suite runs the complete API lifecycle integration suite and adds:

- exact no-op smoke success;
- old evidence version rejection;
- stale context revision/SHA rejection;
- enabled legacy bridge rejection;
- stale finalization report rejection;
- non-zero worker tick rejection;
- data-filter mutation rejection;
- incomplete outbox-chain rejection;
- state/outbox mutation rejection;
- persistent-latch rejection;
- v3/different DB/different commit evidence rejection;
- TTL bounds;
- no transaction/execute/file-write static contract;
- CLI ordering, cleanup, no-route and no-secret contract.

## Next prerequisite

After the full focused suite passes on PHP 8.3, the first real server action is still a controlled staging-only run against `mgw_stage` with a fresh private evidence v4 file. No production or webhook action is permitted. A successful read-only smoke is required before any mutating API smoke is designed.
