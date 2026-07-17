# Realtime database foundation

This module is the inactive database-side foundation for MVP-14.5.

It stores:

- matches and players;
- immutable versioned match snapshots;
- server-only state;
- per-player hidden state;
- matchmaking queue rows;
- invites and invite events;
- notifications.

## Current authority

The running product still uses the JSON storage adapter. This module is not wired into gameplay, matchmaking, invitations or notifications yet. It must not be treated as a completed cutover.

## Hidden information

`loadMatchForPlayer()` returns only public match state plus the requested player's own private snapshot. It never returns `server_state_json` or another player's private snapshot. Trusted server workers may use `loadServerMatch()`.

This separation is mandatory for Battleship ship layouts, Domino hands and any later game with hidden information.

## Identity compatibility

Rows can reference either:

- `mgw:<MGW-ID>`;
- `legacy:<current Telegram/user ID>`;
- `bot:<stable bot ID>`.

Nullable MGW foreign keys allow legacy JSON records to be imported before every account has been mapped. The legacy ID remains stored for audit and reconciliation.

## Safety

- writes use database transactions;
- match snapshots are append-only by `(match_id, state_version)`;
- reusing a snapshot version with different state fails closed;
- queue has one row per player reference;
- invite events and notifications have idempotency keys;
- runtime cutover requires a later tested adapter/import step.

<!-- temporary Hostinger fast-forward bridge -->
