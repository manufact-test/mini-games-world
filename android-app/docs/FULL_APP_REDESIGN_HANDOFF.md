# MGW Shield King — Full App Redesign Handoff

## Status

`DESIGN DIRECTION APPROVED / IMPLEMENTATION DEFERRED TO MAIN MGW ROADMAP`

This document records the approved visual direction created during the isolated Android Branding Pack. It is a product-design handoff only. It does not authorize rewriting the shared Mini Games World web/Telegram UI from this branch.

## Approved direction

Name: **Shield King**

Character:

- very dark premium game atmosphere;
- deep violet rather than bright generic purple;
- gold crown accents used selectively;
- silver/neutral highlights for legibility;
- shield/crown motif as the primary brand mark;
- subtle retro-neon/pixel/game references without turning the product into a noisy arcade skin;
- rounded dark cards with controlled glow, not bright glassmorphism everywhere;
- game identity should feel competitive, premium and coherent across all eight core games.

## Core palette

```text
#0C0F14  launcher / deepest shell background
#080B12  splash / near-black background
#17121F  dark brand surface
#231942  deep violet surface
#6A4CFF  primary purple
#A65FF7  violet highlight
#FFD45C  crown / premium gold
#E6E8EF  silver / primary light neutral
#FFFFFF  white where maximum contrast is needed
```

The old brighter flat-purple `MG` tile is not part of the approved final direction.

## Approved brand mark

Primary mark:

`dark shield + premium crown + deep violet inner field + gold accents`

Android launcher/splash implementation is already present in the isolated Branding Pack branch.

## Full shared-product redesign scope for a future main-roadmap MVP

The main MGW chat must place the following work into the earliest safe UI/app-shell MVP after current runtime-critical work is stable. The redesign must be implemented once in the shared product UI, not separately for Android.

### 1. Global app shell

- background and page surfaces;
- top bar;
- profile mini-card;
- navigation;
- buttons;
- chips/tabs;
- cards;
- modal/sheet chrome;
- badges;
- empty/error states;
- icon system;
- typography hierarchy;
- spacing/radius/shadow/glow tokens.

### 2. Home screen

- home header;
- hero block;
- Match / Gold room selector;
- room cards;
- balances;
- current activity;
- game cards;
- rules entry points;
- primary and secondary CTA hierarchy.

### 3. Game catalogue / game selection

Create coherent Shield King visual treatment for:

1. Tic Tac Toe
2. Four in a Row
3. Battleship
4. Checkers
5. Reversi
6. Chess
7. Go
8. Domino

Each game may have its own icon/motif, but all must remain in the same family.

### 4. Profile

- avatar framing;
- rank/level treatment when such systems become authoritative;
- stats cards;
- achievements entry;
- settings/profile actions;
- balances and history surfaces.

Do not invent new backend/profile features merely to match a concept image.

### 5. Store

- catalogue cards;
- premium/gold accents;
- purchase buttons;
- avatar/cosmetic previews;
- insufficient-balance states;
- transaction/result messaging.

Implementation must wait for the corresponding economy/store contracts in the main roadmap.

### 6. Notifications / friends / social surfaces

Apply the same visual system when these product surfaces are implemented or refreshed in the main roadmap.
Do not create fake social functionality as part of design work.

### 7. Settings

- account/settings rows;
- notification settings;
- language/theme/legal/help sections when authoritative functionality exists;
- destructive action styling.

### 8. Loading / preparation / synchronization UI

This is a high-priority redesign target.

All app-level loading and synchronized match-preparation states should be restyled to Shield King once the current Phase B lifecycle is accepted.

Requirements:

- global layer above the whole application when product contract requires it;
- dark MGW background;
- Shield King mark / subtle crown-shield motion or restrained branded loader;
- no technical wording such as synchronization/device counters;
- same authoritative state for both participants;
- shared 3→2→1 countdown remains server-authoritative;
- visual redesign must not introduce a second timing/state owner;
- no fake local progress used to hide backend latency;
- preparation timeout/result states use the same visual family.

### 9. Result / win / loss / draw / timeout states

- victory can use controlled gold accent;
- neutral/draw states remain restrained;
- loss should not become hostile/red-heavy unless design system explicitly supports it;
- preparation timeout remains distinct from draw.

### 10. Android-specific platform chrome

Already handled by the isolated Branding Pack:

- launcher icon;
- adaptive icon;
- monochrome icon;
- native splash/startup branding.

Do not duplicate shared UI natively just to match these resources.

## Implementation rule for the main roadmap

Correct sequence:

```text
current runtime-critical Phase/MVP work
→ safe UI/app-shell integration point
→ audit current shared UI contracts
→ introduce reusable Shield King design tokens/components
→ migrate screens systematically
→ focused visual/functional checks
→ full regression
→ real Telegram acceptance
→ Android shell inherits the same shared UI automatically
```

Do not perform random screen-by-screen CSS patches.
Do not fork Android and Telegram visual implementations.
Do not let branding changes alter game/auth/economy lifecycle ownership.

## Main-chat status to record

```text
SHIELD KING FULL APP REDESIGN:
DESIGN READY / APPROVED

IMPLEMENTATION:
NOT STARTED

PLACEMENT:
TO BE ASSIGNED TO THE EARLIEST SAFE MAIN-ROADMAP UI/APP-SHELL MVP

DEPENDENCY:
CURRENT RUNTIME-CRITICAL WORK MUST NOT BE DESTABILIZED
```

## Visual reference note

The design direction was approved from concept boards created in the project chat on 2026-08-09. The durable source of truth for future implementation is this written specification plus the Android Shield King resources in `agent/android-branding-pack`.

# END OF FULL APP REDESIGN HANDOFF
