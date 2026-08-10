# Mini Games World — Shield King Design System V1

## Status

```text
DS-0 FOUNDATIONS / TOKENS — PASS
DS-1 COMPONENT LIBRARY — PASS
DS-2 EXACT ICON SYSTEM — PASS / MANUALLY ACCEPTED
DS-3 SCREEN SPECIFICATIONS — IN PROGRESS
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

## Completed output

### DS-0 — PASS

- `FOUNDATIONS.md`
- `TOKENS.md`
- `tokens.json`

### DS-1 — PASS

- `COMPONENTS.md`
- `COMPONENT_STATES.md`

### DS-2 — PASS / manual product-owner acceptance 2026-08-10

- `ICONS.md`
- `ICON_MANIFEST.md`
- navigation SVG symbol sprite;
- action SVG symbol sprite;
- status SVG symbol sprite;
- economy SVG symbol sprite;
- 8/8 standalone royal game SVG icons.

Accepted DS-2 direction:

- ordinary app icons remain lighter and do not use the large royal shield frame;
- all eight game icons use one equal-width crowned royal frame template;
- all eight game SVGs use `viewBox="0 0 96 112"`;
- Tic Tac Toe has no redundant nested black panel;
- Four in a Row uses two player colors only;
- Checkers uses coherent board + solid black vs solid gold pieces;
- Reversi uses dark-violet board + black/white discs only, no green;
- Chess uses the same crown/frame/width as all other games;
- Go uses black/white stones only;
- Domino uses the same external frame width as the rest.

## Current work

`DS-3 SCREEN SPECIFICATIONS` is now active.

The screen system will define the shared product hierarchy first and use the accepted DS-0/DS-1/DS-2 rules rather than inventing screen-specific styling.

## Ownership

Persistent changes for this workstream belong only under:

`design-system/shield-king/**`

Current exact-diff policy: no runtime file may be changed by this branch.

Future integration into the real shared UI must happen through a separate main-roadmap compatibility/integration gate. This branch must never be blindly merged into future runtime code.
