# MGW Safety Checkpoint — 2026-07-28

Checkpoint name:

`MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD`

## Code snapshot

- repository: `manufact-test/mini-games-world`
- exact commit: `f7e956000c027de640f196e8900b20a2140d0ca0`
- immutable checkpoint branch: `checkpoint/2026-07-28-v109-before-json-rollback-mysql-rebuild`
- production build reported by product owner: `v109`
- PR #172: merged
- manual speed/UI regression: failed

This branch is the exact code rollback point before MVP-14R begins.

## Production-data snapshot status

The code snapshot is complete. The full production rollback checkpoint is **not complete yet**.

Before any runtime switch, the following must be created outside `public_html`, checksummed and restored in isolation:

1. consistent SQL dump of the current MySQL database;
2. DB-primary to JSON export of current application state;
3. archive of the current `public_html` deployment;
4. archive of private config, cutover state and operational receipts without publishing secrets;
5. archive of the current JSON rollback source;
6. SHA-256 manifest for every artifact;
7. isolated restore verification report.

No production mutation, rollback or JSON switch is authorized by this document.

## Current production finding

Build v109 was deployed but the product owner reported that direct invites, repeated Telegram sharing, bot fallback timing, online presence, notification opening and toast gestures still fail the intended behavior.

Production remains unchanged while MVP-14R.0 prepares the complete snapshot and architecture audit.
