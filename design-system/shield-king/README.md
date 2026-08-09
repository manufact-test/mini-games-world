# Mini Games World — Shield King Design System V1

## Status

```text
DS-0 FOUNDATIONS / TOKENS — PASS
DS-1 COMPONENT LIBRARY — PASS
DS-2 EXACT ICON SYSTEM — AWAITING MANUAL VISUAL ACCEPTANCE
DS-3 SCREEN SPECIFICATIONS — NOT STARTED
DS-4 EIGHT-GAME VISUAL SYSTEM — NOT STARTED
DS-5 LOADING / SYSTEM STATES / HANDOFF — NOT STARTED
```

This directory is the isolated design-system owner for the approved **Shield King** shared-product visual direction.

It is design/specification work only. Nothing in this directory owns or changes runtime behavior, Telegram/Web application logic, Android shell behavior, backend/API contracts, database/storage, matchmaking, economy, game engines, readiness, timers, polling, staging, main, or production.

## Source of truth

This workstream is based on the frozen Android Branding Pack:

- branch: `agent/android-branding-pack`
- exact accepted SHA: `4f110277c85df4c77d9a66b794ff620812c16d2d`
- approved identity: **Shield King / MGW**

Durable inputs:

- `android-app/docs/BRANDING.md`
- `android-app/docs/FULL_APP_REDESIGN_HANDOFF.md`
- `android-app/docs/FINAL_BRANDING_ACCEPTANCE.md`

The written accepted rules override accidental differences in earlier concept imagery.

## Non-negotiable brand rule

The primary Shield King mark is a centered shield + centered crown + metallic `MGW` composition on a near-black base.

Forbidden behind the mark:

- white/silver offset backplate;
- pale duplicate plate;
- duplicated shifted shield silhouette;
- asymmetric glow blob that visually moves the mark off-center.

Metallic highlights on the mark itself are allowed. All ambient glow must remain visually centered.

## Workstream

1. `DS-0` — Foundations / Tokens
2. `DS-1` — Component Library
3. `DS-2` — Exact Icon System
4. `DS-3` — Screen Specifications
5. `DS-4` — Eight-Game Visual System
6. `DS-5` — Loading / System States / Final Handoff

## Completed output

### DS-0

- `FOUNDATIONS.md`
- `TOKENS.md`
- `tokens.json`

### DS-1

- `COMPONENTS.md`
- `COMPONENT_STATES.md`

### DS-2 — implementation complete, acceptance pending

- `ICONS.md`
- `ICON_MANIFEST.md`
- navigation SVG symbol sprite;
- action SVG symbol sprite;
- status SVG symbol sprite;
- economy SVG symbol sprite;
- 8/8 standalone game SVG icons.

DS-0 and DS-1 are passed. DS-2 exact assets are present and its diff remains isolated, but DS-2 cannot be marked PASS until product-owner visual acceptance of the icon family.

## Ownership

Persistent changes for this workstream belong only under:

`design-system/shield-king/**`

Current exact-diff audit confirms all branch changes remain under this directory; no runtime file is changed.

Future integration into the real shared UI must happen through a separate main-roadmap compatibility/integration gate. This branch must never be blindly merged into future runtime code.
