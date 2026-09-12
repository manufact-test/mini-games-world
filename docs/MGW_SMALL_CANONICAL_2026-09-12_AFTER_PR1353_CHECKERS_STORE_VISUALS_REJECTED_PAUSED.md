# MiniGamesWorld — SMALL CANONICAL
## 2026-09-12 — after PR #1353 — Checkers Store visuals rejected / MVP-19.6 paused

## Purpose
This checkpoint records the durable factual state after the full technical Checkers Store slice and several visual corrective passes.
It is the source to use when returning to MVP-19.6 after the user switches to another task.

## Source priority
When continuing from this checkpoint, use this order:
1. factual GitHub/runtime/staging state;
2. newest SESSION CHECKPOINT / CURRENT SHORT ROADMAP;
3. newest SMALL CANONICAL / CANONICAL DELTA;
4. GLOBAL CANONICAL MASTER;
5. older historical docs.

If an older handoff conflicts with the current repo, the current factual repo/runtime wins.

## Current factual project position
- Active formal stage remains **MVP-19.6 — Checkers cosmetics**.
- MVP-19.5 Chess is CLOSED / manually accepted / frozen.
- Active staging branch: `agent/mvp-13-2-staging`.
- Current checkpoint: `91aec29dbcc8a3f1febf41b9bba3f276150fdac7`.
- PR #1353 is merged into staging.
- Staging Playwright E2E for the merged checkpoint passed.
- `Checkers live static owner probe` passed.
- **MVP-19.6 is NOT accepted and NOT closed.**
- Work is intentionally paused because the user rejected the current Store visuals and moved to another task.

## Canonical economy / ownership rules
These remain accepted and must not regress:
- purchase never auto-equips;
- ownership is persistent;
- duplicate ownership is blocked;
- equip / unequip is explicit;
- one equipped cosmetic per slot;
- bundle purchase must respect already-owned members / partial pricing;
- Store, live game and Profile must eventually represent the same accepted cosmetic identity.

## Canonical Checkers catalogue now established in the repo
The Checkers Store technical catalogue is no longer hypothetical: exact IDs, slots and prices are established.

### Boards / themes
1. `game-checkers-board-wood` — 3,000;
2. `game-checkers-board-dark` — 5,000;
3. `game-checkers-board-marble` — 8,000;
4. `game-checkers-board-neon` — 12,000.

Factual current slot:
- `game_checkers_theme`.

Important correction versus stale earlier handoff text:
- do **not** use `game_checkers_board` as the factual current slot;
- current repo/runtime truth is `game_checkers_theme`.

### Checker / piece sets
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

Event contract:
- move → normal move effect;
- capture → capture effect;
- promotion → king / `дамка` promotion effect.

### Premium bundle
Offer:
- `checkers-premium-bundle`.

Price:
- 34,000.

Composition:
- `game-checkers-board-neon`;
- `game-checkers-pieces-neon`;
- `game-checkers-effect-move`;
- `game-checkers-effect-capture`;
- `game-checkers-effect-promotion`.

## Runtime / owner truth at this checkpoint
### Store
The active Store route is composed through the Checkers Store wrapper over the native Store owner.
Current relevant files include:
- `app/assets/js/screens/store-screen.js`;
- `app/assets/js/screens/store-screen-checkers-wrapper.js`;
- `app/assets/css/games/checkers/cosmetics.css`;
- `app/assets/css/games/checkers/store-visual-corrective-v2.css`;
- `app/assets/css/games/checkers/store-visual-corrective-v3.css`;
- `app/runtime/client/version-manifest.php`.

Do not assume every historical corrective file represents accepted design; they document attempts, not approval.

### Live Checkers
Live runtime is still intentionally board-only for cosmetics:
- `app/assets/js/checkers-cosmetics/renderer-board-themes.js` projects equipped board theme;
- accepted base Checkers renderer/rules/layout remain frozen;
- live `game_checkers_elements` projection is not implemented yet;
- live `game_checkers_effect` projection is not implemented yet.

