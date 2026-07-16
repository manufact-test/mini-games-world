# MVP-14 roadmap amendment

Date: 2026-07-16

This amendment records the user-approved insertion of a managed migration deployment step before realtime product data moves from JSON to MySQL/MariaDB.

## Completed

- MVP-14.1 — JSON storage adapter boundary.
- MVP-14.2 — MySQL/MariaDB connection and versioned migrations.
- MVP-14.3 — MGW users, identities, devices and sessions.

## Current

### MVP-14.4 — Managed migrations after deployment

- one permanent staging Cron replaces temporary five-minute Cron jobs;
- status, dry-run, exact plan fingerprint, migration and post-check are one controlled operation;
- concurrent executions are locked;
- failures are logged privately and keep health degraded;
- production is disabled by default;
- production requires exact short-lived approval and successful primary/external backup evidence;
- no `/app`, `/site`, gameplay, economy, payment or live JSON changes.

## Renumbering of the remaining original roadmap

- original MVP-14.4 (matches, players, queue, invites, notifications in DB) → MVP-14.5;
- original MVP-14.5 (balances, ledger, reservations, idempotency) → MVP-14.6;
- original MVP-14.6 (legacy payment/shop archive) → MVP-14.7;
- original MVP-14.7 (staging JSON → DB import and reconciliation) → MVP-14.8;
- original MVP-14.8 (production cutover) → MVP-14.9;
- original MVP-14.9 (DB backup, restore, monitoring and load tests) → MVP-14.10;
- original MVP-14.10 (minimal protected web admin) → MVP-14.11.

GitHub `main` remains the source of truth if older handoff or roadmap files use the previous numbering.
