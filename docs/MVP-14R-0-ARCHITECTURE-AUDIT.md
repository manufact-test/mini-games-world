# MVP-14R.0 — Architecture audit

## Status

In progress. The code and architecture audit is complete enough to prepare the production checkpoint. No production change has been authorized or performed.

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

The exact current-entrypoint-to-relational-owner map is recorded in `docs/MVP-14R-0-BASELINE-AND-OWNERSHIP-MAP.md`.

## Finding D — the client still initializes many historical runtime layers

The v109 production entry imports and initializes modules from v93, v96, v99, v100, v101, v102, v103, v104, v105 and v109.

Even when a newer module tries to intercept an action first, retained historical modules remain in the application graph. Manual v109 testing proved that the intended ownership was not achieved consistently.

**Disposition:** the rebuild requires one explicit client owner per action group. Existing layers are frozen as rollback code; they are not the base for another v110 overlay.

## Exact JSON behavior baseline

The exact clean JSON behavior baseline is now identified:

- production checkout identity: `4295f42c84d28b02eae25fb9aa069ed186bde5ac`;
- latest functional commit: `c1f51e1188af12a18bd72a94cc289429f7d4960a`;
- comparison between those commits: two commits and zero changed files;
- storage factory: JSON-only, with `createJson()` returning `JsonStorageAdapter` directly;
- Telegram launch URL: `/app/?v=85`;
- client assets: `main.css?v=85`, `main.js?v=86`;
- app markup build: `v86-mvp13-runtime-controls`;
- accepted client feature line: `v85-mvp12-invite-rebuild`.

Historical roadmaps independently record `4295f42...` as the exact JSON-first production checkout with cutover not executed. Later JSON rollback checkpoint `56dd3340...` proves operational recovery but already includes part of the cutover package, so it is not the clean behavior source.

**Important boundary:** historical JSON data will not be restored over current users. The current DB-primary state must first be exported into a fresh verified JSON artifact. Historical code is the behavior baseline; current database state remains the data source.

## Finding E — the existing DB→JSON exporter is usable but not the whole checkpoint

The repository already contains a guarded production DB→JSON export. It verifies the current compatibility-state revision, the complete outbox chain and parity of all nine projected modules, and writes a checksummed BackupManager-compatible JSON artifact without SQL writes.

The exporter requires maintenance mode, financial read-only mode and a short-lived private authorization. Therefore it belongs to the controlled recovery stage, not to an unapproved background operation.

The exact-current rollback checkpoint additionally needs:

- an independent single-transaction SQL dump;
- deployed `public_html` archive;
- private runtime/config archive;
- existing `mgw_data` archive;
- SHA-256 manifest;
- isolated file/JSON restore verification.

The checkpoint creator and isolated verifier are now present on the audit branch. The creator rejects any output path nested inside an archived source tree, preventing recursive or self-changing archives.

## MVP-14R.0 production checkpoint boundary

Checkpoint identity:

`MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD`

The checkpoint operation is read-only with respect to the application runtime and database. It creates a new sibling directory outside `public_html`, `_private_mgw` and `mgw_data`.

It does not:

- change the active storage route;
- write SQL;
- replace JSON;
- change runtime flags;
- change webhook or Cron;
- deploy historical code;
- start MVP-14R.1.

## Remaining work in MVP-14R.0

1. obtain exact product-owner approval to merge the audit/scripts PR;
2. deploy that code-only commit so the reviewed checkpoint scripts exist on Hostinger;
3. run the one reviewed checkpoint command;
4. run the isolated verifier command;
5. record the returned checkpoint directory, commit, clean/dirty state and verification result;
6. separately prepare the guarded current DB→JSON export required by MVP-14R.1;
7. stop before maintenance mode or any runtime switch and obtain fresh approval.

## Acceptance criteria

MVP-14R.0 is complete only when:

- immutable code checkpoint exists;
- SQL, deployment, private and existing JSON artifacts exist under the exact checkpoint ID;
- all SHA-256 checks pass;
- deployment/private/JSON archives restore successfully into an isolated temporary directory;
- every restored JSON file decodes successfully;
- exact JSON code/runtime baseline is recorded;
- every current hot-path entrypoint has a target relational repository owner;
- no production runtime or database mutation has occurred;
- the next operation is the separately guarded current DB→JSON export for MVP-14R.1.
