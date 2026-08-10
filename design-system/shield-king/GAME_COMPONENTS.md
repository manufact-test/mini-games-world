# Shield King — Shared Gameplay Components

## Status

`DS-4 PASS — SHARED SHELL SKIN ONLY / LIVE BOARDS PRESERVED`

This file defines the optional Shield King visual treatment around the existing gameplay surfaces.

It does not own game rules, clocks, matchmaking, readiness, server state, action validity, settlement, board geometry or piece rendering.

---

# 1. Preserve the current gameplay shell geometry

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

Shield King may change only the safe visual layer.

---

# 2. Shared-shell palette

Where a shared application component can be recolored without affecting gameplay readability, use the existing Shield King tokens:

```text
screen background: #080B12
player/shared card: #17121F
secondary shared surface: #231942
border: #342A43
primary text: #FFFFFF
secondary text: #C7C3D1
muted text: #918A9E
primary violet: #6A4CFF
violet highlight: #A65FF7
gold: #FFD45C
success: #48D6A5
warning: #F2B84B
error: #FF617D
```

These colors are **not a mandate for the live game board itself**.

---

# 3. Turn indicator

Keep current size, position, copy and runtime ownership.

Safe visual migration:

- neutral/shared turn surface → dark Shield King card;
- current-player emphasis → violet if it remains clear;
- opponent/waiting → neutral dark/silver;
- true success/ready → semantic success;
- warning → semantic warning;
- error/invalid → semantic error.

If the current game's event color carries important established semantics, preserve it.

---

# 4. Timer

The server/runtime remains the clock owner.

Keep exact current timer geometry and values.

Only the timer container may receive a safe shared skin:

- normal → dark/silver;
- warning → warning token when runtime already exposes that state;
- expired → error token when runtime exposes expiration.

Never invent a threshold or countdown visually.

---

# 5. Player cards

Keep the current player layout, content and state ownership.

Optional shared skin:

- base card → dark Shield King surface;
- active/current side → restrained violet border if clearly readable;
- opponent/inactive → neutral border;
- actual online/presence → semantic success only when authoritative data says so.

Do not infer online from turn state.

---

# 6. Board container

The board wrapper may use a neutral Shield King surrounding surface only when the current game already uses an outer wrapper.

Rules:

- never reduce usable board size;
- never add ornate royal frames around live gameplay;
- never change aspect ratio;
- never add decorative content over the board;
- never recolor the live board simply for global consistency;
- no per-game geometry patches.

---

# 7. Interaction states

Existing game-specific interaction markers remain authoritative.

Do **not** globally replace legal-move, selected, capture, last-action or invalid colors if that damages a game's established readability.

A future integration may map a marker to Shield King violet/gold only when the substitution is simple, safe and visually better on the real screen.

No behavior or target changes are allowed.

---

# 8. Event banners

Keep current messages, triggers, dimensions and game-specific semantics.

Shared card materials may be skinned; semantic color meaning must remain clear.

Do not force every `your turn` banner, capture banner or game event into one universal color if the current implementation is clearer.

---

# 9. Motion

Preserve existing gameplay motion and reduced-motion behavior.

Do not add perpetual brand animation to boards, pieces or timers.

No animation may delay authoritative rendering or action submission.

---

# 10. Result transition

Use the existing result flow and current game/runtime ownership.

Shared result card surfaces may use Shield King tokens, but:

- win/loss/draw semantics remain current;
- rematch/menu actions remain current;
- no game-specific new result flow is invented.

---

# 11. Accessibility

Any color-only change must preserve or improve:

- piece/background contrast;
- selected/legal/invalid distinction;
- timer readability;
- black/white piece edge contrast;
- reduced-motion understandability.

If a Shield King color substitution fails this test, keep the current game color.

---

# 12. Integration prohibition

Do not:

- change server clock ownership;
- change legal-move calculations;
- change action submission;
- change board coordinate systems;
- move controls for aesthetics;
- invent new surrender/rematch behavior;
- recolor every board into violet;
- use the rejected DS-4 board-family concept.

`GAME_COMPONENTS.md` is a safe shared-shell skin contract, not a gameplay redesign contract.
