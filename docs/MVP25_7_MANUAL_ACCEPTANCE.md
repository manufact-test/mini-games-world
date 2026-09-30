# MVP-25.7 — Final Telegram Product RC manual acceptance

**Status:** NOT STARTED / WAIT FOR AUTOMATED RC + EXACT STAGING E2E

This is the final human regression for the Russian Telegram Mini App before MVP-25 closes.
It must be performed on the exact staging RC SHA supplied after the automated gate.

Do not treat the intentionally disabled real-money top-up surface as missing functionality:
MVP-25.6 intentionally ships the finished product without real-money/provider integration.

## Final human checklist

### 1. Cold launch and shell
- fully close the Telegram Mini App and reopen it;
- Home appears normally with no diagnostic/bootstrap/API/DB/runtime text;
- switch Home → Arena → Store → Profile → Home;
- topbar and bottom navigation remain aligned and responsive.

### 2. More / shared sheets
- open More, Settings, Rules, History and Support;
- all rows/actions remain reachable on the phone viewport;
- no clipped primary action, broken close button or horizontal scroll;
- ordinary copy remains human-facing.

### 3. Profile
- open Profile from both bottom navigation and top identity/avatar;
- profile appears without a stuck loader or broken layout;
- owned cosmetics render normally;
- Data and account / Moderation sheets remain reachable;
- the known MVP-25.4 Profile micro-hitch may still occur, but no materially worse
  delay, freeze, crash or stuck state is acceptable.

### 4. Store
- Store opens normally and shows the current coin balance;
- visible tabs are Profile / Games / Bundles; real-money coin top-up is not presented;
- there is no “Скоро” monetization card;
- open several cosmetics/bundles and verify previews/actions remain intact;
- do not require any real-money purchase flow.

### 5. Arena
- open rating/leaderboard and tournament surfaces;
- Hall / current tournament / reachable result or reward state renders normally;
- no internal owner/projection/settlement wording leaks to the player.

### 6. Friends, invites and notifications
- open Friends and search/player actions;
- open the normal invite flow;
- Notification Center opens, cards/actions align and remain usable;
- no duplicate/stuck invite or notification surface is visible.

### 7. Eight-game visual smoke
Briefly open the setup/game surface for all eight games:
- Tic Tac Toe;
- Chess;
- Checkers;
- Reversi;
- Go;
- Domino;
- Four in a Row;
- Battleship.

For each: board/game surface must render, controls must be reachable, and no obvious
layout/cosmetic regression may appear. Do not re-judge previously accepted mechanics
unless a reproducible regression is visible.

### 8. One real two-player path
Using the normal staging accounts/players:
- send or accept an invite;
- enter one match;
- make several actions;
- finish or safely exit the match;
- verify result/rematch/history path is not stuck.

### 9. Network recovery spot-check
- on a safe read-only screen such as Profile or Arena, temporarily remove connectivity;
- trigger a refresh/open;
- visible error must be human-readable, not raw browser/API/HTTP/SQL/stack text;
- restore connectivity and verify recovery without reopening the whole app if possible.

### 10. Final product-owner verdict

Pass only if there is no new launch-blocking regression across the sweep.

Accepted known residual:
- the already recorded slight Profile visual hitch from MVP-25.4 may remain as-is.

A PASS must be explicitly recorded by the product owner on the exact staging RC SHA.
