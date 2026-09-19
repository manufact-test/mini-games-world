# MGW SMALL CANONICAL — 2026-09-19 — BATTLESHIP CLOSED → NEXT BUNDLES

## 0. Authoritative checkpoint

Repository: `manufact-test/mini-games-world`  
Working/staging branch: `agent/mvp-13-2-staging`  
Accepted staging SHA: `db81c231447bb22cd1aefd2415d3e5bbbfb77c41`  
Tree: `2fe9f1ae7a04b144056657595d8b9bc1b6e58679`  
Active Telegram route remains `/app/v110.php?v=1233&...`.

This checkpoint supersedes older short roadmaps for Battleship. Treat Battleship MVP-19.12 as CLOSED/FROZEN unless a real regression is reproduced.

## 1. Battleship status: CLOSED / manually accepted

The user manually accepted the complete Battleship cosmetics flow:

- Store catalog and purchase flow.
- Profile catalog.
- Equip / unequip detail sheet.
- Purchase/detail sheet.
- LIVE game cosmetics.
- Theme/map cosmetics.
- Fleet cosmetics.
- Three gameplay effects:
  1. `game-battleship-effect-shot` — **Плазменный выстрел**
  2. `game-battleship-effect-hit` — **Ударная вспышка**
  3. `game-battleship-effect-destroy` — **Критическое потопление**

Final user decision on Destroy center fire: not perfect, but acceptable; **leave it as-is**. Do not reopen visual tuning unless the user explicitly reports a regression.

## 2. Final Battleship effect behavior

### Plasma Shot
- LIVE and previews accepted.
- Store/Profile/detail previews use scale-independent geometry.
- Tracer/bolt reach the reticle center exactly.
- LIVE shot motion is intentionally slightly slower/readable.
- Shot visual starts from the canonical queued `fire` action, not from an independent raw DOM click.

### Impact Flash
- LIVE and previews accepted.
- LIVE contains core + ring + flare + six readable outward streaks.
- Slower/readable timing accepted.
- Preview surfaces already match the accepted visual language.

### Critical Sink
- Store/Profile/detail previews accepted.
- LIVE uses connected visible sunk cells only.
- Center is optically shifted down to the visible ship row.
- LIVE contains bright blast, dedicated center flame, dual shockwaves, wreck, smoke and debris.
- Dedicated center flame is above wreck and persists through most of the event.
- Current version is `critical-sink-v4`.
- User accepted the current result even though the center fire is not considered visually perfect.

## 3. Battleship fire reliability fixes that must remain

Do not revert these while working on later Store tasks:

- Pending target cells no longer draw fake intermediate result frames.
- User sees the shot animation, then the authoritative `miss / hit / sunk` result.
- One Battleship fire may be pending at a time.
- Invalid/stale optimistic fire does not enter the queue.
- Lost `game_action` responses reconcile via fresh `game_state`; client never blindly retries a fire.
- Old `mgw-action-pending` input lock is released before authoritative rerender.
- Battle-phase Battleship `fire` uses a narrow API fast path that bypasses the global cross-game cleanup sweep.
- Timeout safety remains inside `BattleshipService::fire()`.
- Backend turn ownership and duplicate-shot guards remain authoritative.

The user re-tested after these fixes and reported that target cells were clicking correctly.

## 4. Last Battleship PR chain

- #1544 — Battleship Neon v5 accepted.
- #1545 — LIVE Plasma Shot.
- #1546 — LIVE Hit + Destroy.
- #1547 — accepted effects transferred to shared Store/Profile previews + fire reliability.
- #1548 — Plasma Shot preview geometry + slower LIVE flight.
- #1549 — richer/slower LIVE Hit + input-lock gap fix.
- #1550 — centered/slower Destroy + truthful pending marker.
- #1551 — remove intermediate pending frames + Destroy fire layering + Battleship fire fast path.
- #1552 — dedicated persistent Critical Sink center flame.

