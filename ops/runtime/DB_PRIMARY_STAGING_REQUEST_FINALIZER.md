# MVP-14.8.6l — inactive API-only staging request finalizer and bounded session

This stacked sub-MVP adds the missing lifecycle primitives required before the guarded selector from MVP-14.8.6k can be connected to a sequence of mutating API requests.

## Safety boundary

- the layer is **inactive**;
- it is not loaded by application bootstrap;
- it is not registered as an API success hook;
- selector behavior is unchanged by this PR;
- webhook is rejected by the session contract;
- production is forbidden;
- no private config is changed;
- no staging database operation is run;
- no Cron, deploy, merge or cutover occurs.

Deploying these classes alone performs nothing.

## Why this layer is required

A DB-primary mutation commits the compatibility-state row and creates a pending projection-outbox event. The request must not be reported as successfully finalized until:

1. every outbox revision through the current state revision is completed in order;
2. all nine normalized runtime modules match the exact current DB-primary snapshot;
3. state, event and queue remain unchanged during the read-only audit;
4. the request remains inside a short, bounded API-only session.

Without this lifecycle, one request could succeed while normalized tables were stale, and the next request would start from an unproven revision.

## Bounded request session

`RuntimePrimaryStagingRequestSessionConfig` requires an exact private contract:

```php
'staging_db_primary_request_session' => [
    'enabled' => true,
    'contract_version' => 'v1-api-only-bounded-request-session',
    'allowed_entrypoints' => ['api'],
    'baseline_revision' => 1,
    'max_revision_delta' => 3,
    'max_worker_ticks' => 3,
    'lease_seconds' => 60,
    'expires_at_utc' => '2030-01-01T00:00:00+00:00',
],
```

The example is illustrative only; this PR does not add it to any config.

Fail-closed limits:

- only `api` is accepted;
- webhook is rejected;
- baseline revision must be positive and match the evidenced baseline;
- revision delta is limited to 1–20;
- worker ticks are limited to 1–20 and must cover the revision delta;
- lease is limited to 30–300 seconds;
- expiry requires an explicit UTC offset;
- an active session may be at most 30 minutes from expiry;
- a current revision above the session maximum is blocked.

## Request finalizer

`RuntimePrimaryStagingRequestFinalizer` receives the already resolved DB-primary adapter, a leased worker and an all-module auditor.

For one request it:

1. reads the current DB-primary revision and SHA;
2. proves the request remains within the bounded API session;
3. processes the oldest non-completed outbox event repeatedly;
4. stops after the configured worker-tick bound;
5. requires the current revision event to be cleanly completed;
6. requires a contiguous completed event chain from revision 1 through the current revision;
7. reads and verifies the exact current DB-primary snapshot;
8. performs read-only parity audit for all nine modules;
9. rechecks state, current event and queue after the audit;
10. reports success only after every condition passes.

A worker result such as `projection_busy`, `projection_delayed`, `projection_failed` or premature `projection_noop` blocks request finalization. The event remains in its explicit recovery state for later inspection; no false success is reported.

## Dynamic readiness between requests

`RuntimePrimaryStagingRequestSessionReadiness` is separate from the immutable baseline activation evidence.

It permits a later revision only when:

- the JSON rollback source and its inventory still match the baseline;
- the baseline event remains completed with its original SHA;
- the current DB-primary revision stays within the session bound;
- every outbox event through the current revision is completed contiguously;
- all nine normalized modules match the current DB-primary snapshot;
- JSON, DB state, event and queue remain unchanged during the audit.

This avoids reusing the original baseline as though the database had never advanced, while retaining a fixed proof that the rollback JSON source itself did not change.

## Interfaces and adapters

The layer introduces narrow testable contracts:

- `RuntimePrimaryProjectionWorkerInterface`;
- `RuntimePrimaryProjectionAuditorInterface`.

Thin adapters wrap the existing leased worker and all-module projector. Their existing behavior is not rewritten.

## Focused verification

```bash
bash ops/checks/db-primary-staging-request-finalizer-local.sh
```

The suite runs the complete prior selector/evidence/synthetic stack and adds:

- disabled/default and strict API-only session config;
- exact baseline and bounded revision checks;
- expiry, lease and worker-tick limits;
- already-completed zero-tick finalization;
- one and multiple pending revisions processed in order;
- busy and failed recovery states;
- true revision-overflow blocking;
- current snapshot and all-nine-module audit;
- JSON rollback-source continuity;
- pending queue, baseline mismatch and audit-drift rejection;
- static proof that the core layer does not route API/webhook, mutate JSON or touch Cron/production.

## Next prerequisite

A later integration sub-MVP must connect these primitives to the selector with a new evidence contract:

1. API-only selector installation must require an enabled bounded session;
2. dynamic readiness must run before each routed API request;
3. the finalizer must run as the first guarded API success hook before the response is sent;
4. finalizer failure must produce a deterministic non-success response and leave explicit recovery evidence;
5. evidence must fingerprint the new session/finalizer/bootstrap contour;
6. webhook and production must remain disabled.
