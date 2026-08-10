# Mini Games World — Shield King Design System V1

## Status

```text
DS-0 FOUNDATIONS / TOKENS — PASS
DS-1 COMPONENT LIBRARY — PASS
DS-2 EXACT ICON SYSTEM — PASS / MANUALLY ACCEPTED
DS-3 SCREEN SPECIFICATIONS / EXISTING-UI MIGRATION — PASS
DS-4 EIGHT-GAME VISUAL SYSTEM — PASS / PRESERVE CURRENT BOARDS
DS-5 LOADING / SYSTEM STATES — SPEC READY
FINAL QUALITY AUDIT / HANDOFF — IN PROGRESS
```

This directory is the isolated design-system owner for the approved **Shield King** shared-product visual direction.

It is design/specification work only. Nothing here owns or changes runtime behavior, Telegram/Web application logic, Android shell behavior, backend/API contracts, database/storage, matchmaking, economy, game engines, readiness, timers, polling, staging, main, or production.

## Source of truth

Base/frozen branding input:

- repository: `manufact-test/mini-games-world`
- branch: `agent/android-branding-pack`
- exact accepted SHA: `4f110277c85df4c77d9a66b794ff620812c16d2d`
- approved identity: **Shield King / MGW**

Persistent workstream branch:

- `agent/shield-king-design-system`

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

Accepted direction:

- ordinary application icons: compact metallic/silver, restrained purple/gold detail, **no large royal shield frame**;
- eight game icons: rich metallic Variant 1 direction with one equal crowned royal family;
- all manual corrections for Tic Tac Toe / Four in a Row / Checkers / Reversi / Chess / Go / Domino are frozen in `ICONS.md`;
- broken crop previews are rejected and not design references.

No further product-owner icon review is required unless future real-slot integration reveals an actual asset defect.

## DS-3 — PASS

The current accepted Mini Games World UI structure is preserved.

Shield King is applied as a skin/migration only:

```text
existing layout + actions + copy + responsive rules
→ Shield King colors/tokens
→ accepted icons
→ component states
```

It is not permission to rebuild Home, add a bottom navigation, invent product blocks, reorder screens or recreate the information architecture.

Authoritative migration files:

- `CURRENT_UI_MIGRATION.md`
- `CURRENT_UI_STYLE_MAP.md`
- `EXISTING_SCREEN_MIGRATION.md`
- `EXISTING_AUX_SURFACES_MIGRATION.md`
- `SCREEN_STATE_MATRIX.md`
- `SCREENS.md` only where it does not conflict with the preserve-existing-UI rule.

## DS-4 — PASS / current gameplay boards preserved

The attempted broad gameplay-board redesign was explicitly rejected and discarded.

Final rule:

- all eight current gameplay boards remain as currently accepted;
- current geometry, pieces, interactions, responsive behavior and game-specific identity remain authoritative;
- safe Shield King color substitutions may be applied only where they genuinely improve fit without harming readability;
- if a game does not adapt cleanly, keep its current colors unchanged;
- game-card icon art does not dictate live-board colors.

Authoritative files:

- `GAMES.md`
- `GAME_COMPONENTS.md`

## DS-5 — specification ready

Created:

- `LOADING_AND_SYSTEM_STATES.md`
- `PHASE_B_VISUAL_CONTRACT.md`
- `ASSET_MANIFEST.md`

The system-state contract preserves the authoritative lifecycle and specifically freezes:

```text
server-confirmed match
→ preparation layer
→ authoritative readiness
→ shared deterministic 3 → 2 → 1
→ neutral wait if server is slower
→ short success check
→ authoritative gameplay reveal
```

No `VS`, no `СТАРТ`, no fake progress, no local readiness/clock/polling owner.

## Ownership

Persistent changes for this workstream belong only under:

`design-system/shield-king/**`

Future integration into the real shared UI must happen through a separate main-roadmap compatibility/integration gate. This branch must never be blindly merged into future runtime code.
