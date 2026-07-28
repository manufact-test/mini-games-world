# MVP-14R — Clean relational MySQL rebuild

## Goal

Finish MVP-14 with one stable server runtime for Telegram, Google Play and App Store.

The final runtime must use normalized MySQL tables and domain repositories. JSON is retained only as a temporary recovery baseline, import/export format and backup source.

The current MySQL database is not deleted. The current production state is preserved before any switch.

## Confirmed architectural boundary

The existing DB-primary hot path stores the whole application state in one `state_json` singleton row. Every mutation locks that row, decodes the full state, runs a legacy whole-array callback, re-encodes and hashes the full state, updates the singleton row, then creates a projection event.

The new runtime must not use the singleton whole-state row or full-state projections in its request hot path.

## Mandatory process

- No new production client hotfix layers while the rebuild is being designed.
- Every sub-MVP has exact commit/PR, automated checks, rollback instructions and measurable acceptance criteria.
- User-visible sub-MVPs require a manual product-owner check before the next sub-MVP begins.
- Production export, import, deploy, rollback and cutover require separate explicit approval.
- MVP-15 stays blocked until MVP-14R.10 is accepted.

## Sub-MVPs

### MVP-14R.0 — Safety checkpoint and architecture audit

**Status:** in progress.

- freeze exact current code checkpoint;
- preserve the complete historical roadmap;
- identify the last accepted JSON behavior baseline;
- inventory entrypoints, storage selection, singleton locks, bridges, projections and client owners;
- prepare one-command production snapshot and isolated-restore runbook;
- do not change production.

**Done when:** code and data rollback checkpoint are verified and the audit identifies the exact components to retire or retain.

### MVP-14R.1 — Full production snapshot and temporary JSON recovery

- produce SQL dump, DB→JSON export, deployment/private/JSON archives and SHA-256 manifest;
- verify the artifacts through isolated restore;
- switch production to the verified JSON recovery runtime only after separate approval;
- preserve the original MySQL database untouched.

**Manual check:** app boot, profile, balance, history, invitation and one full match.

### MVP-14R.2 — JSON behavior and latency baseline

- capture request/response contracts and side effects for critical flows;
- measure cold/warm latency;
- freeze deterministic fixtures and user-visible acceptance scenarios.

**Manual check:** product owner confirms the baseline reflects the known working bot.

### MVP-14R.3 — Relational foundation and parity harness

- normalized schemas and repositories;
- domain transaction boundaries;
- deterministic migrations and fixtures;
- dual-run comparison harness that executes the same scenario against JSON baseline and relational MySQL;
- no production change.

**Manual check:** none; schema, migration and parity harness are automated.

### MVP-14R.4 — Accounts, auth, sessions and presence

- MGW accounts and provider identities;
- Telegram and future Google/Apple identity linking contract;
- sessions, device ownership and active/passive behavior;
- online presence by unique account.

**Manual check:** two accounts on two devices, online count and active/passive session behavior.

### MVP-14R.5 — Invites, notifications and Telegram sharing

- direct and link invitations;
- accept, decline and cancel;
- notification list, unread counter and toast gestures;
- Telegram prepared-message sharing and retry behavior.

**Manual check:** all invitation/share/notification flows on two accounts.

### MVP-14R.6 — Matchmaking, search and bot fallback

- relational queues scoped by room, game, size and bet;
- player match, cancel and repeated search;
- bounded bot fallback without a global application-state lock.

**Manual check:** two-player match, one-player bot fallback, cancel and repeat.

### MVP-14R.7 — Games, clocks, results and rematches

- relational game state and actions;
- clocks and turn ownership;
- result generation, settlement trigger and rematch lifecycle;
- all eight games handled through one proven runtime contract or individually verified repositories.

**Manual check:** mandatory full check of all eight games.

### MVP-14R.8 — Economy, history, shop, payments and weekly bonus

- ledger-first balance mutations;
- bets and settlement;
- balance/match history;
- inventory and store ownership;
- payment lifecycle;
- weekly Match-coin eligibility and grant.

**Manual check:** balances before/after games, history, store and weekly fixtures.

### MVP-14R.9 — Concurrency, load, failure and rollback rehearsal

- concurrent accounts and games;
- deadlock retry and idempotency;
- duplicate Telegram requests and repeated clicks;
- process interruption and recovery;
- backup and full isolated rollback rehearsal.

**Manual check:** controlled staging stress and restored-copy verification.

### MVP-14R.10 — Final migration and production cutover

- fresh JSON→relational import;
- exact parity and backup gate;
- guarded maintenance window and runtime switch;
- smoke before release;
- separate release approval;
- final production regression and rollback window.

**Manual check:** complete production regression before MVP-14 is closed.

## Current checkpoint

- current main/code deployed: `f7e956000c027de640f196e8900b20a2140d0ca0` / v109;
- immutable code checkpoint: `checkpoint/2026-07-28-v109-before-json-rollback-mysql-rebuild`;
- working branch: `agent/mvp14r0-baseline-audit`;
- production manual regression: failed;
- production data snapshot: pending;
- production changes during MVP-14R.0: prohibited;
- next action: complete the exact audit and prepare the snapshot command.
