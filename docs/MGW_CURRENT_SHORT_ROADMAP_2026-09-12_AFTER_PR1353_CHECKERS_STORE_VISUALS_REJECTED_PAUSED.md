# MiniGamesWorld — CURRENT SHORT ROADMAP
## 2026-09-12 — after PR #1353 — MVP-19.6 Checkers Store visuals rejected / work paused

## WHERE WE ARE NOW
We are still inside **MVP-19.6 — Checkers cosmetics**.

Current staging truth:
- branch: `agent/mvp-13-2-staging`;
- checkpoint: `91aec29dbcc8a3f1febf41b9bba3f276150fdac7`;
- PR #1353 is merged;
- staging Playwright E2E for this checkpoint passed;
- Checkers live static-owner probe passed.

**MVP-19.6 is NOT accepted and NOT closed.**
The user explicitly rejected the current Checkers Store visual result after PR #1353 and asked to pause this work before switching to another task.

## WHAT IS TECHNICALLY IMPLEMENTED
The complete Checkers Store catalogue/economy slice exists technically:

### Boards
1. `game-checkers-board-wood` — 3,000;
2. `game-checkers-board-dark` — 5,000;
3. `game-checkers-board-marble` — 8,000;
4. `game-checkers-board-neon` — 12,000.

Factual board slot in the current repo/runtime:
- `game_checkers_theme`.

### Piece sets
1. `game-checkers-pieces-wood` — 3,000;
2. `game-checkers-pieces-marble` — 6,000;
3. `game-checkers-pieces-metal` — 9,000;
4. `game-checkers-pieces-neon` — 12,500.

Slot:
- `game_checkers_elements`.

### Effects
1. `game-checkers-effect-move` — 2,500;
2. `game-checkers-effect-capture` — 5,000;
3. `game-checkers-effect-promotion` — 7,500.

Slot:
- `game_checkers_effect`.

Events:
- move;
- capture;
- promotion / king / `дамка`.

### Bundle
- offer: `checkers-premium-bundle`;
- price: 34,000;
- composition: neon board + neon pieces + all three Checkers effects.

Economy semantics remain canonical:
- purchase never auto-equips;
- duplicate ownership protected;
- explicit equip / unequip;
- one equipped item per slot;
- partial bundle pricing supported.

## LIVE CHECKERS STATUS
Live Checkers remains intentionally bounded:
- equipped **board themes** already project into the actual Checkers game;
- live piece-set projection is **not implemented yet**;
- live move/capture/promotion cosmetics are **not implemented yet**;
- accepted Checkers renderer/rules/timers/layout remain frozen.

Do not confuse the technically complete Store catalogue with finished live parity.

## WHAT WE TRIED IN THIS CHECKERS STORE PASS
### PR #1347 — initial board family
`MVP-19.6: add Checkers board cosmetics`
- added four board products;
- Store/Profile preview wiring;
- live board projection;
- preserved game mechanics.

### PR #1348 — first board Store corrective
`MVP-19.6: correct Checkers Store previews`
- adjusted Checkers Store identity;
- attempted full 8×8 board previews;
- adjusted purchase-confirm board geometry.

### PR #1349 — complete technical Store slice
`MVP-19.6: complete Checkers Store`
- added four piece sets;
- added three effects;
- added 34k premium bundle;
- added purchase/equip state handling and effect previews;
- expanded Store contracts.

### PR #1350 — staging E2E probe stabilization
`Stabilize staging Phase-B readiness probes`
- E2E/test-only correction for synthetic A/B readiness;
- no runtime Store or game-product behavior change.

### PR #1351 — manual-review visual corrective
`MVP-19.6: correct Checkers Store visual review`
- reworked board cards/purchase sheets;
- reworked piece preview geometry;
- changed effects to passive finite previews;
- surfaced the Checkers bundle directly inside Games → Checkers.

### PR #1352 — second preview rebuild
`MVP-19.6: rebuild Checkers Store previews`
- further board geometry changes;
- removed decorative Store frame strip;
- rebuilt passive effect preview behavior;
- replaced abstract bundle art with actual Checkers components.

### PR #1353 — third visual rebuild
`MVP-19.6: rebuild Checkers Store visuals pass 3`
- attempted closer live-board geometry;
- changed marble material treatment;
- rebuilt move/capture/promotion scenes;
- rebuilt premium bundle from concrete mini-previews;
- kept live Checkers mechanics frozen.

## CURRENT MANUAL VISUAL VERDICT — IMPORTANT
After reviewing PR #1353 on staging, the user explicitly said the current result is **not liked / not accepted**.

Do NOT carry forward any of the following as approved visuals:
- current bundle card composition;
- current main Store effect-card previews;
- current board/piece visual treatment merely because it passed CI;
- current overall Checkers Store visual language.

Specific latest feedback:
- the **bundle** presentation is not acceptable;
- the **main effect cards** are not acceptable;
- the only area described as **more or less** visually acceptable is the effect preview inside the **purchase-confirm sheet**, and even that is explicitly **not finished / not fully accepted**.

Therefore there is currently **zero final visual acceptance for the Checkers Store**.
CI/E2E green means technical safety only; it does not mean the visual product is approved.

## PAUSE POINT
Stop Checkers Store work at staging:
- `91aec29dbcc8a3f1febf41b9bba3f276150fdac7`.

The user is switching to another task now.
Do not continue autonomous Checkers visual iterations until the user explicitly returns to MVP-19.6.

## WHEN MVP-19.6 IS RESUMED
Resume from the factual staging/runtime state above, but treat the present Store visuals as a **rejected baseline**, not a near-final design.

Recommended resume sequence:
1. inspect the latest screenshots/feedback first;
2. define a new visual direction for Checkers cards, effects and bundle instead of blindly polishing the rejected concept;
3. rebuild the complete Store presentation as one coherent surface;
4. keep existing catalogue IDs, prices, economy semantics and frozen game owners unless a concrete bug requires otherwise;
5. return only when the whole Store is ready for another manual review.

Only after Store visuals are accepted:
- implement live piece sets;
- implement live move/capture/promotion effects;
- verify Store ↔ live one-to-one parity;
- then finish Profile parity last.

## FROZEN / DO NOT REGRESS
- MVP-19.5 Chess remains accepted/frozen.
- Existing accepted Profile architecture remains frozen.
- Purchase must not auto-equip.
- No duplicate ownership.
- Do not modify Checkers rules, move legality, timers, settlement, board hit targets, or accepted layout owners during Store visual work.
- No `main`, production, Cron, or live DB changes without explicit gating.

## FORMAL SEQUENCE AFTER CHECKERS
After MVP-19.6 is eventually accepted and closed:
- MVP-19.7 Reversi cosmetics;
- MVP-19.8 Go cosmetics;
- MVP-19.9 Domino cosmetics;
- MVP-19.10 Tic-Tac-Toe cosmetics;
- MVP-19.11 Four-in-a-row cosmetics;
- MVP-19.12 Battleship cosmetics;
- MVP-19.13 seasonal/full regression.
