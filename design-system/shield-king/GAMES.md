# Shield King — Eight-Game Visual System

## Status

`DS-4 PASS — CURRENT GAMEPLAY PRESERVED / COLOR-ONLY WHERE SAFE`

The product owner explicitly rejected a broad Shield King redesign of the live gameplay boards on 2026-08-10.

That rejected board-family concept is not part of this design system and must never be used as an implementation reference.

---

# 1. Authoritative DS-4 rule

The current accepted gameplay boards, geometry, pieces, spacing, responsive behavior and interaction cues remain the source of truth.

Shield King V1 does **not** redesign the eight live game boards.

Allowed future integration work is limited to safe visual token substitutions that do not reduce game readability or alter game identity.

```text
CURRENT ACCEPTED GAMEPLAY
+ SAFE SHIELD KING COLOR SUBSTITUTIONS WHERE THEY FIT
= DS-4
```

Not:

```text
CURRENT GAMEPLAY
→ NEW BOARD ART DIRECTION
→ NEW PIECES
→ NEW GEOMETRY
```

---

# 2. Global gameplay rule

For all eight games:

- preserve current board dimensions and aspect ratios;
- preserve current cell/point/tile geometry;
- preserve current piece shapes;
- preserve current legal-move indicators;
- preserve current selected/last-move/win/capture indicators;
- preserve current responsive layout and compact-height rules;
- preserve all gameplay semantics;
- preserve existing game-specific colors if changing them would make the game less readable or less recognizable.

A Shield King color change is optional, never mandatory.

If a game-specific surface does not adapt cleanly to the shared brand palette, leave that surface exactly as currently accepted.

---

# 3. Safe color migration hierarchy

At the future integration gate, attempt visual changes in this order:

1. shared gameplay shell/background around the board;
2. shared headers, player cards, timer containers and generic controls;
3. borders/separators outside the actual board;
4. non-semantic decorative accents;
5. board colors only if contrast and recognition remain clearly equal or better.

Stop before changing a game surface if the result is visually worse.

No game is required to use the same board palette as another game.

---

# 4. Game-by-game rule

## Tic Tac Toe

Keep the current 3×3 geometry, X/O presentation and interaction states.

Safe shared-shell color changes are allowed. Do not redesign the live board to imitate the catalogue icon.

## Four in a Row

Keep the current board geometry, holes, player-disc colors, last-move and winning states unless a simple color substitution is clearly better in the real UI.

Do not force a new silver/gold player-color system.

## Battleship

Keep the complete current coordinate-board system, placement flow, fleet picker, hit/miss/sunk semantics, tabs and compact mobile geometry.

Game-semantic colors remain game-owned. Only surrounding shared UI may be safely skinned.

## Checkers

Keep the current checkerboard, pieces, kings, legal-move dots, capture indicators and animations.

No requirement to turn live gameplay pieces black/gold merely because the game-card icon uses that art direction.

## Reversi

Keep the current board, black/white discs, legal markers, flip animation and scoring.

The live board may remain green if that is clearer and already accepted. Shield King does not require recoloring it violet.

## Chess

Keep the current board palette, pieces/glyphs, move dots, capture ring, check state and promotion UI unless a safe token-only change is demonstrably better.

No board redesign.

## Go

Keep the current wooden board identity, grid, star points, black/white stones, territory markers and scoring.

Do not recolor the board violet just for branding consistency.

## Domino

Keep the current table, adaptive chain layout, tiles, hand, stock, placement targets and density-responsive behavior.

The live table may remain green if that remains the clearest accepted gameplay surface.

---

# 5. Shared gameplay shell

The shared application UI surrounding a game may receive Shield King skinning because it is not the game board itself:

- app/screen background;
- player cards;
- turn container;
- timer container;
- rules action;
- generic leave/menu action;
- generic modal/sheet surfaces.

Even there, preserve current dimensions, hierarchy, text and runtime ownership.

---

# 6. Rejected DS-4 board-family concept

The generated 8-board Shield King concept created during this workstream was explicitly rejected by the product owner.

It must not be stored, cited, recreated or treated as a future visual reference.

Reason:

- it did not match the accepted live fields;
- it introduced unnecessary reinterpretation;
- it risked damaging already-working gameplay presentation.

Durable lesson:

`Do not redesign a working game board merely to make it look more branded.`

---

# 7. Acceptance

```text
8/8 games covered
current gameplay visuals preserved
color-only adaptation allowed where safe
game-specific visual identity may remain unchanged
no mechanics modified
rejected board redesign discarded
DS-4 PASS
```
