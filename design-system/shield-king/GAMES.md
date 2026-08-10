# Shield King — Eight-Game Visual System

## Status

`DS-4 8/8 GAME VISUAL CONTRACTS — READY FOR MANUAL VISUAL GATE`

This document defines the visual identity of all eight core Mini Games World games while preserving their existing mechanics, layout owners, responsive rules and runtime state.

Shared gameplay-shell rules are in `GAME_COMPONENTS.md`.

---

# 1. Common rule

Every game must feel like the same Shield King product, but its live board must remain instantly recognizable.

Shared product language:

```text
near-black shell
+ deep violet surfaces
+ silver/white material
+ controlled gold tactical accent
+ violet selection/active language
+ semantic green/warning/red only when state genuinely requires it
```

Do not apply the ornate crowned game-icon frame around live boards.

The royal frame is icon/catalogue artwork. Gameplay needs maximum usable area.

---

# 2. Tic Tac Toe

## Current mechanics/layout

Keep current generic 3×3 board owner and cell interaction exactly.

## Identity

The live board echoes the accepted icon without reproducing its frame:

- board surface: `#17121F`;
- cell surface: `#231942` with subtle silver-violet border;
- grid separation: `#342A43`;
- X: metallic silver / `#E6E8EF`;
- O: controlled crown gold / `#FFD45C`.

## Selected / last action

- latest cell → violet inset ring;
- no second background panel inside the board;
- no cyan/green player symbol.

## Win

Winning line/cells:

- gold + silver emphasis;
- short restrained pulse/glow;
- winning pieces remain readable.

## Disabled / opponent turn

- board remains visible at full contrast;
- interactive emphasis disappears;
- no opacity reduction that makes X/O hard to read.

---

# 3. Four in a Row

## Current mechanics/layout

Preserve current variable columns/rows, circular slots, column hit targets and responsive frame geometry.

## Board identity

Replace old blue board frame with Shield King deep violet:

```text
frame top: #231942
frame bottom: #17121F
edge/border: #6A4CFF at restrained opacity
slot cavity: #080B12
```

## Player discs

Exactly two player colors:

- Player A: silver/white metallic disc;
- Player B: crown-gold metallic disc.

Do not introduce a third disc color.

## Last move

- violet/silver outer ring;
- preserve current scale feedback.

## Winning run

- bright silver/gold ring;
- restrained white/gold glow;
- keep current winning animation ownership.

## Column target

- active press/hover uses violet tint, not bright blue.

---

# 4. Battleship

## Current mechanics/layout

Preserve:

- 10×10 coordinate board;
- setup placement flow;
- fleet picker;
- ready state;
- board tabs;
- event banner;
- miss/hit/sunk/last-shot semantics;
- current compact mobile layout.

## Ocean/board identity

Replace blue ocean branding with dark naval Shield King material:

```text
base: #17121F
secondary depth: #231942
cell edge: #342A43
subtle water sheen: violet-only low-opacity gradient
```

The board must remain clearly coordinate-based and readable.

## Ships

- silver/steel material;
- selected placement → violet outline;
- ready fleet → neutral/silver with semantic success indicator only in the ready callout.

## Miss

- small silver-white impact dot;
- no bright cyan.

## Hit

- gold tactical impact;
- `#FFD45C` core with restrained warm halo.

## Sunk

- semantic error/red `#FF617D`;
- replace emoji explosion in final production art with a simple vector/icon burst if implementation changes artwork;
- runtime sunk state remains unchanged.

## Last shot

- high-contrast silver outline.

## Invalid placement

- short red/error pulse.

## Ready state

Green may appear only because this state truly means ready/success.

---

# 5. Checkers

## Current mechanics/layout

Preserve:

- 8×8 board;
- selectable/source/target/capture states;
- chain capture;
- king promotion;
- last move;
- current compact gameplay geometry.

## Board identity

Replace current beige/brown board with Shield King royal board:

- light squares: muted silver-violet `#6F6979` / softened neutral;
- dark squares: deep violet `#231942`;
- surrounding edge: near-black with subtle metallic border.

The two square values must remain immediately distinguishable.

## Pieces

Match the accepted game-art family:

- black side: glossy near-black/graphite;
- gold side: `#FFD45C` / `#D69A32` metallic gold.

One checker is always one solid team color. Never split one physical piece black/gold.

## King

- same base team color;
- small crown/ring mark in silver/gold contrast;
- no giant icon pasted over the piece.

## Selection

- violet outline/ring.

## Legal normal move

- violet compact dot.

## Capture target

- gold tactical marker.

## Capture chain

- warning/gold event emphasis;
- do not use orange as a new brand family if gold can carry the same meaning.

## Captured/invalid feedback

- semantic red only for the temporary state.

---

# 6. Reversi

## Current mechanics/layout

Preserve:

- variable board size;
- legal move markers;
- black/white discs;
- flip animation;
- last move;
- pass state;
- scoring.

## Critical correction

The current green board is removed from the Shield King visual system.

No green decorative board background.

## Board identity

```text
board base: #231942
cell depth: alternating/subtle violet values
line/grid: #6F6979 or muted gold/silver at low opacity
outer edge: #342A43
```

