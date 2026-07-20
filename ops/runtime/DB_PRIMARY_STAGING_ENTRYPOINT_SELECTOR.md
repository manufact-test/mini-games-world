# MVP-14.8.6k — guarded staging API/webhook DB-primary selector

This sub-MVP makes the existing API and webhook request flow capable of resolving DB-primary storage only inside staging and only after the full evidence/approval/resolver chain succeeds.

## Default state

JSON remains the default and rollback source.

- the selector is disabled when its private config block is absent;
- production with the selector disabled stays on JSON;
- staging with the selector disabled stays on JSON;
- an entrypoint omitted from the allowlist stays on JSON;
- disabled paths do not open the evidence file or a DB connection.

## Request-local routing

Existing application code still calls `StorageFactory::createJson(...)`.

The factory attempts the guarded selector only when the current script basename is exactly:

- `api.php`;
- `webhook.php`.

After a successful guarded resolution, one `DatabasePrimaryStateStorageAdapter` is installed in `RuntimePrimaryEntrypointStorageContext`. Every later `createJson()` call in that request, including calls from webhook guards, receives the exact same adapter.

The context is immutable and accepts only:

- storage driver `database`;
- completed resolver/readiness evidence;
- projection outbox enabled;
- drift check passed;
- selector-aware evidence v3;
- exact selector contract version;
- valid selector evidence fingerprint.

A selector error is sticky for the lifetime of the request. A later storage call cannot silently fall back to JSON after a failed DB selection attempt.

## Legacy JSON bridge suppression

The existing bootstrap registers JSON-to-DB success hooks and API normalization filters for realtime, economy, shop, payments and weekly bonus. Those hooks are correct for the JSON-first application, but they must not run after a request has been routed to DB-primary storage.

`RuntimePrimaryEntrypointBridgeGuard` therefore checks the request-local context at hook execution time:

- JSON requests keep all existing bridge hooks and filters;
- DB-primary requests skip every legacy `synchronizeCurrentJson()` success hook;
- payment and weekly API filters return the DB-primary response unchanged;
- the webhook legacy hook batch becomes a no-op;
- the projection outbox remains the only post-commit projection mechanism for a DB-primary request.

The bootstrap and bridge guard are both included in evidence v3. Removing the guard or an individual hook/filter check invalidates the evidence.

## Evidence v3

Real request routing requires:

```text
v3-staging-db-primary-selector-evidence
```

V3 contains all v2 database, JSON, schema, parity and concurrency evidence plus SHA-256 fingerprints for the complete request storage contour:

- API and webhook entrypoints;
- `WebhookHandler`;
- every current webhook guard that can participate before the handler;
- application bootstrap and legacy bridge guard;
- `StorageFactory`;
- selector bootstrap, config, selector and request-local context.

The verifier recomputes the exact source set, checks and SHA values from the current checkout. V1 or v2 evidence cannot authorize real API/webhook routing. A direct `new JsonStorageAdapter(...)` or `new JsonDatabase(...)` inside the request contour blocks v3 evidence.

## Collect v3 evidence

Keep the selector disabled while collecting evidence:

```bash
php ops/runtime/collect-staging-db-primary-selector-evidence.php \
  --output=/absolute/private/path/staging-db-primary-selector-evidence-v3.json \
  --max-events=20
```

The collector uses:

- external private config;
- exact commit/database approval;
- a non-blocking private rehearsal lock;
- two independent DB connections for lease evidence;
- the existing two-pass staging rehearsal;
- atomic no-clobber private output;
- post-write v3 verification.

## Private selector config contract

The disabled contract is:

```php
'staging_db_primary_entrypoint_selector' => [
    'enabled' => false,
    'contract_version' => 'v1-staging-db-primary-entrypoint-selector',
    'allowed_entrypoints' => [],
],
```

The code can parse an explicit staging allowlist such as `['api']` or `['api', 'webhook']`, but **this PR must not be enabled for a live request series yet**.

## Current operational limit

A committed DB-primary mutation advances the compatibility-state revision and creates a pending outbox event. The evidence-bound readiness guard intentionally remains tied to the pre-request evidenced revision. Therefore, without the next finalizer/session layer:

- one mutating request would invalidate readiness for the next request;
- normalized module tables would remain behind until the projection worker completes;
- enabling webhook for a stream of updates could cause later updates to fail closed;
- disabling the selector would return to the unchanged JSON rollback source and would not preserve a staging-only DB mutation in JSON.

For that reason, selector activation remains blocked operationally even though the guarded resolution code exists. No real API or webhook request should be routed through this PR alone.

## Immediate routing rollback

When a later layer authorizes a controlled window, the one-step next-request rollback remains disabling the selector:

```php
'staging_db_primary_entrypoint_selector' => [
    'enabled' => false,
    'contract_version' => 'v1-staging-db-primary-entrypoint-selector',
    'allowed_entrypoints' => [],
],
```

No automatic mid-request fallback is allowed after DB routing starts. A request failure is fail-closed.

## Safety boundary

- this code does not enable the selector;
- no staging DB request has been run by this PR;
- no production config is changed;
- selector use outside staging fails closed;
- legacy JSON bridge hooks are suppressed only after guarded DB context installation;
- no Cron is added or changed;
- no deployment or production cutover is executed;
- JSON remains the immediate next-request rollback source.

## Focused verification

```bash
bash ops/checks/db-primary-staging-entrypoint-selector-local.sh
```

The focused suite includes the prior evidence, activation, resolver and rollback-only synthetic tests plus:

- strict selector config and allowlist;
- disabled production/staging short-circuit;
- enabled v3 path through approval, gate, resolver and context;
- immutable request-local storage;
- sticky selector failures;
- v3 compatibility through the existing activation guard;
- complete request-contour source fingerprints;
- direct JSON-constructor bypass rejection;
- legacy bridge runtime suppression and bootstrap contract;
- v3 collector runtime and CLI contracts;
- JSON fallback and exact DB adapter reuse.

## Next prerequisite

MVP-14.8.6l must add a staging request finalizer/session contract that:

1. processes the exact committed outbox revision before a DB-primary request is considered complete;
2. proves all nine normalized modules against the current DB-primary snapshot;
3. supports a bounded sequence of requests without reusing stale baseline evidence;
4. leaves a deterministic recovery state when post-commit projection fails;
5. keeps production and webhook routing disabled until API-only staging evidence proves the complete request lifecycle.
