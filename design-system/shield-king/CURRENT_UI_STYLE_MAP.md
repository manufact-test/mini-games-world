# Shield King — Current UI Style Map

## Status

`DS-3 CURRENT ACCEPTED UI → SHIELD KING VISUAL MAPPING`

This document is based on the current accepted shared application structure (`app/index.html`) and its existing token/component split. It specifies **visual substitutions only**.

No runtime file is changed by this design branch.

---

# 1. Exact current Home owners to keep

Current Home already contains these exact owners and they remain structurally intact:

```text
.topbar
  .user-mini
  .avatar
  .user-name
  .user-status / .dot-online
  #notificationsOpen
  #moreMenuOpen

.hero
  .hero-title
  .hero-sub

.seg
  [data-room="match"]
  [data-room="gold"]

#roomCard

.balances
  .balance-card ×2

#activityTitle
#activityGrid

[data-game-card="tictactoe"]
[data-game-card="four_in_a_row"]
[data-game-card="battleship"]
[data-game-card="checkers"]
[data-game-card="reversi"]
[data-game-card="chess"]
[data-game-card="go"]
[data-game-card="domino"]

Each game card keeps:
  .game-rules-button
  .game-top
  [data-game-icon]
  [data-game-title]
  [data-game-description]
  .btn.primary "Играть"
  .btn.ghost "Пригласить друга"
```

Do not change this order merely for Shield King styling.

---

# 2. Token migration

Current runtime token → future Shield King token:

| Current runtime token | Current value | Shield King target | Rule |
|---|---|---|---|
| `--bg` | `#090c14` | `#080B12` | deepest shared app background |
| `--bg2` | `#111827` | `#0C0F14` | elevated/shell background |
| `--card` | `#151b2b` | `#17121F` | normal card surface |
| `--card2` | `#1b2336` | `#231942` | secondary/deep-violet surface |
| `--stroke` | white alpha | `#342A43` semantic border | preserve subtle contrast |
| `--text` | `#f7f8fb` | `#FFFFFF` | primary text |
| `--muted` | `#a7b0c2` | `#918A9E` | muted text |
| `--soft` | `#cfd6e6` | `#C7C3D1` | secondary text |
| `--accent` | `#7c5cff` | `#6A4CFF` | primary interaction violet |
| `--accent-dark` | `#5d42df` | deep-violet support | do not introduce new blue-purple |
| `--accent2` | `#2ee6a6` | semantic-only `#48D6A5` | remove as broad decorative brand accent; retain only success/online use |
| `--gold` | `#ffc857` | `#FFD45C` | Gold/premium semantic accent |
| `--gold-dark` | `#e49b25` | `#D69A32` | deep-gold support |
| `--danger` | `#ff5f6d` | `#FF617D` | error/destructive |
| `--warning` | `#ffb020` | `#F2B84B` | warning |
| `--shadow` | current dark shadow | Shield King card/modal shadows | retain depth, reduce generic neon feel |
| `--radius` | `24px` | preserve current geometry unless component migration maps it explicitly | no layout redesign |
| `--radius2` | `18px` | preserve current geometry unless component migration maps it explicitly | no layout redesign |
| `--max` | `460px` | preserve current accepted mobile shell width | do not widen Home for concept art |

---

# 3. Background and shell

Preserve:

- current `.app` max width;
- current screen positioning;
- current scroll behavior;
- current safe-area behavior;
- current responsive breakpoints.

Visual change only:

- remove broad green/cyan ambient glow from the old brand treatment;
- use near-black + deep-violet ambient accents;
- no giant Shield King launcher logo in Home background;
- no casino/synthwave scene replacing the application shell.

---

# 4. Top bar

## Preserve exactly

- profile click target;
- avatar slot size/position;
- username/status layout;
- notification button slot;
- more-menu button slot.

## Replace visually

### Avatar

Current bright purple/green gradient becomes restrained Shield King treatment:

- deep violet base;
- metallic/silver edge or controlled purple edge;
- optional small gold detail only where the avatar design requires it;
- no green decorative gradient.

### Online dot

Keep location and meaning.

Use semantic success only:

`#48D6A5`

This is one of the few valid green uses.

