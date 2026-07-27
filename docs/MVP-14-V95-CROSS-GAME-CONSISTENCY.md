# MVP-14 v95 — cross-game consistency and latency repair

## Scope

This code-only hotfix addresses the manual regressions reported after production v94 across desktop and Telegram Mobile.

## Shared UI consistency

- Tic Tac Toe X and O are drawn with identical centered CSS geometry rather than platform font glyphs.
- Every `.close` control uses the same fixed 38×38 box and two centered CSS strokes.
- All eight home game cards use deterministic inline SVG icons, independent of OS emoji fonts.
- Desktop and mobile use the same icon and close-control dimensions.

## Player picker

- The prepared invite sheet remains visually stable while a cold opponent request resolves.
- The intermediate `notifications-loading` card is suppressed before paint.
- The final player list, empty state or retry state remains owned by the existing invitation coordinator.

## Immediate match entry

- All eight “Начать поиск” controls switch to the search screen in the same frame.
- A full game object returned by `start_search`, invitation start or rematch is rendered from the local authoritative response before the next network poll.
- Finished board DOM is still cleared before home/rematch navigation.

## Cross-game action feedback

The shared API coordinator gives immediate visual feedback while the authoritative server request is in flight:

- Four in a Row: disc drop.
- Checkers: move, capture and promotion preview.
- Reversi: placement and flips.
- Chess: move, capture, castling, en passant and promotion preview.
- Go: stone placement or pass.
- Domino: tile placement or draw status.
- Battleship: fleet placement/removal/clear and shot target status.

Tic Tac Toe remains owned by its existing dedicated v94 coordinator.

## Safety

- The server remains authoritative.
- Optimistic state cannot settle balances, determine winners or create purchases/invitations.
- Polling returns the pending local snapshot instead of overwriting it with a stale response.
- Server success replaces the optimistic snapshot.
- Server failure restores the previous board and lets the existing UI show the error.
- No database, cutover, release, recovery, private config, JSON rollback source, Cron or webhook changes.

## Required manual regression

Test both desktop and Telegram Mobile after a cold close:

1. Compare all eight home icons.
2. Open several setup/invite sheets and compare every close button.
3. Play Tic Tac Toe and compare X/O size and centering.
4. Open the player picker and confirm there is no loading-card flash.
5. Start each game and confirm the search/game screen appears immediately.
6. Perform one legal action in every game and confirm immediate visual feedback.
7. Confirm the opponent/server result reconciles correctly.
8. Check rematch, new opponent and return-to-menu transitions.