This is deliberate because the agreed process was Store first, then live parity, then Profile.

## PR history for this slice
### #1347 — `MVP-19.6: add Checkers board cosmetics`
Established the first bounded family:
- four boards;
- prices/ownership/equip;
- Store/Profile previews;
- live board projection.

### #1348 — `MVP-19.6: correct Checkers Store previews`
First Store-only visual correction for board/header/purchase preview geometry.

### #1349 — `MVP-19.6: complete Checkers Store`
Established the full technical Store catalogue:
- four piece sets;
- three effects;
- 34k Checkers bundle;
- purchase/equip state semantics;
- complete Store contract coverage.

### #1350 — `Stabilize staging Phase-B readiness probes`
Test/E2E infrastructure correction only.
It did not change the Checkers cosmetic product/runtime design.

### #1351 — `MVP-19.6: correct Checkers Store visual review`
First large manual-review corrective after full Store completion.

### #1352 — `MVP-19.6: rebuild Checkers Store previews`
Second manual-review corrective:
- board preview rebuild;
- passive finite effects;
- bundle rebuilt from Checkers components.

### #1353 — `MVP-19.6: rebuild Checkers Store visuals pass 3`
Third visual corrective:
- another board presentation attempt;
- marble material changes;
- rebuilt effect scenes;
- five-part bundle composition;
- no live mechanics changes.

## Manual acceptance status — authoritative
The user reviewed the post-#1353 Store and **rejected the visual result**.

Authoritative latest verdict:
- user said they did not like essentially anything currently shown;
- the premium **bundle presentation is not accepted**;
- the main Store **effect-card previews are not accepted**;
- current boards/piece treatment must not be inferred as accepted merely because no separate screenshot was supplied in the final feedback;
- the effect preview inside the **purchase-confirm dialog** is the only piece described as **more or less acceptable**, but the user explicitly said it is still not fully worked out and is **not final acceptance**.

Therefore:
- no Checkers Store visual family is frozen;
- no current Store screenshot should be used as an approved visual reference;
- technical CI/E2E success must never be interpreted as visual acceptance.

## Current user decision / working state
The user requested:
1. save the current state;
2. create a current roadmap and canonical checkpoint;
3. pause Checkers Store work;
4. switch to another task.

Do not continue Checkers visual work until the user explicitly returns to it.

## How to resume MVP-19.6 later
When the user returns to Checkers:
- start from staging checkpoint `91aec29dbcc8a3f1febf41b9bba3f276150fdac7` or the newest factual descendant;
- preserve the technical catalogue/economy contract unless a real bug is found;
- treat current visuals as a rejected prototype line;
- do not keep iterating from the assumption that PR #1353 is visually close;
- first establish a new visual direction/reference for boards, piece sets, effects and bundle;
- rebuild the complete Store surface coherently;
- only then request another full Store manual review.

After Store acceptance:
1. live board parity confirmation;
2. implement live four piece sets;
3. implement live move/capture/promotion effects on real events only;
4. reconnect/rerender persistence;
5. Store ↔ live one-to-one comparison;
6. Profile parity last.

## Frozen boundaries / safety
During future Checkers Store work:
- do not change Checkers rules;
- do not change move legality;
- do not change timers;
- do not change settlement;
- do not change board hit targets;
- do not reopen accepted Chess/Profile/Victory behavior without a concrete repro;
- no `main`, production, Cron, or live DB changes without explicit gating.

## Formal roadmap after MVP-19.6 eventually closes
- MVP-19.7 Reversi cosmetics;
- MVP-19.8 Go cosmetics;
- MVP-19.9 Domino cosmetics;
- MVP-19.10 Tic-Tac-Toe cosmetics;
- MVP-19.11 Four-in-a-row cosmetics;
- MVP-19.12 Battleship cosmetics;
- MVP-19.13 seasonal/full regression.
