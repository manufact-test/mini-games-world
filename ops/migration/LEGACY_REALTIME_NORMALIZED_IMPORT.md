# Controlled normalized realtime import

MVP-14.7.4 imports the already verified realtime shadow into the inactive normalized database tables for matches, players, snapshots, queue, invites and notifications.

JSON remains authoritative. This step proves exact staging parity before any production storage switch.

## Commands

```bash
php ops/migration/legacy-realtime-normalized-import.php --status
php ops/migration/legacy-realtime-normalized-import.php --dry-run
php ops/migration/legacy-realtime-normalized-import.php --run
```

`--status` and `--dry-run` are read-only.

## Prerequisites

- all managed database migrations are applied;
- exact realtime shadow matches current JSON with zero insert/update/repair/delete drift;
- provider-neutral accounts are imported;
- account ownership and real provider identities are linked;
- normalized target tables contain no unmanaged rows;
- Match/Gold balances and immutable ledger are already verified.

## Import behavior

- every human legacy ID resolves through `mgw_account_ownership`;
- stable `legacy:<id>` player/account references are preserved;
- bots remain internal `bot:<id>` players and never receive an MGW-ID;
- the complete legacy game payload is stored only in `server_state_json`;
- `public_state_json` remains `NULL`, so hidden Battleship or other private state cannot leak through the inactive database reader;
- one normalized match row, player set and server snapshot are created per source game;
- queue, invite and notification rows are created with MGW-ID links where applicable;
- existing exact rows are reused; conflicting or unmanaged target rows fail closed;
- one source fingerprint and completion record are stored in `mgw_meta`.

## Verification sequence

1. Dry-run: `ready=true`, exact source counts, expected planned creates, zero conflicts and unmanaged rows.
2. First run: expected rows are created and final verification is `ok=true`.
3. Repeat run: creates nothing and reports every source row unchanged.
4. Run `ops/migration/staging-json-db-reconciliation.php --status` twice.
5. Both reconciliation reports must have the same fingerprint, `count_parity_complete=true`, no blocking reasons and no migration gaps.
6. Confirm Match/Gold balances and immutable ledger counts/hashes did not change.
7. Delete only the temporary normalized-realtime-import Cron.

## Safety

- no JSON write occurs;
- no runtime read or storage adapter switch occurs;
- no balance, reservation, ledger, settlement, payment or shop mutation occurs;
- `/app`, `/site`, games and production configuration are unchanged;
- hidden game state is server-only;
- the operation is CLI-only and protected by a private lock;
- production requires private approval plus `--allow-production`;
- this command must not be configured as a permanent Cron.
