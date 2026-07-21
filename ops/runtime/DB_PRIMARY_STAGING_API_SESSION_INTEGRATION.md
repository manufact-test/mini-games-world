# MVP-14.8.6m — guarded API-only staging lifecycle integration

This stacked sub-MVP connects the inactive request finalizer/session primitives from MVP-14.8.6l to the existing API storage path. It remains disabled by default and requires fresh lifecycle evidence v4 before one API request can use DB-primary storage.

## Default behavior

Nothing changes while the private selector/session latches are absent or disabled:

- `StorageFactory::createJson()` returns the existing JSON adapter;
- API behavior remains JSON-first;
- webhook behavior remains JSON-first;
- legacy JSON→DB bridges keep their existing behavior;
- no MySQL connection is opened by the selector;
- no finalizer hook is registered;
- no Cron or background worker is created.

## API-only boundary

The selector contract is now:

```text
v2-api-only-staging-db-primary-entrypoint-selector
```

Only this allowlist is valid:

```php
'allowed_entrypoints' => ['api'],
```

Webhook is forbidden even in staging. Enabling the selector outside `environment=staging` fails closed.

## Lifecycle evidence v4

Real API request routing requires:

```text
v4-staging-db-primary-api-lifecycle-evidence
```

V4 contains the complete DB/JSON/schema/parity/concurrency and selector evidence from v3, plus an exact API request lifecycle contour:

- `bot/api.php` and success-response helper ordering;
- inert selector bootstrap;
- API-only selector/config/context;
- API session coordinator;
- once-only request finalization hook;
- bounded request-session config;
- request finalizer and dynamic readiness;
- worker/auditor interfaces and adapters;
- leased projection worker;
- all-nine-module projector.

The verifier recomputes source SHA-256 values and checks from the current checkout. Any change to hook ordering, coordinator, finalizer, readiness, selector, response helper or projector invalidates the manifest.

Evidence alone does not enable the session and does not register the finalizer.

## Collect lifecycle evidence

Keep the selector and request session disabled while collecting:

```bash
php ops/runtime/collect-staging-db-primary-lifecycle-evidence.php \
  --output=/absolute/private/path/staging-db-primary-lifecycle-evidence-v4.json \
  --max-events=20
```

The collector:

1. validates the private output path before application bootstrap;
2. requires exact staging DB/commit approval;
3. holds the private rehearsal lock;
4. opens two independent staging DB connections for lease evidence;
5. performs the existing two-pass rehearsal;
6. adds exact lifecycle baseline and source evidence;
7. verifies v4 in memory;
8. writes with the atomic no-clobber private writer;
9. reads and verifies the written file again;
10. deletes output if post-write verification fails.

The command does not enable request routing.

## Coordinator order

For an enabled API request the coordinator must complete this order:

1. verify external private config;
2. verify exact DB identity, commit, approval and evidence v4;
3. create one staging DB connection;
4. run dynamic request-session readiness against current DB state and fixed JSON rollback baseline;
5. create DB-primary storage with mandatory transactional outbox;
6. fully build worker, finalizer and once-only hook;
7. validate and prepare a local hook registry with finalizer first;
8. install the immutable request-local DB storage context;
9. publish the prepared hook registry.

An invalid hook registry fails before request-local DB context installation. A selector failure is sticky for the rest of that request and cannot fall through to JSON silently.

## Success response contract

The finalization hook executes first inside the existing API success hook boundary and before filters/response output.

A successful API response is possible only when:

- every outbox revision through current DB state is completed in order;
- current event status is `completed`;
- all nine normalized modules pass read-only parity against exact current revision/SHA;
- state/event/queue remain unchanged during audit;
- request stays inside bounded revision and expiry limits;
- legacy JSON bridges remain suppressed;
- webhook remains forbidden;
- production remains unchanged.

A finalizer exception occurs inside the existing API `try/catch`, so the success response is not sent. No automatic JSON fallback happens inside the failed request.

## Private enablement shape

This PR does not modify private config. A future controlled staging window requires all existing approval/outbox settings plus both exact latches:

```php
'staging_db_primary_entrypoint_selector' => [
    'enabled' => true,
    'contract_version' => 'v2-api-only-staging-db-primary-entrypoint-selector',
    'allowed_entrypoints' => ['api'],
],

'staging_db_primary_request_session' => [
    'enabled' => true,
    'contract_version' => 'v1-api-only-bounded-request-session',
    'baseline_state_revision' => 1,
    'max_revision_delta' => 4,
    'max_worker_ticks' => 4,
    'lease_seconds' => 60,
    'expires_at_utc' => '<explicit ISO-8601 offset, maximum 30 minutes ahead>',
],
```

The exact baseline revision comes from the verified v4 manifest. Values shown above are examples, not activation instructions.

## Immediate rollback between requests

Disable both latches before the next request:

```php
'staging_db_primary_entrypoint_selector' => [
    'enabled' => false,
    'contract_version' => 'v2-api-only-staging-db-primary-entrypoint-selector',
    'allowed_entrypoints' => [],
],

'staging_db_primary_request_session' => [
    'enabled' => false,
],
```

Also disable the short-lived activation approval after the window. JSON remains the rollback source.

## Safety boundary

- selector/session remain disabled in repository config;
- no Hostinger config changed;
- staging MySQL not contacted by this PR work;
- no API request routed during development;
- webhook remains forbidden;
- production forbidden;
- Cron unchanged;
- merge/deploy/cutover not performed;
- deployment of code alone performs nothing.

## Focused verification

```bash
bash ops/checks/db-primary-staging-api-session-integration-local.sh
```

The suite runs every previous selector/finalizer regression and additionally covers:

- API-only selector and lifecycle v4 context;
- exact v4 source/baseline/commit binding;
- generic evidence-gate v4 dispatch;
- finalization hook once-only behavior;
- atomic coordinator order: hook prepared → context installed → hook registry published;
- no partial context on invalid hook registry;
- sticky failure before context reuse;
- two-pass v4 collector behavior;
- lifecycle collector CLI ordering, private output and no-secret contract.

## Next prerequisite

After this Draft PR passes the full focused suite on PHP 8.3, a separate staging execution checkpoint must collect fresh v4 evidence and run an API-only smoke window against the isolated `mgw_stage` database. Selector/session must be disabled immediately after the bounded window, followed by JSON/DB/outbox parity audit. Production and webhook remain out of scope.
