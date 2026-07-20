# MVP-14.8.6f — strict staging evidence manifest

This stacked sub-MVP defines the evidence required before any application entrypoint can be considered for DB-primary routing. It adds a read-only manifest verifier and does not run rehearsals, workers, schemas or application traffic.

## Safety boundary

- verifier is CLI-only;
- verifier does not load application bootstrap;
- verifier does not connect to MySQL;
- verifier does not open JSON storage;
- verifier does not run workers;
- verifier does not create or change Cron;
- manifest must be an external private file outside the deployed project;
- manifest symlinks, world-writable files and files larger than 512 KiB are rejected;
- reports contain only blockers, fingerprints and non-sensitive metadata.

## Verification command

```bash
MGW_REHEARSAL_COMMIT_SHA=<exact-40-character-commit> \
php ops/runtime/verify-staging-db-primary-evidence.php \
  --verify=/absolute/private/path/staging-db-primary-evidence.json
```

When repository metadata is available, `MGW_REHEARSAL_COMMIT_SHA` is optional. The resolver reads detached `HEAD`, a loose branch ref, packed refs or a worktree `gitdir` pointer without shelling out to `git`.

## Required manifest contract

Top-level fields are exact; missing or unexpected fields fail verification:

- `manifest_version` = `v1-staging-db-primary-evidence`;
- `environment` = `staging`;
- full 40-character `repository_commit`;
- offset-aware `generated_at_utc`;
- PHP evidence;
- MySQL/MariaDB evidence;
- state/outbox schema evidence;
- unchanged JSON snapshot evidence;
- first rehearsal evidence;
- repeated idempotent rehearsal evidence;
- CLI lock and worker lease concurrency evidence;
- current application entrypoint evidence.

The verifier rejects payload fields such as `state_json`, `snapshot`, `users`, `telegram_id`, `provider_subject`, `mgw_id`, `account_ref`, payment identifiers, tokens, secrets or passwords.

## Runtime requirements

### PHP

- exact PHP 8.3.x;
- `version_id` from 80300 through 80399;
- SAPI `cli`.

### Database

- driver `mysql`;
- non-empty MySQL/MariaDB server version;
- state table engine `innodb`;
- outbox table engine `innodb`.

### Schemas

- exact table `mgw_runtime_primary_state` and a SHA-256 schema fingerprint;
- exact table `mgw_runtime_primary_projection_outbox` and a SHA-256 schema fingerprint.

## Unchanged JSON evidence

The manifest records four hashes:

- JSON SHA before the first rehearsal;
- JSON SHA after the first rehearsal;
- JSON SHA after the repeated rehearsal;
- a non-sensitive inventory fingerprint.

All three JSON SHAs must be identical. The rehearsal target SHA for both runs must match that unchanged source SHA.

## First rehearsal

The first rehearsal must:

- return `rehearsal_completed`;
- initialize or create a fresh state revision;
- complete the exact target event;
- report healthy final status and parity;
- process between 1 and 100 worker events;
- report all nine projected modules;
- keep entrypoints, Cron and production unchanged;
- expose no sensitive identifiers.

## Repeated rehearsal

The second rehearsal must:

- return `rehearsal_completed`;
- report `snapshot_unchanged`;
- target the same state revision and SHA;
- require zero worker ticks;
- report the same nine modules;
- preserve every safety flag.

This proves idempotency. A repeated rehearsal that creates a revision or worker event is rejected.

## Concurrency evidence

### CLI lock

A second mutating rehearsal process must:

- exit with code `2`;
- report `rehearsal_lock_blocked`;
- leave the first process unaffected.

### Worker lease

For the same oldest revision:

- the first worker must hold a valid claim;
- the second worker must return `projection_busy`;
- both results must reference the same revision;
- lease duration must remain between 30 and 900 seconds.

## Entrypoint evidence

`RuntimePrimaryEntrypointEvidence` reads the current repository sources directly and records only SHA-256 plus boolean markers for:

- `bot/api.php`;
- `bot/handlers/WebhookHandler.php`.

For this prerequisite stage both must still contain direct JSON factory routing and must not contain `ProductionPrimaryRuntimeCoordinator`. The manifest copy must exactly match the current checkout. A stale or edited entrypoint proof is rejected.

## Commit binding

`RuntimePrimaryStagingEvidenceGate` resolves the current checkout commit and compares it with the manifest. Evidence from another commit cannot authorize the current code.

## Focused verification

```bash
bash ops/checks/db-primary-staging-evidence-local.sh
```

This runs every prior DB-primary focused suite, then verifies:

- current JSON-first entrypoint inspection;
- shell-free commit resolution;
- strict valid manifest;
- environment/PHP/database/schema failures;
- changed JSON source;
- missing module or repeated worker write;
- weak lock/lease evidence;
- tampered entrypoint SHA;
- sensitive and unexpected fields;
- current-checkout commit binding;
- verifier CLI file safety contract.

## Next prerequisite

Add an automated staging evidence collector that performs two bounded rehearsals, computes before/after JSON and inventory hashes, records schema/database versions, and runs controlled lock/lease probes. It must write the manifest only to an explicit private path outside deployment, then invoke this verifier. No application entrypoint may be changed until a real PHP 8.3 + staging MySQL manifest passes with zero blockers.
