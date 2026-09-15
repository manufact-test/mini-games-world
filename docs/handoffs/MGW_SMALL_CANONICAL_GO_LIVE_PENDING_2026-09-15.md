# MiniGamesWorld — small canonical card: Go Live

Date: 2026-09-15  
Integration branch: `agent/mvp-13-2-staging`  
Current corrective PR: #1426  
Launch cache: `v1147`

## Status

**MANUAL ACCEPTANCE PENDING** only for Go Live Effect 2 timing and Go rules marker alignment.

## Already accepted / frozen

- Go live field geometry, full-width board column, `Пас` and `В меню`: **ACCEPTED**. Do not modify.
- Effect 1 — Placement: **ACCEPTED**. Do not modify.
- Effect 3 — Territory visual: **ACCEPTED**. The temporary ordinary-placement QA trigger is removed; the accepted square-stamp visual is restored to the canonical finished-game territory event driven by authoritative `final_score` cells.
- Go Store/Profile catalog and shared equip ownership remain the existing canonical owners.
- Purchase must never auto-equip.

## Pending manual acceptance

### Effect 2 — Group Capture

- Must trigger only on a real capture.
- Primary source remains authoritative `last_captured_cells`.
- The legacy immediate Group Capture animation must stay suppressed before the actual capture moment.
- The approved Store-style implode + rotating corona + particles must start **once** when the base renderer marks the captured point with `capture-out`.
- There must be no preliminary twitch, return/reset, or second replay.
- If polling skips the base capture frame, presentation may derive removed stones from previous/current board snapshots and create one visual-only outgoing ghost at the real captured intersection; that fallback must also run the paid effect only once.
- Ordinary placement must not trigger Effect 2.
- Presentation fallback must not create a second gameplay/rules owner.

### Go rules diagrams

- `forbidden` (`×`) marker in the suicide example must be centered exactly on its Go intersection.
- `ko` (`↺`) marker in the ko example must be centered exactly on its Go intersection.
- Diagram logic/content remains unchanged; this is presentation alignment only.
- This alignment remains **PENDING** until explicit manual approval.

## Effect 3 canonical final state

- No ordinary-placement QA trigger.
- No centre-origin radial wave.
- No `MG` / `MGW` seal.
- No random board-wide marker spread.
- Final territory presentation is driven only by authoritative finished-game `final_score` territory cells.
- Cyan/purple rounded square language remains the accepted Store visual.

## Ownership / frozen boundaries

- Base Go renderer remains the gameplay/action owner.
- No changes to Go rules engine, server Go service, bot logic, timers, economy or inventory ownership.
- Cosmetics wrapper remains presentation-only.
- Field/layout accepted surface stays untouched.

## Acceptance gate

1. Merge PR #1426 to staging.
2. Redeploy Hostinger staging from `agent/mvp-13-2-staging`.
3. Fully close and reopen Telegram Mini App (`v1147`).
4. Equip Effect 2 and perform a real capture: one smooth Store-style capture animation, no first twitch/reset and no double replay.
5. Re-open Go rules and verify the `×` and `↺` marker centering if not already visually confirmed.
6. **Do not mark Effect 2 or rules alignment accepted until explicit user approval.**
