# MVP-14R.0 — Architecture audit

## Status

In progress. This document is read-only analysis. It does not authorize or perform any production change.

## Exact current code point

- main: `f7e956000c027de640f196e8900b20a2140d0ca0`
- safety branch: `checkpoint/2026-07-28-v109-before-json-rollback-mysql-rebuild`
- audit branch: `agent/mvp14r0-baseline-audit`

## Finding A — the current primary state is still one JSON document

`DatabasePrimaryStateStorageAdapter` stores the complete application state in the singleton table row `state_json`.

Mutation flow:

1. begin DB transaction;
2. select the singleton row with `FOR UPDATE` on MySQL;
3. decode and verify the complete JSON state;
4. run the legacy callback against the whole array;
5. canonicalize and encode the complete state;
6. hash the complete state;
7. update the singleton row using optimistic revision;
8. enqueue a full-state projection event.

This means unrelated users, invitations, games and balance mutations contend on the same row and repeat whole-state serialization work.

**Disposition:** the singleton adapter may remain available only for rollback/evidence during migration. It must not be the target production hot path.

## Finding B — requesting JSON can still install DB-primary storage

The current `StorageFactory::createJson()` first tries to install a guarded production/staging entrypoint context. When installed, it returns that context's storage rather than `JsonStorageAdapter`.

This made the old application compatible with cutover without rewriting all call sites, but it also hides the real runtime behind legacy JSON-shaped code.

**Disposition:** the new relational runtime needs explicit domain repositories and explicit wiring. A method named `createJson()` must not silently become the final MySQL runtime.

## Finding C — DB-primary protection covers broad application entrypoints

The production entrypoint registry currently includes:

- API;
- webhook;
- invites;
- notifications;
- invite opponents;
- game clock;
- live game endpoint;
- search-speed endpoint;
- shop history;
- weekly Match Cron.

The rebuild therefore cannot be treated as one isolated API rewrite. Each entrypoint needs a direct repository contract and an explicit cutover state.

## Finding D — the client still initializes many historical runtime layers

The v109 production entry imports and initializes modules from v93, v96, v99, v100, v101, v102, v103, v104, v105 and v109.

Even when a newer module tries to intercept an action first, retained historical modules remain in the application graph. Manual v109 testing proved that the intended ownership was not achieved consistently.

**Disposition:** the rebuild requires one explicit client owner per action group. Existing layers are frozen as rollback code; they are not the base for another v110 overlay.

## JSON baseline candidate

Historical production evidence identifies commit `4295f42c84d28b02eae25fb9aa069ed186bde5ac` as a JSON-first production point before the DB-primary cutover package was active.

This is currently only a **candidate code baseline**. MVP-14R.0 still has to prove:

- which exact later commit was the last user-accepted JSON runtime;
- whether later code-only commits changed product behavior while JSON remained active;
- which production data snapshot corresponds to the accepted behavior;
- which UI build and Telegram menu URL were actually used.

No rollback will target a guessed baseline.

## Remaining work in MVP-14R.0

1. identify the exact accepted JSON code/runtime baseline;
2. inventory all storage creation call sites and protected entrypoints;
3. map each whole-state callback to its future domain repository;
4. inventory client action owners and polling owners;
5. produce a production snapshot script using existing guarded export/rollback components where safe;
6. add independent SQL dump and deployment/private/JSON archives;
7. produce SHA-256 manifest and isolated restore verifier;
8. run CI on this documentation/audit branch;
9. stop for product-owner approval before any Hostinger snapshot command.

## Acceptance criteria

MVP-14R.0 is complete only when:

- code checkpoint exists;
- full production data/file checkpoint is restored successfully in isolation;
- exact JSON baseline is identified by commit and runtime configuration;
- every current hot-path entrypoint has a target relational repository owner;
- no production change has occurred;
- the next operation is one reviewed and copyable snapshot/recovery command.
