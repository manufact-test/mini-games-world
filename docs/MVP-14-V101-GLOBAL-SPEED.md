# MVP-14 v101 — Global speed pass

## Scope

This release is intentionally limited to perceived and request latency. It does not change game rules, legal moves, winner detection, balances, settlement, matchmaking rules or session ownership.

## Performance changes

- one global fetch layer covers every versioned `api/client.js` module instance;
- active game actions abort older `game_state` and safe background reads before the server action starts;
- action requests receive high fetch priority and passive reads receive low priority;
- bootstrap data seeds the stats, shop and weekly-status caches;
- profile, notifications, shop orders, weekly status and shop status are prefetched after app readiness;
- cached screens render immediately and refresh in the background within bounded stale windows;
- the notification cache is updated directly from live invitation events;
- opening notifications can render the current cache immediately while mark-read is confirmed asynchronously;
- opponent lists are prefetched when invitation setup begins;
- Telegram prepared invite messages are warmed before the Share button is pressed;
- a narrow 350 ms post-game watch accelerates rematch notifications for at most seven seconds;
- a match result sheet opens from the already authoritative finished action response instead of waiting for another UI cycle.

## Preserved v100 behavior

- `search-screen-v100.js` remains the only ordinary matchmaking owner;
- `game-screen-v100-safe.js` remains the only active game owner;
- no historical v90-v99 action or polling coordinator is restored;
- invite player picker stays free from the intermediate loading-frame regression;
- search cancellation remains silent and does not reopen;
- passive secondary devices stay silent until explicit play intent;
- server remains authoritative for every legal action and final result.

## Telegram native boundary

The Mini App can reduce the delay before Telegram's native share picker opens by preparing the message early. The native picker and its final `Готово` state are controlled by Telegram and cannot be dismissed early by Mini App JavaScript.

## Deferred game-correctness backlog

These issues are recorded but deliberately not changed during the speed pass:

- Tic Tac Toe may briefly preview a mark after a tap when it is not the player's turn;
- each game requires a separate complete rules and interaction pass after the global speed stage is accepted;
- any game-specific animation, legal-move, placement or selection defect belongs to that later stage.

## Manual acceptance checklist

1. Open the newest `/start` button on Telegram Desktop and Mobile.
2. Open profile, notifications, weekly Match info, store and store orders twice; the second open must be immediate and must not show a visible loader.
3. Open invitation setup, wait less than a second, press Share; the native Telegram picker should open without the former app-side two-second preparation pause.
4. Cancel and complete the native share flow; no airplane/loading sheet may appear.
5. Finish a match and return to the menu; a new rematch notification should surface substantially faster than the former 1.5-second sync interval.
6. Open the bell from that toast; the actionable rematch card must already be present.
7. In every game, make two valid actions while background stats/notification polling is active; the action request must not wait behind those reads.
8. Complete one match; the server-confirmed result sheet must open immediately after the final response, without an additional polling cycle.
9. Recheck all v100 regressions: invite picker, silent search cancel, secondary-device lock, icons and one-runtime routing.

Production deployment still requires exact-head authorization and manual Hostinger Redeploy.
