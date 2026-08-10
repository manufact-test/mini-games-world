# Shield King — Shared Gameplay Components

## Status

`DS-4 SHARED GAMEPLAY SHELL — READY`

This file defines the visual layer shared by all eight games.

It does not own game rules, clocks, matchmaking, readiness, server state, action validity or settlement.

---

# 1. Preserve the current gameplay shell

The existing runtime already owns:

```text
#screen-game
.game-header
.turn-box
#turnText
[data-game-rules-current]
#timerText
#playersRow
.game-player
.board-wrap
#gameBoard
#leaveGame
```

All existing responsive geometry, board sizing, compact game-specific overrides and touch behavior remain authoritative.

Shield King only changes the visual layer.

---

# 2. Shared shell palette

```text
screen background: #080B12
sticky header fade: #080B12 → transparent
player card: #17121F
secondary player/card surface: #231942
border: #342A43
primary text: #FFFFFF
secondary text: #C7C3D1
muted text: #918A9E
active violet: #6A4CFF
violet highlight: #A65FF7
gold: #FFD45C
deep gold: #D69A32
success: #48D6A5
warning: #F2B84B
error: #FF617D
```

Green is semantic only. It is not the general active-turn brand color.

---

# 3. Turn indicator

## Normal current-player turn

Use:

- dark-violet `#231942` surface;
- `#6A4CFF` border/emphasis;
- white primary text;
- restrained violet glow only.

Do not use bright green simply to mean “your turn”.

## Opponent turn / waiting handoff

Use:

- `#17121F` surface;
- `#342A43` border;
- muted/silver text;
- optional very subtle violet edge.

## Success / ready

Only when runtime state genuinely means success/ready:

- semantic `#48D6A5` accent;
- no permanent green shell.

## Warning

- `#F2B84B` border/icon/text accent;
- dark base remains.

## Error / invalid action

- `#FF617D` accent;
- short state feedback only;
- no persistent full-screen red treatment.

---

# 4. Timer

Current runtime/server remains the clock owner.

## Active

- dark elevated pill;
- silver/white tabular numbers;
- controlled violet border.

## Waiting / handoff

- muted silver text;
- neutral border;
- no countdown invention.

## Warning threshold

- warning gold `#F2B84B`;
- no animation unless current product already supports it or a future visual-only animation is explicitly integrated without changing timing logic.

## Expired

- error accent `#FF617D`;
- expired state is visual reflection of authoritative runtime only.

---

# 5. Player cards

Keep current two-player layout and all runtime content.

## Current player / active side

- violet border;
- subtle violet inner/outer ring;
- no green active outline.

## Opponent / inactive

- standard dark card;
- silver/muted hierarchy.

## Online/presence

Green may appear only where actual presence data owns it.

Do not infer online state from whose turn it is.

---

# 6. Board container

The board wrapper must visually connect all eight games without flattening their identities.

Shared rules:

- keep current width/aspect-ratio/runtime geometry;
- near-black surrounding shell;
- subtle `#342A43` border where the current game uses a wrapper;
- no giant ornamental game-icon frame around the live board;
- no decorative element may reduce usable board size;
- no per-device pixel patch;
- no game-specific outer width changes.

Game-specific material starts inside the gameplay surface itself.

---

# 7. Shared interaction language

## Selected piece/cell

Primary selection language:

- `#6A4CFF` / `#A65FF7` outline or inset ring;
- small scale/elevation only where current UI already animates selection;
- never change hit target or board coordinates.

## Legal move / valid target

Default Shield King legal-move hint:

- compact violet/silver marker;
- enough contrast on the game board;
- no broad green unless the game already relies on semantic success meaning.

Recommended base:

```text
marker core: #A65FF7
halo: rgba(106, 76, 255, .22)
```

## Capture / premium-impact target

Use controlled gold:

```text
#FFD45C / #D69A32
```

Gold means a meaningful tactical/impact state, not every normal selectable cell.

## Invalid target

- short `#FF617D` flash/ring;
- preserve current action rejection ownership.

## Last action

Use a common readable convention:

- previous/source → subtle silver/violet inset;
- final/destination → brighter violet or restrained gold depending on game context;
- never obscure the actual piece.

---

# 8. Event banners

All games currently expose event/turn banners in different colors.

Normalize semantics:

```text
your-turn / active: violet
opponent / neutral: dark neutral
capture / important action: gold
warning / forced action: warning gold
success / ready: semantic green
error / invalid / sunk-loss event: semantic red where appropriate
finished: neutral + result component
```

The message text and event trigger remain runtime-owned.

---

# 9. Motion

Keep motion short and tactical.

Allowed visual patterns:

- piece land;
- flip;
- capture burst;
- promotion pulse;
- last-move highlight;
- legal-target pulse;
- result transition.

Rules:

- no perpetual decorative board animation;
- no animation that hides state;
- respect `prefers-reduced-motion`;
- no animation may delay authoritative action submission or rendering.

---

# 10. Common result transition

All eight games use one shared MGW result language.

Result surface must support:

```text
win
loss
draw
technical/timeout result when runtime exposes it
rematch action when runtime supports it
return/menu action
```

Visuals:

- win → dark card + controlled gold/violet emphasis;
- loss → neutral dark card, restrained error semantic only where useful;
- draw → silver/neutral;
- do not create a unique full-screen result language per game.

Game identity may appear as the game icon/name, but the result component is common.

---

# 11. Accessibility

- selected/valid/invalid states cannot rely on color alone;
- maintain outline/shape/pulse differences;
- preserve readable contrast for timer and event messages;
- black/white pieces require visible edge contrast against dark/light board cells;
- reduced-motion mode remains fully understandable.

---

# 12. Integration prohibition

Do not:

- change server clock ownership;
- change legal-move calculations;
- change action submission;
- change board coordinate systems;
- move game controls for aesthetics without a separate product requirement;
- invent new surrender/rematch behavior;
- make one game use a different shell width;
- use decorative green as a global active state.

# END
