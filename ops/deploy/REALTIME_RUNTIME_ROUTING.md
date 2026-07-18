# MVP-14.8.2e — staged realtime DB routing

JSON remains the active product and rollback source while matches, players, snapshots and matchmaking queue state are mirrored into MySQL/MariaDB.

## e1 inactive foundation

`RuntimeRealtimeRepository` prepares the staging-only bridge for the current JSON `games` and `queue` sections. It:

- resolves every human legacy player through active account ownership;
- keeps bot identities internal and does not create MGW accounts for bots;
- stores the complete legacy game payload only in server-side state;
- keeps public DB state null until the dedicated public-state contract exists;
- creates immutable snapshot versions and advances the version only when JSON changes;
- checks match IDs, game identity, player seats and player references before updating;
- blocks DB state that is newer than the JSON rollback source;
- mirrors queue removal from JSON because queue rows are temporary;
- preserves DB-only terminal matches as retained history instead of deleting them;
- blocks a missing active or otherwise non-terminal JSON match;
- separates source-managed match parity from retained terminal history counts.

The inactive e1 package was deployed to staging and verified with health `ok`, global JSON storage, 7/7 migrations and `realtime` still disabled.

## e2 API bridge

`RealtimeRuntimeBridge` attaches only to successful responses from `bot/api.php` and only when staging routes `realtime` to DB.

1. The existing API transaction commits its complete JSON state first.
2. Before the successful response is emitted, the bridge reloads the committed JSON snapshot.
3. The repository mirrors current games and queue into normalized DB tables.
4. Match changes append immutable snapshot versions.
5. Any ownership, identity, DB-ahead or parity error fails the API response closed.
6. JSON remains the authoritative rollback source and is never deleted by the bridge.

Other endpoints, production, and requests made while the `realtime` flag is disabled do not attach the bridge.

## Safe staging activation

1. Deploy build `v94-mvp14-db-realtime-routing` with `realtime` still disabled.
2. Confirm `/bot/health.php` is `ok`, migrations are 7/7, global storage is `json`, production routing is false, and enabled DB modules remain `accounts`, `invites`, `notifications`.
3. Add only `realtime => true` to staging `_private_mgw/runtime.php`.
4. Confirm health lists `accounts`, `invites`, `notifications`, `realtime` and remains `ok`.
5. In the test Mini App, complete one ordinary Match-room tic-tac-toe game against the bot. This exercises queue creation/removal, match creation, moves, snapshots and terminal state. Full eight-game validation belongs to MVP-14.8.3.
6. Create one temporary Cron job with schedule `*/5 * * * *` and command:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/realtime-runtime-audit.php
```

7. Capture one complete output. Require `ok=true`, `read_only=true`, `parity=true`, matching source/DB fingerprints, equal source-managed game and queue counts, `blockers=[]`, JSON storage, current schema and production routing false. Retained terminal DB-only history may be reported separately and is not a blocker.
8. Delete only this temporary realtime audit Cron immediately after the output is captured.

Do not hard-code an expected game or queue count: cleanup and retained terminal history make those counts legitimately variable.

## Rollback

Set only `realtime => false` or remove that module from staging `_private_mgw/runtime.php`. Keep `accounts`, `invites` and `notifications` enabled. Do not delete JSON data, normalized DB rows, snapshots, migrations or permanent Cron jobs.
