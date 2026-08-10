# Shield King — Icon System

## 1. Status

```text
DS-2 VISUAL FAMILY — MANUALLY ACCEPTED
DS-2 EXACT PRODUCTION ART EXPORT — CORRECTION REQUIRED
```

The product owner accepted the **rich metallic Variant 1** family after iterative visual review on 2026-08-10 (+03:00).

Critical correction: the simplified geometric SVGs produced earlier in this branch are not visually equivalent to that accepted artwork. They are semantic/geometry references only until exact production art is exported.

Future approval mockups must not substitute those simplified SVGs for the accepted rich Variant 1 finish.

---

## 2. Core application icon contract — accepted

Ordinary application icons deliberately **do not use the large royal shield frame**.

Accepted character:

- standalone metallic/silver glyphs;
- premium but compact depth;
- restrained purple/gold accents only where useful;
- no bulky shield around Home/Profile/Games/Store/Friends/Notifications/Settings/etc.;
- icon background/container is owned by the existing UI component, not baked into every icon;
- readable at small application-icon sizes.

State rules:

- inactive → muted/silver neutral;
- hover/focus → lighter neutral + component focus treatment;
- active/selected → same semantic icon with controlled violet emphasis;
- disabled → disabled neutral;
- gold remains semantic/premium, not a universal selected color.

Required semantics remain mapped in:

```text
icons/navigation/navigation-icons.svg
icons/actions/action-icons.svg
icons/status/status-icons.svg
icons/economy/economy-icons.svg
```

Those SVG sprites currently preserve semantic IDs/geometry and may guide implementation, but their flat line-art finish is not the final accepted metallic art finish.

---

## 3. Royal game-icon contract — accepted

The eight game icons are intentionally richer than ordinary application icons.

Every game uses the **same external royal frame template**:

```text
same outer width
same outer height
same crown geometry / size / placement
same royal side-frame geometry
same bottom-frame/pedestal geometry
only the interior game artwork changes
```

A narrow central symbol such as Chess must never make the icon itself narrower.

Shared finish:

- metallic silver/neutral frame;
- restrained gold crown/details;
- dark-violet / near-black interior field;
- premium dimensional material treatment;
- no unrelated green/cyan decorative branding;
- no inconsistent crown/frame widths between games.

---

## 4. Frozen eight-game semantics

### Tic Tac Toe

- X/O board directly on one interior field;
- no redundant black panel placed on top of another purple background;
- silver X and controlled gold O are allowed.

### Four in a Row

- exactly two player colors;
- no third player/token color.

### Battleship

- recognizable premium ship composition;
- dark-violet field;
- silver/neutral ship with restrained gold detail.

### Checkers

- coherent checkerboard that fills the available interior field;
- real round checker pieces;
- black team vs gold team;
- one physical checker can never be half black / half gold;
- slight board perspective/tilt is allowed only when the board remains clean and believable.

### Reversi

- visually distinct from Checkers;
- black and white/silver discs only;
- no green background;
- dark-violet Shield King field/grid.

### Chess

- central premium silver king;
- exactly the same external crown/frame width and height as every other game.

### Go

- black and white/silver stones only;
- no third stone color;
- dark-violet Shield King board treatment.

### Domino

- recognizable angled light domino tile;
- restrained gold pips/details;
- exactly the same external royal frame width as all other game icons.

---

## 5. Current geometry-reference files

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

These files encode the shared bounding-box intent and semantic content, but **must not be treated as the accepted final visual artwork**.

They may remain temporarily so future implementation work does not lose semantic mappings, but exact production-art export must replace/supersede them before DS-2 is finally closed as an exact asset pack.

---

## 6. Existing UI usage rule

For the future shared-UI migration:

- keep the current application layout and icon slots;
- replace ordinary emoji/generic icons with the accepted compact metallic application family;
- replace game artwork/emoji/placeholder slots with the accepted rich royal game family;
- do not resize/rebuild the surrounding Home/game-card layout simply to fit the icons;
- scale all eight royal game icons from the same external bounding box.

See `CURRENT_UI_MIGRATION.md`.

---

## 7. Accessibility / semantics

- icon-only controls require accessible labels;
- critical state cannot rely on color alone;
- status icons render authoritative product state only;
- `win`, `loss`, `draw`, `error`, `timeout` remain distinct;
- `locked` and `disabled` remain distinct;
- decorative artwork should be hidden from accessibility APIs where appropriate.

---

## 8. Forbidden patterns

Do not:

- put the royal game shield around every ordinary application icon;
- use emoji as the final production icon family;
- use the simplified flat SVG game art as a substitute in future visual acceptance;
- generate unrelated active icons;
- use gold for every selected state;
- vary royal frame width/crown per game;
- add a second background panel inside Tic Tac Toe;
- use green for Reversi;
- add a third player/stone color to Four in a Row or Go;
- use hybrid two-color individual checker pieces;
- rebuild Home to accommodate an icon concept.

---

## 9. Acceptance record

```text
CORE APPLICATION ICON VISUAL DIRECTION:
ACCEPTED — COMPACT METALLIC / NO LARGE SHIELD FRAME

GAME ICON VISUAL DIRECTION:
ACCEPTED — RICH METALLIC ROYAL CROWNED FRAME

GAME FRAME GEOMETRY:
ONE IDENTICAL EXTERNAL WIDTH / HEIGHT / CROWN / FRAME FOR 8/8

SIMPLIFIED SVG VISUAL FINISH:
NOT ACCEPTED AS FINAL ART

EXACT PRODUCTION ART EXPORT:
REQUIRED BEFORE FINAL DS-2 DONE
```

Any later change must update the family rule and all dependent assets consistently; do not patch one screen independently.
