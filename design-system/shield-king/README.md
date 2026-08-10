# Mini Games World — Shield King Design System V1

## Status

```text
SHIELD KING DESIGN SYSTEM V1 — COMPLETED / FROZEN / NOT INTEGRATED

DS-0 FOUNDATIONS / TOKENS — PASS
DS-1 COMPONENT LIBRARY — PASS
DS-2 EXACT ICON SYSTEM — PASS / MANUALLY ACCEPTED
DS-3 SCREEN SPECIFICATIONS / EXISTING-UI MIGRATION — PASS
DS-4 EIGHT-GAME VISUAL SYSTEM — PASS / CURRENT BOARDS PRESERVED
DS-5 LOADING / SYSTEM STATES / PHASE B CONTRACT — PASS
```

This directory is the isolated design-system owner for the approved **Shield King** shared-product visual direction.

It is design/specification work only. Nothing here owns or changes runtime behavior, Telegram/Web application logic, Android shell behavior, backend/API contracts, database/storage, matchmaking, economy, game engines, readiness, timers, polling, staging, main, or production.

## Source of truth

Base/frozen branding input:

- repository: `manufact-test/mini-games-world`
- branch: `agent/android-branding-pack`
- exact accepted SHA: `4f110277c85df4c77d9a66b794ff620812c16d2d`
- approved identity: **Shield King / MGW**

Frozen design-system branch:

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
- all manual corrections are frozen in `ICONS.md`;
- broken crop/contact previews are rejected and not design references;
- no further product-owner icon review is required unless future real-slot integration reveals an actual defect.

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

`CURRENT_UI_MIGRATION.md` overrides any older exploratory wording in `SCREENS.md` that conflicts with the preserve-existing-UI rule.

## DS-4 — PASS / current gameplay boards preserved

The broad gameplay-board redesign experiment was explicitly rejected and discarded.

Final rule:

- all eight current live gameplay boards remain as currently accepted;
- current geometry, pieces, interactions, responsive behavior and game-specific identity remain authoritative;
- safe Shield King color substitutions may be applied only where they genuinely improve fit without harming readability;
- if a game does not adapt cleanly, keep its current colors unchanged;
- game-card icon art does not dictate live-board colors;
- rejected board-family mockups must never be reused.

Authoritative files:

- `GAMES.md`
- `GAME_COMPONENTS.md`

## DS-5 — PASS

Authoritative files:

- `LOADING_AND_SYSTEM_STATES.md`
- `PHASE_B_VISUAL_CONTRACT.md`
- `ASSET_MANIFEST.md`

The existing authoritative lifecycle is preserved:

```text
server-confirmed match
→ preparation layer
→ authoritative readiness
→ deterministic shared 3 → 2 → 1
→ neutral wait if server is slower
→ short success check
→ authoritative gameplay reveal
```

Frozen presentation constraints:

- no `VS` center label;
- no `СТАРТ` after `1`;
- no fake percentage progress;
- no local readiness owner;
- no local countdown/clock owner;
- no duplicate polling owner;
- no gameplay reveal before authoritative conditions;
- preparation timeout remains distinct from draw.

## Final quality audit

```text
persistent diff ownership: design-system/shield-king/** only
runtime files changed: 0
staging files changed: 0
main/production files changed: 0
8 games covered: yes
screen migration coverage: yes
loading/system states: yes
Phase B visual contract: yes
asset manifest: yes
rejected replacement Home: excluded
rejected gameplay-board redesign: excluded
branch integration: NOT PERFORMED
```

## Future integration rule

This branch must never be blindly merged into future runtime code.

At the safe main-roadmap UI/App Shell integration gate:

```text
latest accepted shared UI
+ Android Foundation handoff
+ Android Branding handoff
+ this Shield King handoff
→ compatibility audit
→ map current selectors/components to tokens
→ integrate accepted icons
→ skin existing screens without restructuring
→ preserve current gameplay boards; safe colors only
→ skin loading/Phase B visuals without lifecycle changes
→ focused visual/functional checks
→ full regression
→ manual Telegram acceptance
→ Android inherits the same shared UI
```

# END — COMPLETED / FROZEN / NOT INTEGRATED
