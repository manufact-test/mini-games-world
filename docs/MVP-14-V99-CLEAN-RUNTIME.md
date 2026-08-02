# MVP-14 v99 — clean client runtime

## Reason for replacement

Production v98 still loaded the v97 action owner, the v98 polling bridge and the old v90–v92 coordinators through `main.js`. The same click or server response could therefore be rendered by more than one owner.

Observed consequences on real Telegram clients:

- a Tic Tac Toe mark appeared, disappeared and returned;
- a finished match waited for another legacy poll before opening the result;
- Battleship partial placement was replaced by timer polling and a completed ship waited for the server;
- passive secondary devices repeatedly surfaced session-lock messages or entered an active game through invitation sync.

## V99 contract

- a separate no-store `/app/v99.php?v=99` entrypoint;
- no v90–v98 search/action/polling coordinator in the active module graph;
- one search epoch and one cancellation owner;
- one game polling loop and one serialized action queue per game;
- optimistic state remains visible until its own authoritative response;
- in-flight stale polling is discarded by generation;
- finished action responses open the result directly without another `game_state` request;
- Battleship partial cells remain local and timer-only polling does not rebuild the board;
- a complete ship is represented as one atomic optimistic action;
- passive session locks are stored silently and shown only after explicit launch intent;
- invitation sync may enter a game only after an explicit invite accept/start/rematch intent on that device.

## Safety

Code, tests and documentation only. No database, cutover, release, recovery, rollback, rearm, private configuration, JSON state, Cron or webhook change is included.
