# Shield King — Exact Icon System

## 1. Status

`DS-2 MANUALLY ACCEPTED / SOURCE ASSETS NORMALIZED`

The product owner accepted the **Variant 1** family after iterative visual review on 2026-08-10 (+03:00).

This file is the durable written source of truth. Generated concept boards are visual references only; the exact SVG files and rules below control future implementation.

---

## 2. Core UI icon contract

Core app icons deliberately **do not use the large royal shield frame**.

They are the lighter layer of the Shield King system:

- clean standalone metallic/silver glyph language;
- restrained purple/gold semantic accents;
- no bulky shield container around Home/Profile/Games/Store/etc.;
- component surfaces may supply a subtle dark tile, tint, hover surface or active indicator;
- the icon itself stays visually compact and readable.

### Geometry

- source viewBox: `0 0 24 24`;
- default stroke: `1.75`;
- line cap/join: `round`;
- default source color: `currentColor` where applicable;
- standard rendered size: `20–24px`;
- compact metadata: `16–18px`;
- touch target remains component-owned and at least `44×44px`.

### State behavior

Inactive: muted, no glow.

Hover/focus: lighter semantic foreground plus component-level tint/focus ring.

Active/selected: same artwork, primary violet/primary text plus component indicator.

Disabled: disabled foreground, no glow.

Gold is semantic/premium, not a universal selected color.

---

## 3. Core SVG sprites

```text
icons/navigation/navigation-icons.svg
icons/actions/action-icons.svg
icons/status/status-icons.svg
icons/economy/economy-icons.svg
```

The same semantic artwork is reused between inactive/active states. Do not replace it with unrelated active icons.

Unread/online badges remain separate component layers.

---

## 4. Royal game-icon contract — accepted

The eight game icons are intentionally richer than ordinary UI icons.

Every game asset uses the **same royal frame template**:

```text
viewBox: 0 0 96 112
outer visual width: identical for all eight
crown: identical geometry, size and placement
shield/frame silhouette: identical geometry and width
bottom pedestal/banner: identical geometry and width
interior art: game-specific only
```

This equal-width rule is mandatory because the icons must align cleanly in Home/game-catalogue cards.

Do not narrow a frame because its central symbol is narrow (for example Chess). Add negative space / dark-violet field inside the standard frame instead.

### Shared visual construction

- crown: `#FFD45C` with controlled deep-gold edge;
- frame: silver/neutral → restrained gold transition;
- field: deep violet / dark brand surface;
- no green board/background in this family;
- no unrelated neon colors;
- no extra nested black card inside the Tic Tac Toe field;
- no baked noisy glow;
- no text/font dependency inside the SVG asset;
- game name is supplied by the surrounding UI component/localization layer.

---

## 5. Exact eight-game semantics

### Tic Tac Toe

- X/O grid sits directly on the single dark-violet interior field;
- no second black background panel behind the board;
- silver X, controlled gold O allowed.

### Four in a Row

- exactly **two player disc colors**;
- current V1 asset uses dark/black and silver/light discs;
- no third-color player pieces.

### Battleship

- recognizable premium ship silhouette;
- silver/neutral hull;
- violet environmental accent and restrained gold mast/detail allowed.

### Checkers

- recognizable checkerboard;
- actual round checkers pieces only;
- two teams are **solid black and solid gold** in the accepted family;
- one physical piece must never be half-black/half-gold;
- board may have a subtle controlled tilt, but must remain geometrically coherent and fill the available field;
- no fake/unrelated center symbols.

### Reversi

- visually distinct from Checkers;
- grid/board stays dark violet, never green;
- discs are **black and silver/white only**;
- classic/recognizable Reversi placement language;
- no gold player discs.

### Chess

- central silver king;
- **same external crown and same full-width frame** as every other game;
- narrow chess piece does not make the asset/frame narrower.

### Go

- board uses only **black and white/silver stones**;
- no third-colored stone;
- dark-violet Shield King board treatment.

### Domino

- recognizable angled light domino tile;
- restrained gold pips/details;
- same external full-width frame as all games.

---

## 6. Exact game files

```text
icons/games/game-tic-tac-toe.svg
icons/games/game-four-in-a-row.svg
icons/games/game-battleship.svg
icons/games/game-checkers.svg
icons/games/game-reversi.svg
icons/games/game-chess.svg
icons/games/game-go.svg
icons/games/game-domino.svg
```

Recommended rendered size in game cards: `56–80px` depending on card density. Scale all eight from the same bounding box; never hand-size Chess or Domino separately.

---

## 7. Accessibility / semantic rules

- icon-only controls require accessible labels;
- critical status cannot rely on color alone;
- decorative artwork is hidden from accessibility APIs where appropriate;
- status icons render authoritative product state only;
- `win`, `loss`, `draw`, `error`, `timeout` remain distinct semantics;
- `locked` and `disabled` remain distinct semantics.

---

## 8. Forbidden patterns

Do not:

- put the royal game shield around every ordinary navigation/action icon;
- mix unrelated icon packs/styles;
- use emoji as production UI icons;
- generate separate unrelated active artwork;
- use gold for every selected state;
- change the width/crown/frame per game;
- add a nested redundant black panel to Tic Tac Toe;
- add green Reversi background;
- add a third player/stone color to Four in a Row or Go;
- use hybrid two-color individual checker pieces;
- rebuild the approved primary Shield King mark as a generic nav glyph.

---

## 9. Acceptance record

Manual review outcome:

```text
CORE UI ICON DIRECTION:
ACCEPTED — LIGHTER / NO LARGE SHIELD FRAME

GAME ICON DIRECTION:
ACCEPTED — ROYAL CROWNED SHIELD FRAME

GAME FRAME GEOMETRY:
ONE IDENTICAL WIDTH / CROWN / OUTER TEMPLATE FOR 8/8

CHECKERS / REVERSI / GO / FOUR-IN-A-ROW RULES:
CORRECTED AND FROZEN IN THIS CONTRACT
```

Any later visual change must update this source-of-truth contract and all dependent assets consistently; do not patch one screen independently.
