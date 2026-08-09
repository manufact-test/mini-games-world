# Shield King — Icon Manifest

## Contract

Core UI sprites use `viewBox="0 0 24 24"`, `currentColor` and the state rules from `ICONS.md`.

Game icons are standalone `viewBox="0 0 64 64"` assets using the fixed Shield King dark/violet/gold/silver palette.

---

# Navigation sprite

File: `icons/navigation/navigation-icons.svg`

| Symbol | Semantic name | Default size | Typical usage | Active behavior |
|---|---|---:|---|---|
| `#home` | Home | 24px | Home/app navigation | primary purple / active component indicator |
| `#profile` | Profile | 24px | Profile/user entry | same artwork, active color |
| `#games` | Games | 24px | Game catalogue/game entry | same artwork, active color |
| `#store` | Store | 24px | Store entry | same artwork; gold only for premium semantics inside store, not navigation selection |
| `#friends` | Friends | 24px | Friends/social entry | same artwork, active color |
| `#notifications` | Notifications | 24px | Notification center | unread count badge is separate layer |
| `#settings` | Settings | 20–24px | Settings entry | same artwork, active color |
| `#ranking` | Ranking | 20–24px | Ranking/leaderboard entry when authoritative | no fake rank data |
| `#achievements` | Achievements | 20–24px | Achievements entry when authoritative | no fake achievements |
| `#history` | History | 20–24px | Match/transaction/history entry according to screen contract | same artwork |
| `#rules` | Rules | 20–24px | Rules/game information | same artwork |
| `#search` | Search | 18–20px | Search input/action | focus/active supplied by input/button |

---

# Action sprite

File: `icons/actions/action-icons.svg`

| Symbol | Semantic name | Default size | Typical usage | Notes |
|---|---|---:|---|---|
| `#back` | Back | 20px | Top bar/back navigation | navigation action only |
| `#close` | Close | 20px | Modal/sheet/dismiss | 44px hit target |
| `#more` | More | 20px | Overflow actions | three dots, no implied menu content |
| `#edit` | Edit | 20px | Profile/edit actions | only where edit exists |
| `#invite` | Invite | 20px | Invite flow entry | no fake sent/success state |
| `#rematch` | Rematch | 20px | Result/rematch action | only after contract exposes rematch |
| `#retry` | Refresh / Retry | 20px | Retryable loading/network error | same semantic asset for retry/refresh where context disambiguates |
| `#surrender` | Surrender | 20px | Gameplay surrender action | destructive styling supplied by component |
| `#chevron-right` | Disclosure / Next | 18px | List rows/navigation affordance | not a standalone CTA |
| `#check` | Check | 18px | Selected/success affordance | semantic status icon `success` remains separate when a status circle is needed |

`share` is intentionally not included in V1 because the current design roadmap does not establish a required shared-product share action.

---

# Status sprite

File: `icons/status/status-icons.svg`

| Symbol | Semantic name | Default size | Typical usage | Color treatment |
|---|---|---:|---|---|
| `#win` | Win | 20–24px | Authoritative victory/result | controlled gold allowed |
| `#loss` | Loss | 20–24px | Authoritative loss/result | neutral/silver; not automatic error red |
| `#draw` | Draw | 20–24px | Authoritative draw/result | silver/neutral |
| `#warning` | Warning | 18–20px | Warning/threshold | warning color + text |
| `#error` | Error | 18–20px | System/action error | error color + text |
| `#info` | Information | 18–20px | Informational state | info/violet |
| `#online` | Online | 8–16px | Player presence where authoritative | success |
| `#offline` | Offline | 8–16px | Player presence where relevant | muted neutral |
| `#locked` | Locked | 18–20px | Locked content/action | lock ≠ disabled |
| `#unlocked` | Unlocked | 18–20px | Explicit unlocked state | neutral/success only if semantics justify |
| `#success` | Success | 18–20px | Confirmed successful operation | success color |

---

# Economy sprite

File: `icons/economy/economy-icons.svg`

| Symbol | Semantic name | Default size | Typical usage | Color treatment |
|---|---|---:|---|---|
| `#coins` | Standard coins | 20–24px | Standard/free coin balance/stake | silver/primary or product-defined standard coin treatment; not premium gold by default |
| `#gold` | Gold currency | 20–24px | Gold/premium currency balance | gold |
| `#premium` | Premium/value marker | 18–20px | Premium badge/value context | gold; use sparingly |

All balance values remain functional/economy-owned.

---

# Eight-game assets

Directory: `icons/games/`

| File | Semantic name | Default card size | Identity |
|---|---|---:|---|
| `game-tic-tac-toe.svg` | Tic Tac Toe | 48–64px | violet grid, gold/violet X and silver O |
| `game-four-in-a-row.svg` | Four in a Row | 48–64px | framed connect board with purple/gold discs |
| `game-battleship.svg` | Battleship | 48–64px | ship silhouette, silver hull, violet water, gold mast accent |
| `game-checkers.svg` | Checkers | 48–64px | compact dark/violet board with purple/gold pieces |
| `game-reversi.svg` | Reversi | 48–64px | overlapping opposing discs with dark/light split identity |
| `game-chess.svg` | Chess | 48–64px | simplified premium knight with silver body and gold accent |
| `game-go.svg` | Go | 48–64px | compact grid with dark/light stones and violet/gold ring accents |
| `game-domino.svg` | Domino | 48–64px | angled domino tile with purple/gold/silver pips |

All eight share:

- outer 56×56 rounded tile inside 64×64 viewBox;
- `#17121F` tile surface;
- `#342A43` base border;
- `#6A4CFF` / `#A65FF7` violet accents;
- `#FFD45C` selective gold accents;
- `#E6E8EF` primary light/silver readability;
- no text/font dependency;
- no glow baked into the asset.

---

# Badge relationship

Notification/unread/online badges are component layers and are not embedded into navigation artwork.

Recommended relationship:

```text
24px icon
+ separate 18px min unread badge
+ badge anchored to top-end outside meaningful icon center
```

Online presence uses the status symbol/indicator contract and must not be hardcoded into avatar source artwork.

---

# File integrity checklist

Expected exact assets:

```text
icons/navigation/navigation-icons.svg
icons/actions/action-icons.svg
icons/status/status-icons.svg
icons/economy/economy-icons.svg
icons/games/game-tic-tac-toe.svg
icons/games/game-four-in-a-row.svg
icons/games/game-battleship.svg
icons/games/game-checkers.svg
icons/games/game-reversi.svg
icons/games/game-chess.svg
icons/games/game-go.svg
icons/games/game-domino.svg
```

DS-2 may not pass until all paths above exist and the visual family receives manual product-owner acceptance.
