# Shield King — Existing Auxiliary Surfaces Migration

## Status

`DS-3 AUXILIARY SURFACES / VISUAL-ONLY MIGRATION`

This file covers existing Store, Notifications, sheets/modals, menu/rules/history and related economy surfaces.

The existing component geometry and functional ownership remain unchanged.

---

# 1. Store

Preserve all existing store owners, scroll behavior, country tabs, product cards, denomination controls, footer and runtime data.

## Surface mapping

- `.store-loading`, `.store-empty` → Shield King standard empty/loading surface;
- `.store-balance-card` → Gold-semantic dark card, not a bright gold panel;
- `.store-note` → standard information surface;
- `.store-country-tab` → existing size/scroll behavior, Shield King tab states;
- `.store-product-card` → standard dark card;
- `.store-product-card.active` → controlled gold border/tint because the selected store item is in an economy/purchase context;
- `.store-product-visual` → retain exact 62×62 owner and image behavior, replace old mixed generic purple/gold background with restrained Shield King surface;
- `.store-denomination` → preserve dimensions and 2-column grid;
- `.store-denomination.active` → controlled Gold semantic selection;
- `.store-selection-summary` → standard dark summary card.

Do not change catalogue order, price data, eligibility, purchase logic or availability.

Any old green `available` text may remain semantic success/availability green; it must not spread into decorative store branding.

---

# 2. Notifications

Preserve current notification badge, list/card geometry, severity classes, actions, top toast behavior, drag behavior and responsive sizes.

## Notification bell

The existing `#notificationsOpen` action remains.

Replace the current emoji bell visual with the accepted compact metallic Notifications icon.

Unread badge remains a separate red/error layer; do not bake it into the icon asset.

## Severity mapping

Existing semantics remain exactly:

```text
success
warning
danger/error
info
```

Map them to Shield King semantic tokens:

- success → `#48D6A5` only for actual success;
- warning → `#F2B84B`;
- danger/error → `#FF617D`;
- info → Shield King violet/info treatment.

Do not recolor all notification cards purple if their severity is authoritative.

## Notification icon slots

Keep current `42×42` / narrow `36×36` component geometry.

Replace emoji/generic glyphs with accepted compact status icons when exact final icon art is exported.

No royal game frame.

---

# 3. Bottom sheets / modal overlay

Preserve existing:

- `.overlay` full-screen owner;
- bottom alignment;
- `12px` outer padding;
- `.sheet` max-width `440px`;
- existing `max-height` and internal scrolling;
- current low-height adaptations;
- current stable spacing system;
- current setup-scroll behavior;
- current choice grids/buttons.

## Shield King visual mapping

Overlay:

- dark neutral scrim using the Shield King overlay token.

Sheet:

- base `#17121F` / elevated `#0C0F14`;
- optional deep-violet `#231942` accent zones;
- border `#342A43`;
- standard Shield King modal shadow;
- keep current radius/geometry unless a single shared component token maps it without layout change.

## Close button

Keep current `38×38` owner and low-height `34×34` fallback.

Replace text `×` presentation with the accepted compact metallic Close icon when exact art is exported.

No shield frame.

---

# 4. Menu rows

Keep `.menu-list` / `.menu-item` structure, heights, actions and order.

Visual migration only:

- standard rows → dark card/control surface;
- hover/pressed → restrained violet tint;
- danger row → error semantic treatment;
- left-side icons, where present, use compact application icon family without royal game frame.

Do not add or remove menu destinations in DS-3.

---

# 5. Rules surfaces

Keep current `.rules-block` / `.rules-content` scrolling, copy, headings and button ownership.

Map only:

- surface;
- border;
- text hierarchy;
- compact rules/info icon visual.

Game rules content itself is not redesigned or rewritten by this workstream.

---

# 6. History

Preserve current tabs, sections, item rows, responsive geometry and transaction/match data.

Shield King migration:

- neutral history cards → standard dark surfaces;
- selected tab → primary violet;
- positive amount/result → semantic success only;
- negative amount/result → semantic error/destructive only;
- neutral result → silver/muted;
- economy-related Gold values may use controlled Gold semantics where the data itself is Gold.

No new history categories or data are introduced.

---

# 7. Gold top-up / economy sheets

Existing top-up calculators, plans, balances, controls and purchase flows remain untouched functionally.

Gold is a valid dominant semantic accent inside these economy-specific surfaces, but still sits on dark Shield King backgrounds.

Do not turn global application chrome gold.

---

# 8. Empty / loading / error states

Existing functional state ownership remains.

Visual mapping only:

- loading → Shield King loading surface; no fake percentage;
- empty → neutral dark surface + accepted compact semantic icon;
- error → error border/icon/copy hierarchy;
- retry buttons → current action owner with Shield King button skin.

DS-5 will define final loading/preparation/system-state motion and Phase B presentation; DS-3 does not become a second lifecycle owner.

---

# 9. Global auxiliary-surface prohibition

Do not:

- convert sheets to new full-screen pages for aesthetics;
- change store/list grids because a concept board used a different layout;
- add shield frames around normal notification/menu/store icons;
- change current action order or copy without a separate product requirement;
- replace semantic success/error/warning colors with one purple brand color;
- alter backend/economy/notification/history ownership.

# END
