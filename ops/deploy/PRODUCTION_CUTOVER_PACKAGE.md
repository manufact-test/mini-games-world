# MVP-14.10e — exact production cutover and recovery package

This package connects the accepted MVP-14 production foundations into one fail-closed operational lifecycle. The code is not deployed and does not authorize a production cutover by itself.

## Safety boundary

Production remains globally `json` until an exact controlled cutover is explicitly approved. Every operation is bound to:

- build `v103-mvp14-production-cutover`;
- the exact deployed Git commit;
- a SHA-256 package fingerprint covering the critical API, webhook, storage, cutover and rollback files;
- the exact production database identity;
- the exact frozen JSON source fingerprint;
- the exact cutover plan fingerprint;
- private files outside the deployment with restrictive permissions;
- a short-lived operation-specific approval.

The package does not change webhook registration or Cron.

## Commands

The control command is:

```bash
php ops/deploy/production-cutover.php --package
php ops/deploy/production-cutover.php --preflight
php ops/deploy/production-cutover.php --run
php ops/deploy/production-cutover.php --status
php ops/deploy/production-cutover.php --release
php ops/deploy/production-cutover.php --rollback
php ops/deploy/production-cutover.php --rearm
```

The protected read-only release smoke is:

```bash
php ops/deploy/production-cutover-smoke.php
```

These commands are versioned source artifacts only. They must not be copied to production or executed there without a fresh explicit production approval.

## Phase 1 — package and preflight

`--package` is offline and read-only. It verifies the current Git commit and every critical file fingerprint.

`--preflight` is read-only. It requires:

- exact production environment and JSON rollback driver;
- exact package integrity;
- current DB-primary API/webhook contract;
- enabled and reachable database;
- current schema with zero pending migrations;
- matching fresh primary and external JSON backups;
- zero active games, matchmaking queue, open invitations, playing/searching users and in-flight financial work;
- available verified rollback export and live rollback commands.

The preflight returns a cutover plan fingerprint but never permits or performs the switch.

## Phase 2 — run to protected awaiting_release

`--run` additionally requires a separate private approval with:

- exact build, release commit, package fingerprint and plan fingerprint;
- a lowercase 32-character request ID;
- confirmation phrase `CUT OVER PRODUCTION TO DB PRIMARY`;
- expiry no more than 30 minutes in the future.

The operation then:

1. creates an exact private runtime backup;
2. enables maintenance and financial read-only mode;
3. disables matchmaking, invitations, payments and shop writes;
4. drains queue/in-flight work;
5. seals JSON writes;
6. freezes and rechecks the JSON source fingerprint;
7. creates and verifies primary plus external backups;
8. imports legacy/normalized data;
9. creates the DB-primary compatibility state at exact revision 1;
10. creates the exact projection outbox event;
11. runs one all-module projection worker tick when required;
12. requires a completed-only outbox and all-nine-module parity;
13. runs the full DB regression;
14. publishes the DB route while maintenance and JSON seal remain active;
15. repeats the regression;
16. writes `awaiting_release` with commit, package, DB, state, outbox and module fingerprints.

The public runtime remains protected. Maintenance is not released by `--run`.

## Phase 3 — read-only smoke and separate release approval

`production-cutover-smoke.php` holds the cutover and migration locks and performs only read operations against production data. It verifies:

- exact package and awaiting-release activation contract;
- database identity and schema;
- exact state revision/SHA before and after the smoke;
- a contiguous completed outbox chain using the expected projection version;
- all-nine-module read-only parity;
- JSON rollback snapshot equality with DB-primary state;
- maintenance, financial read-only and JSON seal still active;
- no DB write, config, webhook or Cron change.

It atomically writes a private `production-cutover-release-receipt.json` with mode `0600`. The receipt expires within ten minutes.

`--release` requires a second, separate private approval bound to the receipt fingerprint, plan fingerprint and source fingerprint, with confirmation phrase `RELEASE PRODUCTION DB PRIMARY`.

## Phase 4 — release ordering

Release ordering is fixed:

1. verify exact package, state, runtime contract, DB identity and smoke receipt;
2. verify the separate release approval;
3. recheck JSON source and full DB regression;
4. validate final runtime in memory;
5. publish final runtime while `awaiting_release` and JSON seal still block public entrypoints;
6. verify the published DB route;
7. remove the JSON write seal;
8. write the terminal `completed` state last.

A failure before any release mutation leaves the system sealed in `awaiting_release`. A failure after publication begins requires the verified fresh rollback workflow.

## Recovery boundary

Before the DB route is published, an exact runtime abort may restore the preserved runtime and unseal the unchanged JSON source.

After the DB route is published, stale JSON rollback is forbidden because DB-primary may have accepted writes. Recovery must use both accepted packages in order:

```bash
php ops/runtime/run-production-primary-rollback-export.php ...
php ops/runtime/run-production-primary-live-rollback.php ...
```

The first creates a fresh verified DB-to-JSON export. The second atomically replaces live JSON, disables all DB routing while sealed, then releases JSON last. Both require their own short-lived authorizations and exact fingerprints.

## What this sub-MVP does not authorize

- merging the release candidate into `main`;
- deploying to Hostinger production;
- editing private production configuration;
- running preflight, cutover, smoke, release or rollback against production;
- changing webhook registration or Cron;
- marking the manual eight-game regression as passed.