Keep the grid clear enough for fast play.

## Discs

Exactly:

- black/graphite;
- white/silver.

No gold player discs.

## Legal move

- violet compact marker;
- not green.

## Last move

- restrained gold outline because it is historical emphasis, not player identity.

## Flip

Keep current flip animation timing/ownership; change material lighting only.

## Pass

Use warning/gold event treatment; no green.

---

# 7. Chess

## Current mechanics/layout

Preserve:

- 8×8 board;
- current legal move/capture indicators;
- selected cell;
- check state;
- castling/move animation;
- promotion flow.

## Board identity

Replace brown/beige palette with royal Shield King board:

- light squares: muted silver `#C7C3D1` / darkened for glare control;
- dark squares: deep violet `#231942`;
- outer edge: near-black/metallic neutral.

Maintain sufficient contrast for both piece colors.

## Pieces

- white side → silver/ivory metallic;
- black side → graphite/near-black with silver edge.

Do not make one player gold; gold is reserved for tactical emphasis.

## Selected square

- violet inset frame.

## Legal move

- violet compact marker, replacing legacy green.

## Capture target

- controlled gold ring.

## Check

- semantic error/red ring/pulse remains appropriate because check is a danger state.

## Promotion

Keep existing promotion choices/behavior; migrate card material and piece rendering to Shield King palette.

---

# 8. Go

## Current mechanics/layout

Preserve:

- existing supported board sizes;
- coordinate-point placement model;
- star points;
- black/white stones;
- capture animation;
- pass action;
- territory markers/final scoring.

## Critical correction

The current brown wooden board becomes Shield King dark-violet board material.

## Board identity

```text
base: #231942
secondary radial depth: #17121F
lines: restrained gold/silver (#D69A32 / #918A9E) at controlled opacity
star points: muted gold or silver
outer edge: #342A43
```

The lines must remain thin and legible.

## Stones

Exactly:

- black/graphite;
- white/silver.

No third stone color.

## Last move

- small gold inner marker/ring.

## Placement interaction

- violet touch/valid-target feedback;
- no green default state.

## Territory

Maintain existing black/white/neutral semantics:

- black territory → dark marker with silver edge;
- white territory → silver marker with dark edge;
- neutral → muted gold diamond is allowed.

## Pass

Use warning/gold event treatment; action/button geometry stays unchanged.

---

# 9. Domino

## Current mechanics/layout

Preserve:

- current adaptive chain layout/density;
- stock/opponent-back indicators;
- placement targets;
- hand layout;
- playable/selected states;
- draw/pass/final states;
- all responsive density logic.

## Critical correction

The current green felt/table is removed from the Shield King decorative palette.

## Table identity

```text
base: #17121F
center depth: #231942
edge: #342A43
very subtle violet vignette
```

No green table.

## Domino tiles

- ivory/silver tile body;
- graphite divider/pips;
- restrained gold pips/details allowed for selected/double/premium emphasis;
- keep high readability at compact chain densities.

## Playable tile

- violet outline/halo, replacing legacy green.

## Selected tile

- gold outline + slight lift, preserving current selection movement.

## Placement targets

- violet target ring/plus marker;
- no bright green plus.

## Latest chain tile

- controlled gold outer ring.

## Draw/start event

- gold/warning semantic remains valid.

## Pass/invalid

- semantic error/red only when runtime actually reports pass/invalid context requiring it.

---

# 10. Cross-game identity matrix

| Game | Board personality | Player/piece identity | Normal selection | Impact/capture |
|---|---|---|---|---|
| Tic Tac Toe | deep-violet 3×3 | silver X / gold O | violet | gold/silver win |
| Four in a Row | royal-violet frame | silver / gold discs | violet | silver/gold win |
| Battleship | dark naval violet | steel ships | violet | gold hit / red sunk |
| Checkers | silver-violet checkerboard | black / gold pieces | violet | gold capture |
| Reversi | dark-violet grid | black / white discs | violet | gold last move |
| Chess | silver-violet board | ivory-silver / graphite | violet | gold capture / red check |
| Go | violet board + thin metallic grid | black / white stones | violet | gold last move |
| Domino | dark-violet table | ivory tiles / graphite pips | violet | gold selected/latest |

---

# 11. What stays mechanically untouched

For all eight games:

- board dimensions;
- legal-move calculations;
- player side assignment;
- win/draw/loss detection;
- setup rules;
- timer values;
- server clock ownership;
- readiness;
- surrender/rematch availability;
- action submission;
- animations triggered by authoritative state;
- responsive geometry unless a later implementation proves a single shared visual accommodation is required.

---

# 12. Manual visual acceptance gate

DS-4 visual acceptance should evaluate a **single meaningful board-family review**, not eight rounds of icon work.

The review should confirm:

- all eight clearly belong to Shield King;
- each game remains immediately recognizable;
- no decorative green board remains in Reversi/Domino;
- Four in a Row is no longer generic blue/red/yellow branding;
- Chess/Go no longer look like unrelated brown apps;
- Checkers pieces remain black vs gold;
- Reversi/Go remain black vs white;
- tactical states remain readable;
- game mechanics and geometry are unchanged.

# END
