# MVP-14 interface invite entry speed v1135

Scoped task: reduce intermittent latency of `Пригласить игрока` and `Поделиться ссылкой` without changing invite ownership or accepted UI flow.

Changes:

- the player picker no longer starts a competing warm-link draft discard before its one authoritative opponents request;
- direct invite creation completes before warm draft cleanup is deferred;
- the user-awaited warm share request uses high browser priority;
- canonical invite owner is published as v1135;
- no cache, second owner, retry, optimistic player list, global fetch wrapper, or additional endpoint was added.

Validation boundary:

- focused contract: `ProductionMvp14InterfaceInviteEntrySpeedV1135Test.php`;
- accepted ready-first player-picker contract remains active;
- exact-head CI and DB/selector checks are required before integration.
