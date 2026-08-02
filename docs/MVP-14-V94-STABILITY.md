# MVP-14 v94 UI stability fix

## Manual regressions addressed

- the first Tic Tac Toe tap could be swallowed until the first authoritative viewer poll completed;
- a finished board remained visible while a rematch request was starting and could flash behind later screens;
- the player picker painted its loading sheet even though opponents had already been warmed;
- the mobile WebView could fail the whole boot when an optional profile/history/notification/opponent read returned a transient error;
- the Tic Tac Toe `○` glyph rendered much smaller than `✕` on some mobile fonts;
- raw English transport errors could remain visible to the user.

## Implementation

- retain one capture-phase Tic Tac Toe owner and derive the first-frame viewer only from an enabled cell plus the authoritative server turn;
- clear game DOM before rematch/home navigation and show the search screen before awaiting `start_search`;
- install a per-user read-through cache before the v92 module graph captures `fetch`;
- degrade only read-only snapshots on network, 429 or 5xx failures; mutating game, payment, shop and invitation actions are never faked;
- preserve bootstrap authentication as authoritative and use a locked cached bootstrap only after a prior successful boot on the same Telegram account;
- render Tic Tac Toe circles with proportional CSS geometry;
- normalize technical transport strings to a Russian user-facing message.

## Safety

- no schema or production data changes;
- no cutover, release, rollback or recovery operations;
- no private config, JSON rollback source, Cron or webhook changes;
- the legacy Telegram mobile ForceReply draft is device-local residue from builds before v71. The current bot no longer creates it; dismissing the reply bar once or sending `/cancel` clears it on that phone.
