# MVP-14 v98 focused manual regression

Run only after PR approval, code-only deployment and a fresh `/start` button that opens `/app/v98.php?v=98`.

## 1. Invite picker transition

1. Open any game conditions.
2. Press `Пригласить друга` and then `Пригласить игрока`.
3. Repeat three times on Telegram Desktop and three times on Telegram Mobile.

Expected: the conditions sheet remains visible until the complete player list replaces it. No loading sheet, icon, text or intermediate card is visible even for one frame.

## 2. Silent search cancellation

1. Start matchmaking.
2. Cancel after one second.
3. Wait seven seconds without touching the UI.

Expected: Home opens once. Search does not reopen and no `Поиск отменён` or `Поиск остановлен` toast is shown.

## 3. Passive secondary device

1. Open the same account on desktop and mobile.
2. Start a match on desktop while mobile remains on Home.
3. Watch mobile for at least ten seconds.
4. Only then press a game launch button on mobile.

Expected: while idle, mobile does not jump, open a game or show any lock message. After the explicit launch attempt, one Russian lock message is shown and the screen remains stable.

## 4. Initial game surface

Start a match on desktop and mobile through search and through a direct invitation.

Expected: header, players and complete board appear together. No empty board, half-rendered field or second-stage load is visible.

## 5. Battleship setup

1. Select the four cells of a four-deck ship slowly, with at least one polling interval between taps.
2. Repeat for three-, two- and one-deck ships.
3. Try overlap, horizontal/vertical contact, diagonal contact and board overflow.

Expected: selected points never disappear between taps. A completed ship appears immediately and never flashes away. All invalid placements remain blocked.

## 6. All game actions

Make at least three actions in Tic Tac Toe, Four in a Row, Checkers, Reversi, Chess, Go, Domino and Battleship.

Expected: timer changes do not replace the board. First taps are not lost. Optimistic moves do not disappear and return. Checkers multi-capture remains continuous.

## Acceptance

MVP-14 remains open until every section passes on real Telegram Desktop and Telegram Mobile.
