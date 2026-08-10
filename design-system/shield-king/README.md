# Mini Games World — Shield King Design System V1

## Status

```text
DS-0 FOUNDATIONS / TOKENS — PASS
DS-1 COMPONENT LIBRARY — PASS
DS-2 EXACT ICON SYSTEM — PASS / MANUALLY ACCEPTED
DS-3 SCREEN SPECIFICATIONS / EXISTING-UI MIGRATION — PASS
DS-4 EIGHT-GAME VISUAL SYSTEM — SPEC READY / AWAITING MANUAL BOARD-FAMILY ACCEPTANCE
DS-5 LOADING / SYSTEM STATES / HANDOFF — NOT STARTED
```

This directory is the isolated design-system owner for the approved **Shield King** shared-product visual direction.

It is design/specification work only. Nothing here owns or changes runtime behavior, Telegram/Web application logic, Android shell behavior, backend/API contracts, database/storage, matchmaking, economy, game engines, readiness, timers, polling, staging, main, or production.

## Source of truth

This workstream is based on the frozen Android Branding Pack:

- branch: `agent/android-branding-pack`
- exact accepted SHA: `4f110277c85df4c77d9a66b794ff620812c16d2d`
- approved identity: **Shield King / MGW**

Durable inputs:

- `android-app/docs/BRANDING.md`
- `android-app/docs/FULL_APP_REDESIGN_HANDOFF.md`
- `android-app/docs/FINAL_BRANDING_ACCEPTANCE.md`

## Non-negotiable brand rule

The primary Shield King mark is centered shield + centered crown + metallic `MGW` on a near-black base.

Forbidden behind the mark:

- white/silver offset backplate;
- pale duplicate plate;
- duplicated shifted shield silhouette;
- asymmetric glow blob that visually moves the mark off-center.

## DS-0 — PASS

- `FOUNDATIONS.md`
- `TOKENS.md`
- `tokens.json`

## DS-1 — PASS

- `COMPONENTS.md`
- `COMPONENT_STATES.md`

## DS-2 — PASS / manually accepted

The product owner accepted the rich metallic **Variant 1** family after iterative review on 2026-08-10.

Frozen visual direction:

- ordinary application icons are compact standalone metallic/silver glyphs with restrained purple/gold detail and **no large shield frame**;
- all eight game icons use one identical crowned royal frame, width and height;
- game art uses the accepted metallic/dark-violet/gold finish;
- Tic Tac Toe has no redundant nested black panel;
- Four in a Row uses two player colors only;
- Checkers uses a coherent board with black vs gold pieces;
- Reversi uses black/white discs only and no green field;
- Chess uses the same crown/frame/width as all other games;
- Go uses black/white stones only;
- Domino uses the same external frame width.

The earlier simplified geometric SVGs are semantic/geometry references only and must not override the accepted rich visual family.

No further product-owner icon review is required unless a future integration reveals a real asset defect.

## DS-3 — PASS

Authoritative files:

- `CURRENT_UI_MIGRATION.md`
- `CURRENT_UI_STYLE_MAP.md`
- `EXISTING_SCREEN_MIGRATION.md`
- `EXISTING_AUX_SURFACES_MIGRATION.md`
- `SCREEN_STATE_MATRIX.md`
- `SCREENS.md` only where it does not conflict with the preserve-existing-UI migration rule.

The current accepted shared Mini Games World UI already owns Home, Search, Gameplay, Profile, Store, Notifications, sheets, history and their interactions.

Shield King is applied as a **skin/migration**:

```text
existing accepted layout + existing actions + existing copy + existing responsive rules
→ Shield King colors/tokens
→ accepted icons
→ Shield King component states
```

It is NOT permission to rebuild Home, reorder sections, add new navigation, invent blocks or replace the current product structure with a concept mockup.

Exact existing Home structure remains preserved:

- topbar/profile/online state;
- notification and more-menu actions;
- “Мировые мини-игры” hero;
- `Матч-комната / Gold-комната` selector;
- runtime room card and buttons;
- two balance cards;
- live-activity block;
- eight existing game cards;
- rules / `Играть` / `Пригласить друга` actions.

No bottom navigation, tournament section, replacement game grid or giant marketing hero is introduced.

## DS-4 — specification ready / visual gate pending

Created:

- `GAME_COMPONENTS.md`
- `GAMES.md`

Shared gameplay shell now defines:

- common player cards;
- turn states;
- timer states;
- board wrapper language;
- selection/legal/capture/invalid/last-action states;
- event banners;
- shared result transition;
- accessibility and reduced-motion rules.

All eight games now have explicit visual contracts without mechanics changes:

1. Tic Tac Toe — deep-violet board, silver X / gold O;
2. Four in a Row — royal-violet frame, silver / gold discs;
3. Battleship — dark naval violet, steel ships, silver miss / gold hit / red sunk;
4. Checkers — silver-violet board, black / gold pieces;
5. Reversi — dark-violet board, black / white discs, no green;
6. Chess — silver-violet board, silver/ivory vs graphite pieces;
7. Go — dark-violet board with metallic grid, black / white stones;
8. Domino — dark-violet table, ivory tiles, no green felt.

The current runtime geometry and mechanics remain authoritative.

## Ownership

Persistent changes for this workstream belong only under:

`design-system/shield-king/**`

Future integration into the real shared UI must happen through a separate main-roadmap compatibility/integration gate. This branch must never be blindly merged into future runtime code.
