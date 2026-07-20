# MVP-14.8.6d — audited all-module runtime projector

This stacked sub-MVP adds the concrete projection layer consumed by the leased worker from MVP-14.8.6c. It remains inactive and staging/local-only.

## Safety boundary

- no worker entrypoint is created here;
- no Cron is created or changed;
- no application API or webhook entrypoint is switched;
- no production config, Hostinger files or database is changed;
- the repository factory rejects `environment=production`;
- loading `RuntimePrimaryProjectionBootstrap.php` only loads classes;
- deploying this code does not install schemas or process outbox events.

## Projection contract

`RuntimePrimaryAllModuleProjector` requires exactly one projector for each module, in dependency order:

1. accounts;
2. realtime;
3. economy;
4. notifications;
5. invites;
6. history;
7. shop;
8. payments;
9. weekly bonus.

For one exact compatibility-state revision it performs:

1. a mutation pass through all nine modules;
2. a separate read-only audit pass through all nine modules;
3. exact revision and state-SHA verification for every report;
4. source/database fingerprint comparison for every module;
5. blocker rejection;
6. one deterministic all-module audit fingerprint.

The worker may mark an outbox event completed only when this projector returns `ok=true`, `parity_ok=true`, the exact claimed revision and fingerprint, and all nine required modules.

## Accounts projector

`RuntimePrimaryAccountsModuleProjector` is the first concrete module implementation. It:

- creates an MGW user only when no identity or ownership mapping already exists;
- creates both the real provider identity and the stable `legacy_import` identity;
- creates or verifies `mgw_account_ownership`;
- updates mutable profile fields while preserving the original creation timestamp;
- rejects provider/legacy/ownership mappings that point to different MGW IDs;
- rejects mappings to a missing MGW user;
- requires one-to-one ownership;
- rejects ownership rows outside the exact compatibility snapshot;
- computes independent canonical source and database fingerprints.

The accounts regression uses a strict fake database that compares SQL placeholders with supplied parameters, so extra or missing PDO parameters fail immediately.

## Existing repository adapters

`RuntimePrimaryRepositoryProjectorFactory` adapts the existing normalized repositories:

- realtime: `RuntimeRealtimeRepository`;
- economy: `RuntimeEconomyRepository`;
- notifications: full per-user synchronization and aggregate audit;
- invites: `RuntimeInviteRepository`;
- history: verified realtime/economy shadows plus `RuntimeHistoryRepository` audit;
- shop: `RuntimeShopRepository` against the exact event snapshot;
- payments: `RuntimePaymentRepository` against the exact event snapshot;
- weekly bonus: `RuntimeWeeklyBonusRepository` against the exact event snapshot.

The factory forces all nine staged DB routes while keeping the global storage driver JSON. It remains local/staging-only until the protected production activation contract is merged and separately reviewed.

## Focused verification

```bash
bash ops/checks/db-primary-all-module-projector-local.sh
```

The focused suite covers:

- exact nine-module composition and dependency order;
- project pass followed by independent audit pass;
- missing, duplicate and unsupported modules;
- wrong revision or state fingerprint;
- false parity, non-read-only audit, mismatched fingerprints and blockers;
- accounts creation, idempotency and profile update;
- exact SQL placeholder/parameter matching;
- identity collision rollback;
- extra ownership rejection;
- staging/local-only factory guard.

## Next prerequisite

Add an explicit staging-only CLI operation that installs/verifies the state and outbox schemas, seeds one exact snapshot, invokes the leased worker once, and prints a non-sensitive parity report. It must not create Cron or enable any application entrypoint. A real staging MySQL rehearsal and concurrency test are required before the DB-primary coordinator can be connected to API or webhook traffic.
