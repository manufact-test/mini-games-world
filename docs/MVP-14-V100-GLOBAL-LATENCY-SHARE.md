# MVP-14 v100 — global action latency and Telegram share repair

## Production evidence after v99

Real Telegram Desktop and Mobile testing showed:

- the legacy airplane/loading sheet still appeared behind Telegram sharing;
- the setup button remained visually stuck on “Готовим ссылку…” during the native share flow;
- returning after send/cancel could leave the Mini App visually waiting for the Telegram callback;
- Tic Tac Toe became stable on Mobile but a desktop first press could still be lost;
- Battleship setup became usable, while battle shots and other game actions still exposed server latency.

## Root causes

1. `game-invites.js` still called `showSharingSheet()` and awaited the native `shareMessage` callback before reconciling the visible sheet.
2. Pointer hold prevented new polls but did not invalidate a poll already in flight before `pointerdown`; that poll could replace the button between press and click.
3. Battleship optimistic fire stored `pending_fire_cell`, but the renderer never mapped it back to the clicked cell.
4. Optimistic action coverage existed in several historical modules instead of one tested gateway for all eight games.

## V100 architecture

- one capture-phase Telegram share controller owns only `[data-create-link-invite]`;
- the controller leaves the prepared setup sheet visible, resets the button before opening Telegram and handles send/cancel asynchronously;
- no airplane/loading sheet is rendered by the v100 path;
- one v100 game screen owns polling, action queue and reconciliation;
- `pointerdown` increments the active game generation, making every earlier poll stale before DOM click dispatch;
- one v100 model gateway covers Tic Tac Toe, Four in a Row, Battleship, Checkers, Reversi, Chess, Go and Domino;
- Battleship fire maps `pending_fire_cell` to a visible pending-shot cell;
- server responses remain authoritative and restore the board on rejection.

## Safety boundary

Code, tests and documentation only. No database, cutover, release, recovery, private config, JSON state, Cron or webhook changes. Production deployment requires separate exact-head authorization and manual Hostinger Redeploy.