### Notifications

Replace current `🔔` glyph with accepted compact metallic notification icon.

No royal shield frame.

### More menu

Replace current `⋯` presentation with accepted compact metallic More icon treatment if an asset is used; keep the same button/action owner.

No shield frame.

---

# 5. Hero

Current content remains:

- `Мировые мини-игры`;
- current subtitle;
- current hero position/size relationship.

Visual migration:

- dark `#17121F` / `#231942` surface;
- restrained top/side violet accent;
- border based on Shield King border token;
- text uses Shield King typography tokens;
- no green accent blob;
- no extra crown/logo replacing current copy.

---

# 6. Room selector

Current component geometry from `.seg` stays unchanged.

## Match active

Replace current purple treatment with:

`linear-gradient(135deg, #6A4CFF 0%, #A65FF7 100%)`

Use controlled violet shadow/glow only.

## Gold active

Replace current gold treatment with:

`linear-gradient(135deg, #FFD45C 0%, #D69A32 100%)`

Keep readable dark foreground.

## Inactive

Dark-violet/neutral surface + muted text.

No new icons or extra controls inside the segmented selector.

---

# 7. Room card

`#roomCard` keeps all runtime-generated content/actions exactly.

Map:

- card surface → `#17121F`;
- border → `#342A43`;
- secondary area → `#231942` only where current component hierarchy already needs a nested surface;
- primary room CTA → Shield King primary button;
- Gold semantic CTA → gold button only where the current action is genuinely Gold/premium;
- ghost/secondary actions → dark neutral surface.

Do not add or delete room buttons in design work.

---

# 8. Balances

Keep exact `.balances` two-card layout.

### Match balance

Current `🎲` placeholder is replaced by the accepted standard-coins/economy icon treatment.

Do not use a dice/casino symbol as the final semantic coin mark.

### Gold balance

Current `✨` placeholder is replaced by the accepted Gold/premium currency icon treatment.

Gold remains visually distinct, but the entire card does not become bright gold.

Values, labels and availability remain runtime-owned.

---

# 9. Live activity

Keep:

- `#activityTitle`;
- `#activityGrid`;
- hidden/visible ownership;
- all runtime values/update cadence.

Style only:

- dark Shield King cards;
- silver/white hierarchy;
- violet accent for neutral activity emphasis;
- semantic green only for true online/success status.

No fake values.

---

# 10. Eight current game cards

The application already has **exactly eight** game-card owners and those stay.

Every card keeps:

- rules button;
- icon slot;
- game title;
- description;
- `Играть`;
- `Пригласить друга`;
- current click/availability/locked behavior.

## Only visual replacements

### `[data-game-icon]`

Replace current generated/emoji/old visual with the **accepted rich metallic royal Variant 1 artwork**.

Do NOT use the simplified geometric SVGs as the visual approval source.

All 8 art assets use the same external bounding box and same displayed width/height.

### `.game-rules-button`

Keep current slot and `?` behavior.

Visual style may migrate to a compact Shield King info/rules affordance, but its hit target and owner remain unchanged.

### `.btn.primary`

Keep size/position/text/action; map only to Shield King primary CTA visual.

### `.btn.ghost`

Keep size/position/text/action; map only to Shield King secondary button visual.

---

# 11. Search / game / profile screens

Existing current owners already exist in `app/index.html`:

- `#screen-search`;
- `#screen-game`;
- `#screen-profile`;
- `#sheetOverlay` / `#sheet`.

DS-3 must follow the same rule there:

```text
preserve screen structure and controls
→ map surfaces/colors/type/icons/components
→ do not rebuild screen information architecture
```

Detailed per-screen migration mapping can continue after Home skin mapping is accepted.

---

# 12. Explicitly rejected mockup behavior

The following are not part of Shield King DS-3:

- new bottom navigation invented for Home;
- new Home grid replacing current vertically stacked game cards;
- invented balance placement;
- invented Profile header structure;
- invented Play Now hero CTA;
- Tournaments destination;
- Backgammon / Dice / Crash games;
- giant marketing logo occupying the Home hero;
- simplified flat SVG game icons used instead of the accepted rich royal artwork.

# END
