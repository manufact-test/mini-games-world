# Shield King — Current Shared UI Migration Contract

## Status

`DS-3 AUTHORITATIVE MIGRATION OVERRIDE / PRESERVE EXISTING UI`

This file corrects an earlier DS-3 interpretation that treated Shield King as permission to redesign the information architecture of already accepted screens.

That interpretation is rejected.

For screens that already exist in the accepted shared Mini Games World UI, Shield King V1 is a **visual migration / skin**, not a new layout.

If wording elsewhere in `SCREENS.md` can be read as authorizing a new Home layout, new section order, new navigation destinations or newly invented product blocks, **this file takes precedence**.

---

# 1. Non-negotiable migration rule

```text
CURRENT ACCEPTED SHARED UI STRUCTURE
+ SHIELD KING TOKENS
+ ACCEPTED SHIELD KING ICONS
+ SHIELD KING COMPONENT STATES
= FUTURE VISUAL MIGRATION
```

Not:

```text
Shield King concept
→ invent a replacement Home
→ move existing blocks
→ invent navigation / balances / CTAs
```

Do not add, remove, reorder or reinterpret product functionality merely to make a redesign mockup look cleaner.

The future implementation gate must first inspect the then-current accepted shared UI and preserve its DOM/product structure unless a separate product requirement explicitly changes it.

---

# 2. Current Home baseline to preserve

The existing Home already owns the required product structure.

Preserve the current order and behavior:

```text
existing top bar
→ compact user/profile identity
→ current online/status presentation
→ notification action
→ existing more/menu action where still present in the accepted runtime
→ existing hero: “Мировые мини-игры” + current copy
→ existing Матч-комната / Gold-комната segmented control
→ existing selected room information card
→ existing room actions/buttons
→ existing balances block
→ existing live activity/status block
→ existing game selection/cards
→ existing sheets/modals/flows opened by those controls
```

Do not introduce a new bottom navigation merely because a concept mockup contains one.

Do not invent Tournaments, Dice, Crash, Backgammon or any other destination/game outside the authoritative product catalogue.

Do not restore any retired Telegram persistent Web App/menu-button owner as part of visual work.

---

# 3. What changes on Home

Only the visual layer is redesigned.

## Global background

Current layout geometry stays unchanged.

Map visual surfaces to Shield King:

- app/deep background → `#080B12`;
- elevated shell → `#0C0F14`;
- normal cards → `#17121F`;
- secondary/deep-violet surfaces → `#231942`;
- default border → `#342A43`;
- separator → `#282130`.

Remove broad cyan/green decorative branding from the old visual language.

Green remains allowed only for semantic success/online state.

## Primary interaction

- primary action purple → `#6A4CFF`;
- highlight violet → `#A65FF7`;
- Gold semantics → `#FFD45C` with restrained deep-gold support;
- do not make every selected control gold;
- keep existing control size, placement and behavior.

## Top bar

Keep current top-bar geometry and actions.

Replace generic/emoji-style controls with the accepted compact core icon language.

Ordinary application icons **do not receive the large royal game shield**.

Profile/avatar treatment may be recolored/restyled, but its existing slot and navigation behavior stay unchanged.

## Hero

Keep:

- current placement;
- current title;
- current supporting copy;
- current hierarchy.

Change only surface, color, border, typography treatment and restrained Shield King accents.

Do not turn Home into a giant launcher-logo/marketing splash.

## Room selector

Keep existing two-room interaction exactly:

```text
Матч-комната | Gold-комната
```

Visual migration:

- Match active → primary violet treatment;
- Gold active → controlled gold treatment;
- inactive → current dark/neutral Shield King surface.

Business rules/copy are not owned by this design branch.

## Room card and actions

Keep the current room card, existing buttons, hint/help affordances and functional destinations.

Only map them to Shield King component tokens.

No new CTA hierarchy is allowed if it changes existing behavior.

## Balances

Keep the current two-balance structure and placement.

