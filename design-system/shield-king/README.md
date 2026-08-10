# Mini Games World — Shield King Design System V1

## Status

```text
DS-0 FOUNDATIONS / TOKENS — PASS
DS-1 COMPONENT LIBRARY — PASS
DS-2 ICON VISUAL FAMILY — MANUALLY ACCEPTED
DS-2 EXACT PRODUCTION ART EXPORT — CORRECTION REQUIRED
DS-3 EXISTING-UI MIGRATION SPEC — READY
DS-3 VISUAL ACCEPTANCE — BLOCKED UNTIL EXACT ACCEPTED ICON ART IS EXPORTED
DS-4 EIGHT-GAME VISUAL SYSTEM — NOT STARTED
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

## DS-2 — visual family manually accepted

The product owner accepted **Variant 1** after iterative review on 2026-08-10.

Accepted direction:

- ordinary application icons are compact standalone metallic/silver glyphs with restrained purple/gold detail and **no large shield frame**;
- all eight game icons use one identical crowned royal frame, width and height;
- game art uses the rich metallic/dark-violet/gold visual finish accepted in the final review board;
- Tic Tac Toe has no redundant nested black panel;
- Four in a Row uses two player colors only;
- Checkers uses a coherent board with black vs gold pieces;
- Reversi uses black/white discs only and no green field;
- Chess uses the same crown/frame/width as all other games;
- Go uses black/white stones only;
- Domino uses the same external frame width.

### Important DS-2 correction

The simplified geometric SVG files created earlier are **not visually equivalent to the manually accepted rich metallic icon artwork**.

They are semantic/geometry references only until exact production art is exported.

They must not be used as the visual source for approval mockups or future Home migration.

The accepted rich Variant 1 visual direction controls the final finish.

## DS-3 — existing UI migration specification ready

Authoritative files:

- `CURRENT_UI_MIGRATION.md`
- `CURRENT_UI_STYLE_MAP.md`
- `EXISTING_SCREEN_MIGRATION.md`
- `EXISTING_AUX_SURFACES_MIGRATION.md`
- `SCREEN_STATE_MATRIX.md`
- `SCREENS.md` only where it does not conflict with the preserve-existing-UI migration rule.

The current accepted shared Mini Games World UI already owns Home, Search, Gameplay, Profile, Store, Notifications, sheets, history and their functional interactions.

Shield King is applied as a **skin/migration**:

```text
existing accepted layout + existing actions + existing copy + existing responsive rules
→ Shield King colors/tokens
→ accepted icons
→ Shield King component states
```

It is NOT permission to rebuild Home, reorder sections, add new navigation, invent blocks or replace current product structure with a concept mockup.

### Exact current Home structure preserved

- current topbar/profile/online state;
- current notification and more-menu actions;
- current “Мировые мини-игры” hero;
- current `Матч-комната / Gold-комната` selector;
- current runtime room card and its buttons;
- current two balance cards;
- current live-activity block;
- current eight vertically stacked game cards;
- current rules / `Играть` / `Пригласить друга` actions.

No bottom navigation, tournament section, new game grid or giant marketing hero is introduced.

### Existing component geometry preserved

- current button heights/radii/grid behavior;
- current card spacing;
- current Search radar/player layout;
- current Gameplay shell/board clamp/responsive behavior;
- current Profile wallet/stat layouts;
- current Store grids/tabs/product cards;
- current Notifications list/toast behavior;
- current sheets/modals/history/economy flows.

Only visual tokens/materials/icons are remapped.

## Ownership

Persistent changes for this workstream belong only under:

`design-system/shield-king/**`

Future integration into the real shared UI must happen through a separate main-roadmap compatibility/integration gate. This branch must never be blindly merged into future runtime code.
