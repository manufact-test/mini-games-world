# MiniGamesWorld — short roadmap: Go Live pending acceptance

Date: 2026-09-15  
Integration branch: `agent/mvp-13-2-staging`  
Current corrective PR: #1426  
Launch cache: `v1147`

## Where we are now

MVP-19.8 Go cosmetics is at the final Live manual-review corrective.

Already accepted and not part of the current visual gate:
- Go Store flow/catalog.
- Go Profile parity/equip flow.
- Go live field geometry/full-width layout, `Пас`, `В меню`.
- Go Effect 1 — Placement.
- Go Effect 3 — Territory visual. Its temporary ordinary-placement QA trigger is removed; it is returned to the canonical finished-game territory event using authoritative `final_score` cells.

Current corrective is limited to:
- Effect 2 — Group Capture single-pass timing.
- Go rules `×` / `↺` marker centering, unless user explicitly confirms it visually.

**Effect 2 and rules alignment are NOT YET ACCEPTED.**

## Current gate

After PR #1426 is merged and Hostinger staging is redeployed:

1. Equip Effect 2 and perform a real capture.
   - Captured stone must run one continuous Store-style implode/disappearance.
   - Corona + particles must start at the same actual `capture-out` moment.
   - No preliminary twitch.
   - No return/reset.
   - No second replay.
   - No Effect 2 on ordinary placement.
2. Effect 3 no longer needs the temporary ordinary-move test.
   - It is accepted visually.
   - It now belongs only to the canonical finished territory event.
3. Open Go rules if marker alignment has not yet been explicitly accepted.
   - Suicide `×` centered exactly on its intersection.
   - Ko `↺` centered exactly on its intersection.

## Immediately after manual acceptance

- Mark Effect 2 accepted if the capture is a single smooth animation.
- Mark rules marker alignment accepted only after explicit confirmation.
- Re-run/confirm focused Go Live + Go Store + Go Profile regression state.
- Update the large canonical with the final accepted Go Live state.
- Close MVP-19.8 Go cosmetics and proceed to the next planned scope only after the remaining manual gate is closed.

## Do not do

- Do not redesign or resize the already accepted Go field.
- Do not alter accepted Effect 1.
- Do not change the accepted Effect 3 visual or re-add its ordinary-placement QA trigger.
- Do not reintroduce centre waves, `MG/MGW`, or random territory markers.
- Do not modify Go rules/gameplay, server Go engine, bot logic, timer, economy or inventory ownership for this presentation corrective.
