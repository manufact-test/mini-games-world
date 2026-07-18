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
- deletes queue rows removed from JSON;
- deletes a match missing from JSON only when the DB match is already terminal;
- blocks a missing active or otherwise non-terminal JSON match;
- supports a CLI-only read-only parity audit in the next substep.

## Boundary

This e1 deployment is intentionally inactive:

- `bot/api.php` is not connected to the repository yet;
- the staging `realtime` runtime flag must remain disabled;
- the application continues to read and write realtime state through JSON;
- existing `accounts`, `notifications` and `invites` DB routes remain unchanged;
- production configuration, data and Cron jobs are not changed.

## First staging check

1. Deploy only the verified e1 files.
2. Do not edit staging `_private_mgw/runtime.php`.
3. Confirm `/bot/health.php` remains `ok` on the existing build and lists only `accounts`, `notifications` and `invites` as enabled DB modules.
4. Confirm global and rollback storage remain `json` and production routing remains false.

## Next substep

A separate e2 change will connect only the realtime API mutation boundary, mirror the committed JSON snapshot, add a CLI-only audit, and keep the `realtime` flag disabled until deployment and health checks pass.

## Rollback

Revert the e1 files. No data rollback is needed because this foundation is not called by the application and no private runtime flag is changed.
