# MVP-14.8.6i — guarded staging DB-primary storage resolver

This stacked sub-MVP can resolve a DB-primary `StorageAdapterInterface` only after the complete evidence-bound readiness guard passes. It still does not route any application request.

## Safety boundary

- staging only;
- independent resolver latch defaults to disabled;
- activation approval and evidence v2 remain mandatory;
- the same already-audited MySQL connection is used for the resolved adapter;
- projection outbox is always attached;
- resolver opens no second DB connection;
- resolver performs no schema installation, seed, transaction or write;
- API and webhook remain JSON-first;
- no application entrypoint imports or calls the resolver;
- no runtime routing, Cron or production changes;
- deploying the code performs nothing.

## Private resolver latch

Only after the read-only activation inspector returns `staging_activation_ready`:

```php
'staging_db_primary_storage_resolver' => [
    'enabled' => true,
],
```

The resolver still requires the separate short-lived `staging_db_primary_activation` approval. The latch alone grants nothing.

Disable both the resolver latch and activation approval after inspection or any failed attempt.

## Resolution sequence

1. Require environment exactly `staging`.
2. Require the independent resolver latch.
3. Run the complete evidence-bound activation guard.
4. Require `activation_allowed`, read-only all-module audit and post-audit drift checks.
5. Construct `DatabasePrimaryStateStorageAdapter` on the same audited DB connection.
6. Attach `RuntimePrimaryProjectionOutboxWriter` unconditionally.
7. Read adapter status.
8. Compare status revision and SHA to the readiness report again.
9. Return a `RuntimePrimaryStagingStorageResolution` object.

The returned object exposes the actual validated storage only to a future dedicated staging test entrypoint. Its safe report explicitly states:

- storage driver `database`;
- rollback driver `json`;
- projection outbox enabled;
- read-only readiness audit passed;
- drift check passed;
- `application_entrypoint_routed: false`;
- no application/Cron/production changes.

## Read-only resolution inspector

```bash
php ops/runtime/inspect-staging-db-primary-storage-resolution.php
```

This command resolves and reports the adapter but never invokes `transaction()` or any application service. A blocked result leaves everything unchanged.

## Focused verification

```bash
bash ops/checks/db-primary-staging-storage-resolver-local.sh
```

The suite runs every previous activation/evidence test and adds:

- strict resolver latch parsing;
- disabled-by-default behavior;
- exact validated storage instance preservation;
- revision/SHA mismatch rejection;
- mandatory projection outbox;
- no second connection or mutation contract;
- no application routing claim;
- read-only CLI contract and sensitive-output checks.

## Next prerequisite

A later sub-MVP may create a dedicated, non-public staging test entrypoint that uses this resolver for a bounded synthetic request suite. It must not modify the real `bot/api.php` or `WebhookHandler.php` until behavior, rollback and concurrency parity are proven.