Final accepted SHA after #1552: `db81c231447bb22cd1aefd2415d3e5bbbfb77c41`.

## 5. Verification at close

Authoritative Battleship targeted workflow:
`MVP-19.12 Battleship Store/Profile/LIVE` — SUCCESS on final SHA.

Final staging E2E:
- exact staging commit checkout — SUCCESS;
- Hostinger exact-SHA wait — SUCCESS;
- staging A/B preflight — SUCCESS;
- real Linux two-context staging test — SUCCESS;
- workflow's final commit-status publishing job failed separately; this is not a product/E2E failure.

Do not reopen Battleship because the aggregate workflow card is red if the Linux route and targeted Battleship suite are green.

## 6. Next milestone: game bundles / «Наборы»

The Store already has a `bundles` tab and generic backend support for bundle offers, but the current implementation is an old pilot and must not be treated as accepted UI.

### Useful existing foundation — KEEP / REUSE

Backend:
- `mgw_product_offers.offer_type = 'bundle'`
- `members_json`
- missing-item calculation
- already-owned protection
- purchase idempotency/audit
- bundle fulfillment grants only missing items
- purchases never auto-equip
- partial bundle quote uses existing owned items
- generic `CosmeticStoreService` and runtime purchase flow

Existing active game bundle offers:
- `ttt-premium-bundle`
- `checkers-premium-bundle`

Both use the same five-item premium concept:
- highest/premium theme
- highest/premium elements
- all three effects

Existing price:
- individual premium contents total: 39,500 coins
- full bundle: 34,000 coins
- full saving: 5,500 coins

The current partial quote rule is:
`min(full_bundle_price, sum(price of missing individual items))`.

This behavior is already tested for Tic Tac Toe. Preserve it unless the user explicitly decides to change bundle economics.

### Old implementation — DO NOT USE AS VISUAL REFERENCE

Current bundle frontend is old hardcoded pilot code in `app/assets/js/screens/store-screen.js`:
- `gameBundlesFromSnapshot()`
- `renderBundlesTab()`
- `renderGameBundle()`

Problems:
- backend snapshot exposes only TTT + Checkers bundles;
- frontend explicitly special-cases Checkers and otherwise falls back to a Tic Tac Toe-style card;
- visuals are primitive and disconnected from the accepted Store/Profile preview owners;
- text/preview composition is hardcoded;
- it does not represent Chess, Reversi, Go, Domino, Four in a Row or Battleship;
- therefore it is **not** an acceptable design baseline.

Do not physically delete historical offer rows blindly: purchases reference offers through durable audit/FK relationships. Normalize/update existing offers or retire superseded offers via migration if necessary.

## 7. Canonical bundle target for all games

Next work should cover all eight game catalogs:

1. Tic Tac Toe
2. Chess
3. Checkers
4. Reversi
5. Go
6. Domino
7. Four in a Row
8. Battleship

All current game catalogs share the same canonical price grid:
- themes: 3,000 / 5,000 / 8,000 / 12,000
- elements: 3,000 / 6,000 / 9,000 / 12,500
- effects: 2,500 / 5,000 / 7,500

Therefore the existing premium-bundle model can be generalized cleanly:
- premium/highest theme = 12,000
- premium/highest elements = 12,500
- all 3 effects = 15,000
- separate total = 39,500
- canonical existing bundle price = 34,000

The next chat should verify the exact premium member IDs per game before seeding/updating offers.

## 8. Frozen areas for the next chat

While building Bundles:
- do not retune any accepted game LIVE cosmetics;
- do not redesign accepted individual Store cards;
- do not redesign accepted Profile cards;
- do not change game mechanics;
- do not change equip slot semantics;
- do not auto-equip after a bundle purchase;
- do not replace accepted preview owners with new fake bundle-only drawings when a real existing preview can be reused.

Bundle work should compose the already accepted cosmetics rather than creating parallel visual implementations.
