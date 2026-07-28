# MGW Safety Checkpoint — 2026-07-28

Checkpoint name:

`MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD`

## Code snapshot

- repository: `manufact-test/mini-games-world`
- exact pre-rebuild production code: `f7e956000c027de640f196e8900b20a2140d0ca0`
- immutable checkpoint branch: `checkpoint/2026-07-28-v109-before-json-rollback-mysql-rebuild`
- PR #173 merge commit: `cdff4534c06679cb03de9863c29e36d937e18a76`
- Hostinger production deployment: confirmed `Completed` and `Current` on 2026-07-28
- production build reported by product owner before rebuild: `v109`
- manual speed/UI regression: failed

The immutable branch remains the exact code rollback point before MVP-14R begins. The merged checkpoint tooling commit is now deployed to production without changing runtime routing.

## Production-data snapshot status

The code snapshot and code-only deploy are complete. The full production rollback checkpoint is **not complete yet**.

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

Production runtime remains unchanged while MVP-14R.0 creates and verifies the complete snapshot.
