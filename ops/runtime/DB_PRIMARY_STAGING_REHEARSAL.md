# MVP-14.8.6e — explicit staging DB-primary rehearsal

This stacked sub-MVP adds a one-shot CLI for rehearsing the inactive DB-primary state, outbox, worker and all-module projector on local or staging only.

## Hard safety boundary

- the CLI rejects every environment except `local` and `staging`;
- the CLI is unavailable over HTTP;
- no Cron is created or modified;
- no API or webhook entrypoint is changed;
- the global application storage remains JSON;
- the JSON rollback source is opened read-only;
- no production cutover state is read or changed;
- mutating modes use a separate non-blocking rehearsal lock;
- `--status` remains lock-free and read-only;
- reports contain only counts, revisions, hashes and safe error messages.

Deploying the code performs nothing. An operator must explicitly execute one CLI mode.

## Modes

Read-only status:

```bash
php ops/runtime/staging-db-primary-rehearsal.php --status
```

Explicitly install and verify the state/outbox schemas:

```bash
php ops/runtime/staging-db-primary-rehearsal.php --install
```

Synchronize the current JSON snapshot into a DB-primary state revision and ensure its exact outbox event:

```bash
php ops/runtime/staging-db-primary-rehearsal.php --seed
```

Process only the oldest unfinished outbox revision:

```bash
php ops/runtime/staging-db-primary-rehearsal.php --run-once
```

Run the bounded end-to-end rehearsal:

```bash
php ops/runtime/staging-db-primary-rehearsal.php --rehearse
```

The full rehearsal may process older queued revisions before the exact current snapshot target. The default bound is 20 events. It can be changed only for `--rehearse`:

```bash
php ops/runtime/staging-db-primary-rehearsal.php --rehearse --max-events=50
```

The accepted range is 1–100.

## Full rehearsal sequence

1. Install and verify the singleton state schema.
2. Install and verify the projection outbox schema.
3. Read one canonical snapshot from JSON without mutating it.
4. Initialize state revision 1, preserve an unchanged revision, or create the next revision when JSON changed.
5. Verify the committed state SHA matches the current JSON SHA.
6. Verify an exact outbox event exists for the target revision and SHA.
7. Run the leased worker in strict oldest-revision order.
8. Continue until the exact target event is completed, a worker blocker occurs, or the bounded event limit is reached.
9. Read aggregate state/outbox status.
10. Return `rehearsal_completed` only when the target revision completed and final status is healthy.

`projection_busy`, `projection_delayed`, worker failure, event disappearance, fingerprint drift, queue-empty-before-target or event-limit exhaustion produce an incomplete or failed report. They never enable application routing.

## Reports

Reports intentionally omit snapshot payloads and user/payment identifiers. They may include:

- environment;
- state revision and SHA-256;
- schema fingerprints;
- aggregate section counts;
- aggregate outbox counts and revision ranges;
- worker actions and attempts;
- projected module names;
- parity completion status;
- safe error class/message;
- explicit `application_entrypoints_changed=false`, `cron_changed=false`, `production_changed=false`.

## Focused verification

```bash
bash ops/checks/db-primary-staging-rehearsal-local.sh
```

This command first runs the complete all-module projector focused suite, then lints and tests the rehearsal backend, operation and CLI safety contract.

GitHub Actions may remain unavailable while included minutes are exhausted; that does not authorize skipping a real PHP 8.3 and staging MySQL rehearsal before merge.

## Next prerequisite

Run the CLI on an isolated staging MySQL database and record:

- PHP/MySQL versions;
- schema fingerprints;
- initial and repeated seed results;
- ordered worker results;
- exact target event completion;
- all-module parity;
- second idempotent rehearsal;
- concurrent `--run-once` lock/lease behavior;
- proof that application API/webhook still read and write JSON.

Only after that evidence is reviewed may a separate sub-MVP add a DB-primary application coordinator behind disabled flags. Production remains blocked by PR #66 until the real entrypoints use that coordinator.
