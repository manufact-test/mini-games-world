# MVP-14 interface invite polish v1135

This integration contains three separately scoped interface tasks and no game, server, database, economy, payment, webhook or Cron behavior changes.

## 1. Invite entry responsiveness

- the player picker no longer starts a competing warm-link draft discard before its one authoritative opponents request;
- direct invite creation finishes before warm-draft cleanup is deferred;
- a warm share request that becomes user-awaited uses high browser priority;
- no cache, second owner, retry, optimistic player list, global fetch wrapper or additional endpoint was added.

## 2. Owner cancellation

- `Отменить приглашение` uses the existing primary purple button style;
- the owner waiting sheet closes and returns home immediately on the real click;
- the server remains the only authoritative cancellation owner;
- a failed cancellation restores the exact captured invitation and sheet.

## 3. Waiting-sheet copy

- the ordinary delivery note about the app and bot is removed;
- direct and shared invitations use the same clean waiting sheet;
- an optional contextual note remains available only when explicitly supplied, such as for rematch.

## Publication and validation boundary

- canonical invite owner: `game-invites-v110.js?v=1135`;
- focused contracts:
  - `ProductionMvp14InterfaceInviteEntrySpeedV1135Test.php`;
  - `ProductionMvp14InterfaceOwnerCancelSpeedStyleTest.php`;
  - `ProductionMvp14InterfaceOwnerWaitingCopyCleanupTest.php`;
- the accepted one-request ready-first player-picker contract remains active;
- exact-head full CI is required before staging integration;
- DB/selector gate is required only if its path-scoped workflow applies to the final diff.
