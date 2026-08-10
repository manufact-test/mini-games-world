# Shield King — Icon Manifest

## Status

`DS-2 ACCEPTED`

Core UI sprites remain compact `24×24` semantic glyph assets. Game icons are standalone equal-frame `96×112` royal assets.

---

## Core UI assets

### Navigation

File: `icons/navigation/navigation-icons.svg`

Symbols: `home`, `profile`, `games`, `store`, `friends`, `notifications`, `settings`, `ranking`, `achievements`, `history`, `rules`, `search`.

Default render: `20–24px`.

Accepted treatment: **no large shield frame** around ordinary app/navigation icons.

### Actions

File: `icons/actions/action-icons.svg`

Symbols include: `back`, `close`, `more`, `edit`, `invite`, `rematch`, `retry`, `surrender`, `chevron-right`, `check`.

Default render: `18–20px`; touch target component-owned at `44px+`.

### Status

File: `icons/status/status-icons.svg`

Symbols include: `win`, `loss`, `draw`, `warning`, `error`, `info`, `online`, `offline`, `locked`, `unlocked`, `success`.

Status is visual only and never fabricates application state.

### Economy

File: `icons/economy/economy-icons.svg`

Symbols: `coins`, `gold`, `premium`.

Gold is premium/semantic, not the generic selected color.

---

## Eight accepted royal game assets

All eight use:

```text
viewBox: 0 0 96 112
identical external frame width
identical crown geometry and placement
identical outer shield silhouette
identical lower pedestal/banner geometry
single deep-violet/dark field family
no embedded text/font dependency
```

| File | Game | Interior contract |
|---|---|---|
| `icons/games/game-tic-tac-toe.svg` | Tic Tac Toe | X/O grid directly on one violet field; no nested black panel |
| `icons/games/game-four-in-a-row.svg` | Four in a Row | two player colors only: dark + silver/light |
| `icons/games/game-battleship.svg` | Battleship | silver ship / violet environment / restrained gold detail |
| `icons/games/game-checkers.svg` | Checkers | coherent checkerboard; solid black pieces vs solid gold pieces; subtle controlled tilt |
| `icons/games/game-reversi.svg` | Reversi | dark-violet grid; black + silver/white discs only; no green |
| `icons/games/game-chess.svg` | Chess | silver king centered inside the same full-width royal frame |
| `icons/games/game-go.svg` | Go | black + silver/white stones only; no third color |
| `icons/games/game-domino.svg` | Domino | angled light domino / restrained gold pips inside same frame |

Recommended card rendering: `56–80px` while preserving the full source aspect ratio and using the same rendered bounding box for all eight.

---

## Explicit rejected patterns

- ordinary app icons inside large royal shields;
- per-game frame width differences;
- different Chess crown/frame;
- nested extra black Tic Tac Toe panel;
- green Reversi board;
- three player/stone colors in Four in a Row or Go;
- hybrid black/gold individual checker pieces;
- screen-specific resizing of Chess/Domino to compensate for different artwork widths.

---

## Exact asset checklist

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

The family was manually accepted by the product owner on 2026-08-10 (+03:00). Future implementation should consume these assets/rules rather than recreating the concept from screenshots.
