# MVP-14 v97 — single production runtime owner

## Manual production regressions after v96

Real Telegram Desktop and Telegram Mobile testing confirmed:

- cancelling matchmaking could briefly reopen the search screen;
- the bell badge could be non-zero while the sheet rendered an older empty snapshot;
- a Tic Tac Toe optimistic mark could disappear for one frame before returning;
- frequent board replacement could swallow the first mobile tap in Checkers and other games;
- Battleship client preview did not repeat the full server no-touch placement rule;
- a locked secondary session could still be navigated into the active game before the lock message was shown.

## Root cause

The application still started several historical latency and regression coordinators. They owned overlapping click paths, API methods and polling responses. Server game rules remained authoritative, but competing client owners could repaint or navigate using stale state.

## v97 contract

- one capture-phase owner for search start/cancel, notifications and Tic Tac Toe cells;
- one API owner for game state and game action;
- one per-game serialized action queue;
- search epoch invalidation before an asynchronous leave request;
- fresh notification request when the bell is opened;
- optimistic game state retained until its own server action resolves;
- timer-only polling updates game chrome without replacing the board;
- polling waits for an active mobile pointer gesture to finish;
- Battleship overlap and eight-neighbour adjacency validation repeated on the client while the server remains authoritative;
- session-locked clients clear the game surface and stay on Home;
- a separate no-cache `/app/v97.php?v=97` entrypoint excludes the v94-v96 production action owners.

## Safety

- no database schema or production data changes;
- no cutover, release, recovery, rollback or rearm operations;
- no private config, JSON state, Cron or webhook changes;
- balances, winner, legal moves and settlement remain server-authoritative;
- production deployment requires separate explicit approval after green CI.
