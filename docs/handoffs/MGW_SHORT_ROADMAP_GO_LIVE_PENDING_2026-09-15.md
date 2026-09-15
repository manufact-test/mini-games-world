# MiniGamesWorld — short roadmap: Go Live pending acceptance

Date: 2026-09-15  
Integration branch: `agent/mvp-13-2-staging`  
Corrective PR: #1425  
Launch cache: `v1146`

## Where we are now

MVP-19.8 Go cosmetics is at the final Live manual-review corrective.

Already accepted and not part of the current visual gate:
- Go Store flow/catalog.
- Go Profile parity/equip flow.
- Go live field geometry/full-width layout, `Пас`, `В меню`.
- Go Effect 1 — Placement.

Current corrective is limited to:
- Effect 2 — Group Capture visibility/timing.
- Effect 3 — approved Store square visual in live QA, without centre wave/logo/random spread.
- Go rules `×` / `↺` marker centering.

**These three current corrective items are NOT YET ACCEPTED.**

## Current gate

After PR #1425 is merged and Hostinger staging is redeployed:

1. Equip Effect 2 and perform a real capture.
   - Captured stone must visibly implode/disappear.
   - Store-style corona + particles must be visible.
   - No Effect 2 on ordinary placement.
2. Equip Effect 3 and make an ordinary move while the temporary QA trigger is active.
   - Maximum three cyan/purple Store-style square stamps.
   - Squares appear on nearby empty real intersections around the new stone.
   - No squares on stones.
   - No centre wave, no `MG/MGW`, no random board-wide spread.
3. Open Go rules.
   - Suicide `×` marker centered exactly on its intersection.
   - Ko `↺` marker centered exactly on its intersection.

## Immediately after manual acceptance

- Remove the temporary Effect 3 ordinary-placement QA trigger.
- Leave Effect 3 only on the canonical finished-territory event driven by authoritative `final_score` territory cells.
- Re-run focused Go Live + Go Store + Go Profile regression contracts.
- One final manual Go Live confirmation.
- Update the large canonical with the accepted final state.

## Do not do

- Do not redesign or resize the already accepted Go field.
- Do not alter accepted Effect 1.
- Do not invent a new Effect 3 visual; Store preview is the visual reference.
- Do not modify Go rules/gameplay, server Go engine, bot logic, timer, economy or inventory ownership for this presentation corrective.
- Do not move to the next game/scope before this manual Go Live gate is closed.
