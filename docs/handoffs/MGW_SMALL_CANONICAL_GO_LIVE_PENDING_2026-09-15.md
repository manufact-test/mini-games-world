# MiniGamesWorld — small canonical card: Go Live

Date: 2026-09-15  
Integration branch: `agent/mvp-13-2-staging`  
Corrective PR: #1425  
Launch cache: `v1146`

## Status

**MANUAL ACCEPTANCE PENDING** for Go Live Effect 2, Effect 3 and Go rules marker alignment.

## Already accepted / frozen

- Go live field geometry, full-width board column, `Пас` and `В меню`: **ACCEPTED**. Do not modify as part of this corrective.
- Effect 1 — Placement: **ACCEPTED**. Do not modify.
- Go Store/Profile catalog and shared equip ownership remain the existing canonical owners.
- Purchase must never auto-equip.

## Pending manual acceptance

### Effect 2 — Group Capture

- Must trigger only on a real capture.
- Primary source remains authoritative `last_captured_cells`.
- If polling skips the base capture animation frame, presentation may derive removed stones from previous/current board snapshots and create a visual-only outgoing ghost at the real captured intersection.
- Captured stone must visibly implode/disappear with the already-approved Store-style rotating corona and particle burst.
- Ordinary placement must not trigger Effect 2.
- Presentation fallback must not create a second gameplay/rules owner.

### Effect 3 — Territory

- Temporary staging QA trigger remains on an ordinary placement only so the visual can be reviewed without finishing a full game.
- QA preview must show **at most three** Store-style square territory stamps on nearby **empty real Go intersections** around the newly placed stone.
- Square appearance stays the accepted Store language: cyan for black-side territory, purple for white-side territory, same rounded square/stamp motion.
- No square may be placed on a standing stone.
- No board-wide/random marker spread.
- No centre-origin radial wave.
- No `MG` / `MGW` seal.
- Canonical finished-game territory remains driven by authoritative `final_score` territory cells.
- After manual acceptance, remove the ordinary-placement QA trigger and keep Effect 3 only on the canonical territory-finish event.

### Go rules diagrams

- `forbidden` (`×`) marker in the suicide example must be centered exactly on its Go intersection.
- `ko` (`↺`) marker in the ko example must be centered exactly on its Go intersection.
- Diagram logic/content remains unchanged; this is presentation alignment only.

## Ownership / frozen boundaries

- Base Go renderer remains the gameplay/action owner.
- No changes to Go rules engine, server Go service, bot logic, timers, economy or inventory ownership.
- Cosmetics wrapper remains presentation-only.
- Field/layout accepted surface stays untouched.

## Acceptance gate

1. Merge corrective to staging.
2. Redeploy Hostinger staging from `agent/mvp-13-2-staging`.
3. Fully close and reopen Telegram Mini App (`v1146`).
4. Manually verify Effect 2, Effect 3 QA preview and the two Go rules markers.
5. **Do not mark these three items accepted until explicit user approval.**
