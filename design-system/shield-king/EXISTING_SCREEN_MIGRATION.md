# Shield King — Existing Screen Migration Details

## Status

`DS-3 EXISTING SCREENS / VISUAL-ONLY MIGRATION`

This document continues `CURRENT_UI_MIGRATION.md` and `CURRENT_UI_STYLE_MAP.md` for screens that already exist in the accepted shared application.

Core rule for every section below:

```text
KEEP DOM / KEEP ACTIONS / KEEP RESPONSIVE GEOMETRY / KEEP RUNTIME OWNERS
CHANGE VISUAL TOKENS + ICON ART ONLY
```

---

# 1. Existing Search screen

Current owners to preserve:

```text
#screen-search
.page-head
.page-title
.page-sub
#cancelSearch
.search-wrap
.radar
.vs-cards
.player-card
#searchMeAvatar
#searchMeName
.vs
#changeSearch
```

## Shield King migration

### Header

Keep exact current hierarchy and close action.

Replace generic `×` presentation with accepted compact metallic Close icon treatment when implementation reaches the icon migration gate; action/hit target remain unchanged.

### Radar

Keep current `152×152` geometry, animation owner and placement.

Visual change:

- remove cyan/green decorative sweep as a brand color;
- primary sweep → controlled violet `#6A4CFF` / `#A65FF7`;
- dark base → `#0C0F14` / `#17121F`;
- rings → `#342A43` / silver alpha;
- if a semantic ready/success indication is needed, green may appear only at the authoritative success stage.

Do not change search timing or matching behavior.

### Player cards

Keep current 2-player card geometry and central VS slot.

Map surfaces to Shield King cards and avatar treatment.

No rank/level/badge may be invented.

### Exit search

Keep current full-width ghost button and copy.

Map to Shield King secondary/ghost component only.

---

# 2. Existing Gameplay shell

Current owners to preserve:

```text
#screen-game
.game-header
#matchMeta
.turn-box
#turnText
.turn-actions
[data-game-rules-current]
#timerText
#playersRow
.game-player
.board-wrap
#gameBoard
#leaveGame
```

All existing compact Battleship-specific responsive rules and other game-specific board geometry remain authoritative until DS-4 maps each game visually.

## Turn box

Current green+purple decorative gradient must become Shield King semantic treatment.

Default active-turn visual:

- deep-violet surface;
- violet emphasis for current authoritative turn;
- white/silver text;
- no broad green background.

Green is reserved for true success/ready semantics, not generic “your turn” branding.

## Timer

Keep exact size, position, tabular-number behavior and runtime owner.

Normal timer:

- dark elevated surface;
- silver/white numeric text;
- restrained violet border/accent.

Warning threshold:

- use warning token only when authoritative UI state says warning.

Expired:

- use expired/error visual contract without changing clock ownership.

## Player cards

Keep `.players-row` two-column geometry and current player data.

Active player:

- violet border / controlled violet halo;
- do not use permanent green active border.

Presence/online status may use semantic green only when product data owns that state.

## Board wrapper

Keep all current widths, `clamp(...)` sizing, gaps and mobile-height breakpoints.

Shield King adds visual treatment around the board but must not alter board mechanics or fit logic.

DS-4 owns game-specific board colors/pieces; DS-3 only maps the shell.

## Rules / leave action

- current rules button owner stays;
- current `В меню` button owner stays;
- only visual/icon skin changes.

---

# 3. Existing Profile screen

Current owners to preserve:

```text
#screen-profile
.page-head
.page-title / .page-sub
[data-back-home]
.profile-card
.profile-main
#profileAvatar
#profileName
#profileDate
#profileStats
.profile-overview
```

Runtime-created overview/wallet/order rows remain product-owned.

## Profile card

Keep current padding, radius and information hierarchy.

Visual migration:

- base surface → Shield King card;
- border → Shield King border;
- avatar → dark-violet/metallic treatment;
- primary identity → white/silver hierarchy;
- supporting metadata → muted token.

## Stats grid

Keep current 2-column layout and responsive behavior.

No new stats, ranks, XP or achievements may be invented.

## Wallet cards

Current Match wallet:

- preserve location/data;
- replace old bright-purple treatment with restrained Shield King violet.

Current Gold wallet:

- preserve location/data;
- migrate to `#FFD45C` / `#D69A32` semantic treatment.

### Shop-available card

The current green decorative wallet card must not remain green merely because the old stylesheet uses a green gradient.

Use a neutral/deep-violet Shield King information surface unless the state itself semantically means success.

The value/data is unchanged.

## Orders action

Keep current action and layout.

Gold accents remain valid because this is an economy/store context.

Replace emoji/generic icon treatment with the accepted compact economy/store icon family when exact production icon art is available.

---

# 4. Existing shared buttons

Current button geometry is already mature and should be preserved:

```text
.btn min-height: 48px
border-radius: 16px
existing padding
existing pressed transform
existing 2-column .btn-row layout
existing full-width behavior
```

Only visual mapping changes.

## `.btn.primary`

Target:

`linear-gradient(135deg, #6A4CFF 0%, #A65FF7 100%)`

## `.btn.gold`

Target:

`linear-gradient(135deg, #FFD45C 0%, #D69A32 100%)`

## `.btn.ghost`

Target:

- `#17121F` / dark neutral;
- subtle `#342A43` border;
- no unnecessary glow.

## `.btn.green`

Do not use as a general branded CTA in Shield King.

At integration time classify every existing `.btn.green` occurrence by semantics:

- if success/confirmation → map to semantic success;
- if merely legacy primary styling → migrate to primary violet;
- do not alter its action.

## `.btn.danger`

Keep destructive semantics; map to Shield King error/destructive colors.

---

# 5. Existing cards

Preserve current card dimensions, padding and spacing rules.

Visual mapping:

```text
.card / .balance-card / .activity-card / .game-card / .profile-card
→ #17121F base
→ #342A43 border
→ #231942 only for intentional secondary/elevated zones
→ existing shadow geometry, tuned to Shield King opacity
```

Do not convert every card into a glowing royal shield.

The rich royal frame belongs specifically to game artwork, not the entire application component library.

---

# 6. Existing eight game cards

Current `48×48` `.game-icon` slot is a **layout owner**, not a final art-size decision.

At future integration:

1. keep `.game-top` row and all surrounding card geometry;
2. test the accepted rich royal icon at the largest size that fits without moving text/buttons or increasing card height unexpectedly;
3. all 8 use the exact same rendered width/height;
4. if the accepted royal art needs a slightly wider internal visual crop, solve it inside the icon asset/crop, not by making Chess narrower or Domino wider;
5. do not redesign the game card into a new tile/grid.

Any necessary small icon-slot dimensional adjustment is a **single shared component rule for all eight**, never a game-specific patch.

---

# 7. Current responsive behavior

Preserve all current responsive constraints including:

- mobile `max-width` shell behavior;
- board `clamp(...)` sizing;
- narrow-screen game padding;
- low-height game adaptations;
- Battleship compact gameplay shell;
- profile wallet single-column fallback at narrow widths.

Shield King must fit those owners rather than replacing them.

---

# 8. DS-3 completion condition for existing screens

DS-3 existing-screen migration is complete when implementation can map every currently visible surface to:

```text
existing selector/owner
→ exact Shield King token/component
→ exact accepted icon family where applicable
→ no product/layout/function change
```

No visual mockup can override this selector-level contract.

# END
