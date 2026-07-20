# MVP-14.8.6k — guarded staging API/webhook DB-primary selector

This sub-MVP makes the existing API and webhook request flow capable of using DB-primary storage only inside staging and only after the full evidence/approval/resolver chain succeeds.

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

## Evidence v3

Real request routing requires:

```text
v3-staging-db-primary-selector-evidence
```

V3 contains all v2 database, JSON, schema, parity and concurrency evidence plus SHA-256 fingerprints for the complete request storage contour:

- API and webhook entrypoints;
- `WebhookHandler`;
- every current webhook guard that can participate before the handler;
- application bootstrap;
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

## Private selector config

Only after the real staging rehearsal and rollback-only synthetic suite pass may the external staging config enable an entrypoint:

```php
'staging_db_primary_entrypoint_selector' => [
    'enabled' => true,
    'contract_version' => 'v1-staging-db-primary-entrypoint-selector',
    'allowed_entrypoints' => ['api'],
],
```

API should be rehearsed first. Webhook is added only in a later explicit window:

```php
'allowed_entrypoints' => ['api', 'webhook'],
```

The activation approval must point to the exact v3 manifest fingerprint. The resolver latch and projection outbox must also remain explicitly enabled.

## Immediate routing rollback

Disable the selector in private staging config:

```php
'staging_db_primary_entrypoint_selector' => [
    'enabled' => false,
    'contract_version' => 'v1-staging-db-primary-entrypoint-selector',
    'allowed_entrypoints' => [],
],
```

The next request returns the ordinary JSON adapter. After a rehearsal window, the activation approval and resolver latch should also be disabled.

No automatic mid-request fallback is allowed after DB routing starts. A request failure is fail-closed; disable the selector before the next request.

## Safety boundary

- this code does not enable the selector;
- no staging DB request has been run by this PR;
- no production config is changed;
- selector use outside staging fails closed;
- no Cron is added or changed;
- no deployment or production cutover is executed;
- JSON remains available as the immediate next-request rollback source.

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
- v3 collector runtime and CLI contracts;
- JSON fallback and exact DB adapter reuse.

## Next prerequisite

A real isolated staging window must collect fresh evidence v3, run API-only smoke requests, disable the selector, and audit JSON/DB/outbox parity. Webhook routing and production remain blocked until those results are reviewed.