Replace visual currency marks with the DS-2 economy icon family.

Values remain runtime-owned.

## Live activity

Keep the existing activity block and refresh behavior exactly as the product currently owns it.

Restyle cards/labels/numbers only.

Do not add fake live counts, player counts or activity.

## Game cards

Keep current card layout, copy, buttons and click behavior.

Replace only the old game visual/emoji/placeholder slot with the **accepted royal Shield King game artwork** for exactly these eight games:

1. Tic Tac Toe
2. Four in a Row
3. Battleship
4. Checkers
5. Reversi
6. Chess
7. Go
8. Domino

All eight use one identical external royal frame/crown footprint.

Do not independently resize Chess, Go or Domino.

---

# 4. Accepted icon source rule

The product owner manually accepted the rich metallic **Variant 1** visual family created during DS-2 review.

Accepted core icon character:

- standalone metallic/silver application glyphs;
- no large shield frame around Home/Profile/Games/Store/etc.;
- controlled purple/gold details only.

Accepted game icon character:

- one identical crowned royal frame;
- silver/dark-violet/gold material language;
- detailed game-specific central artwork;
- same width and height for 8/8.

The simplified geometric SVGs created earlier in this branch are **not an adequate visual substitute for the manually accepted rich metallic artwork**.

They may be retained only as semantic/geometry references until the exact accepted production artwork is exported.

They must not be used to judge or preview the final Home skin.

---

# 5. Exact accepted game-art semantics

These corrections are frozen from manual review:

- Tic Tac Toe: one interior field only; no redundant black panel on another background;
- Four in a Row: exactly two player colors;
- Battleship: recognizable premium ship treatment;
- Checkers: coherent checkerboard with real round pieces; black vs gold pieces; no hybrid half-black/half-gold piece;
- Reversi: visually different from Checkers; black/white discs only; no green field;
- Chess: same external crown/frame/width as every other game;
- Go: black/white stones only;
- Domino: same external frame width and crown as every other game.

---

# 6. Existing UI → Shield King mapping

| Existing UI owner | Preserve | Shield King change |
|---|---|---|
| app/body shell | layout, scroll, safe-area behavior | dark background tokens |
| topbar | positions/actions | surfaces + accepted compact icons |
| avatar/user mini | size/navigation/data | dark-violet/silver/gold styling |
| online status | semantics/location | semantic success token only |
| hero | layout/copy | dark-violet surface, restrained accent |
| room segmented control | exact behavior/order | Match violet / Gold gold |
| room card | content/actions | card/border/type/button tokens |
| balances | placement/data | economy icons + Shield King surfaces |
| live activity | placement/data/update owner | visual tokens only |
| game cards | layout/actions/copy | accepted rich royal game artwork + tokens |
| overlays/sheets | behavior/content | Shield King modal/sheet components |
| loading/errors | ownership/state | DS-5 visual treatment only |

---

# 7. Forbidden DS-3 behavior

Do not:

- rebuild Home from scratch;
- change existing block order for aesthetics;
- add a new bottom navigation if the accepted runtime does not own it;
- invent new product sections;
- invent currency values, ranks, achievements or player counts;
- replace accepted rich icons with simplified placeholders in approval mockups;
- use the Android launcher mark as a giant Home hero;
- reintroduce retired Telegram UI controls;
- change runtime/backend/game logic in this workstream.

---

# 8. Future implementation procedure

At the eventual main-roadmap UI integration gate:

```text
inspect exact then-current accepted UI
→ inventory existing classes/components/actions
→ map existing visual tokens to Shield King tokens
→ replace existing icon slots with accepted assets
→ preserve DOM/function owners where possible
→ verify no section/action disappeared or moved unexpectedly
→ focused visual checks
→ full functional regression
→ Telegram manual acceptance
→ Android inherits the same shared UI
```

No screen is allowed to be rebuilt merely because a standalone concept board suggests a different layout.

# END
