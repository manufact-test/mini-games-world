# MVP-14 v97 manual regression checklist

Run each item after a full Telegram Mini App close and reopen through the new v97 button.

1. Start Match search and cancel immediately. Home must remain visible; Search must not flash again.
2. Produce one unread notification, open the bell, and confirm that the same item is visible before the badge becomes zero.
3. Play Tic Tac Toe from both sides. Each mark must appear once and never disappear between optimistic and server state.
4. In Checkers, select a piece with one tap and complete a two-capture chain without waiting for polling between jumps.
5. Perform one action in Four in a Row, Reversi, Chess, Go, Domino and Battleship. The local board must respond immediately and settle to the same server state.
6. In Battleship setup, verify that overlap, horizontal/vertical touching and diagonal touching are rejected.
7. Open the same account on two devices. Start/search/play on device A; device B must remain on Home with the Russian session-lock message and must never open the game screen.
8. Cancel search, leave a game, rematch and return Home; no previous screen or board may flash.
9. On Telegram Mobile, dismiss the historical payment ForceReply locally with its close control or send `/cancel` through the attached reply once.
