# MVP-14 v99 manual regression

Run after code-only deployment and a completely closed/reopened Telegram Mini App.

1. Tic Tac Toe: make every move from both devices. A mark appears once and never disappears. Winning result opens without a multi-second frozen board.
2. Battleship: select 4, 3 and 2 adjacent cells belonging to one ship. Partial dots stay visible; the complete ship appears immediately. A separate touching ship remains rejected.
3. Other games: make at least three actions in Four in a Row, Checkers, Reversi, Chess, Go and Domino. No first tap is lost and no move flashes away.
4. Secondary device: start a match on desktop and leave mobile idle on Home for ten seconds. Mobile shows nothing and does not enter the match. Only an explicit start attempt shows one Russian lock message.
5. Invite picker: the setup sheet remains visible until the complete player list replaces it; no loading card is visible.
6. Search cancel: Home opens once, Search does not reopen and no cancellation toast appears.
7. Invitations: accept/start a direct invite and a rematch. Only the device that explicitly participated enters the game.
8. Finish and navigation: victory, defeat, draw, rematch, new opponent and Home do not reuse or flash an old board.

MVP-14 remains open until every item passes on Telegram Desktop and Telegram Mobile.
