# MVP-14.10d — controlled live JSON rollback

This layer implements the final filesystem and routing order required to return an already active production DB-primary runtime to verified JSON data. It is code and CI only; it is not deployed or authorized for production use by this branch.

## Preconditions

The operation requires all of the following:

- exact production environment;
- global rollback driver remains `json`;
- maintenance mode and financial read-only are both exact boolean `true`;
- DB-primary routing is still active for all nine approved modules;
- cutover state is exactly `completed` and confirms the DB route is published;
- a fresh MVP-14.10c DB-to-JSON export verifies completely;
- export request ID, state revision/SHA, snapshot SHA, database identity, activation fingerprints and export path match a separate short-lived live-rollback authorization;
- authorization uses the exact confirmation phrase `ROLL BACK PRODUCTION TO VERIFIED JSON`;
- authorization is bound to the current `runtime.php`, cutover JSON and live data directory fingerprints;
- live JSON and all private inputs use canonical paths and private permissions.

## Lock order

The coordinator acquires and retains:

1. a private global live-rollback operation lock;
2. the current live JSON `app.lock` exclusively;
3. the restored candidate JSON `app.lock` exclusively;
4. the DB-primary singleton state row with `SELECT ... FOR UPDATE`.

While the DB row is locked, it rechecks the exact state revision/SHA, the complete outbox chain and read-only parity across all nine normalized modules.

## Atomic transition order

1. Restore the verified export into an isolated sibling directory.
2. Add a private `.cutover-write-block` to the candidate.
3. Rename the current live JSON directory to a retained request-bound backup directory.
4. Rename the sealed candidate into the exact live JSON path.
5. Record `live_json_installed_db_active` while requests still route to DB.
6. Atomically rewrite private `runtime.php` with DB routing disabled, all nine modules false, maintenance on and financial read-only on.
7. Re-resolve `RuntimeStorageRouter` and prove the route is JSON-only.
8. Record cutover and recovery state as `json_route_sealed`.
9. Remove the live JSON write block.
10. Atomically publish the released runtime overlay with DB routing still disabled and maintenance/read-only off.
11. Record cutover state `rolled_back` and recovery state `completed`.

The previous live JSON directory is retained and is never deleted by this service.

## Failure behavior

- Before DB routing is disabled, the filesystem swap is reverted and the authorized runtime/cutover backups are restored.
- After DB routing is disabled, the new JSON remains sealed, maintenance and financial read-only remain enabled, and recovery state becomes `sealed_resume_required`.
- A retry for the same exact request may resume the sealed transition and release JSON only after re-verification.
- A completed request is idempotent and only verifies the live state.

## Explicit non-effects

This layer does not:

- merge or deploy to `main`;
- contact production during CI;
- execute SQL writes;
- delete the previous JSON data directory;
- modify Telegram webhook registration;
- modify Cron;
- alter release files;
- expose private paths, DSNs or credentials in CLI output.

Production rollback execution still requires fresh explicit approval, a deployed exact release candidate, current backups and a controlled maintenance window.
