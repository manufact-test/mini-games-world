# MGW CURRENT SHORT ROADMAP — 2026-09-19 — BATTLESHIP CLOSED → MVP-19.13 BUNDLES

## Current state

MVP-19.12 Battleship is CLOSED and manually accepted.

Checkpoint:
- branch: `agent/mvp-13-2-staging`
- SHA: `db81c231447bb22cd1aefd2415d3e5bbbfb77c41`
- targeted Battleship suite: GREEN
- exact Hostinger deploy: confirmed
- Linux staging A/B E2E: GREEN

Do not do more Battleship visual tuning unless a new regression is reproduced.

---

# NEXT: MVP-19.13 — «Наборы» for all games

## Goal

Turn the existing old Store `Наборы` pilot into a real shared premium-bundle system for every game, using the cosmetics and previews that have already been manually accepted.

The old TTT/Checkers bundle backend is useful. The old bundle UI is not.

## Phase 1 — inventory/audit before coding

1. Read the current active bundle backend:
   - `bot/catalog/CosmeticStoreService.php`
   - `bot/catalog/CosmeticStoreRuntimePurchaseService.php`
   - `bot/catalog/ProductInventoryService.php`
   - `mgw_product_offers` bundle schema
2. Read the two existing bundle seeds:
   - `ttt-premium-bundle`
   - `checkers-premium-bundle`
3. Verify exact premium/highest-tier item IDs for all eight games.
4. Verify all accepted Store preview owners for each game.
5. Do not start by polishing the current bundle cards.

## Phase 2 — canonical data model

Create one additive migration (next migration number after 0040) that:

- preserves/normalizes existing TTT and Checkers bundle IDs;
- adds one premium game bundle for:
  - Chess
  - Reversi
  - Go
  - Domino
  - Four in a Row
  - Battleship
- keeps each bundle at five members:
  - premium theme
  - premium elements
  - all three effects
- keeps 34,000 coins as the existing canonical full-bundle price unless the user asks to change it;
- keeps purchase = missing items only;
- keeps no auto-equip;
- keeps durable purchase/audit behavior.

Do not delete old bundle rows if they may be referenced by purchase history.

## Phase 3 — generalize backend snapshot

`CosmeticStoreService` currently hardcodes:
- `TICTACTOE_BUNDLE_OFFER_ID`
- `CHECKERS_BUNDLE_OFFER_ID`
- only those two entries in `game_bundles`.

Replace the two-game assumption with a generic ordered game-bundle projection.

Required order:
1. tictactoe
2. chess
3. checkers
4. reversi
5. go
6. domino
7. four_in_a_row
8. battleship

Each public bundle should expose enough metadata for a generic client card:
- game type
- display name
- members
- missing members
- owned/missing counts
- full price
- current price
- regular missing price
- saving
- already-owned state
- purchasable/affordable state

Do not make the client guess bundle composition from names.

## Phase 4 — rebuild the Bundles UI from scratch

Current `renderGameBundle()` is a legacy visual and should be replaced, not incrementally patched.

New UI requirements:

- one consistent premium bundle card system for all eight games;
- visually premium, matching the current Store;
- mobile-first and no clipping;
- use actual accepted game cosmetics/previews instead of generic coloured squares;
- show a clear composition of the five included items;
- show game name and bundle name;
- show 34,000 full bundle price / saving relative to separate purchase;
- if partially owned, clearly show remaining count and current quote;
- if fully owned, show completed state and disable purchase;
- clicking/opening should use a proper detail/purchase sheet with the same accepted preview language;
- buying a bundle must not auto-equip its items;
- after purchase, Store/Profile inventory must refresh correctly.

Where practical, reuse the exact shared preview functions/modules already used by Store/Profile for each game. Do not create a third visual truth just for Bundles.

## Phase 5 — preserve individual catalogs

Bundle work must not change:
- individual item prices;
- individual Store cards;
- Profile cards;
- accepted LIVE cosmetics;
- equip/unequip behavior;
- one-effect-slot rules per game;
- game mechanics.

The bundle is only a purchase grouping over existing permanent item IDs.

## Phase 6 — test contract

Add a dedicated MVP-19.13 bundle workflow/contracts that at minimum prove:

- eight active game bundles exist;
- each has exactly five valid active game items;
- each contains exactly one premium theme, one premium elements item and all three effects;
- full bundle quote = 34,000;
- full separate total = 39,500;
- partial ownership excludes already-owned items;
- partial purchase never duplicates ownership;
- all-owned bundle is not purchasable;
- purchase never auto-equips;
- purchase remains idempotent;
- all eight bundles are exposed in Store snapshot in canonical order;
- Store UI has no TTT/Checkers-only hardcoding;
- bundle UI uses accepted preview owners;
- responsive/mobile geometry stays valid.

## Manual acceptance sequence

Do not ask the user to review after tiny intermediate steps.

Bring the user in when:
1. all eight bundle cards are visible and polished;
2. detail/purchase sheet works;
3. full and partial bundle price behavior works;
4. purchase grants only missing items;
5. inventory/Profile updates correctly;
6. targeted CI is green;
7. exact staging SHA is deployed.

Manual review should check:
- desktop + mobile Store bundle tab;
- at least one full bundle purchase;
- at least one partial-ownership bundle purchase;
- all-owned state;
- visual parity of previews against accepted individual cosmetics.

## Important old code decision

KEEP as architecture/reference:
- generic bundle offer schema;
- purchase/quote/ownership mechanics;
- TTT/Checkers five-member concept;
- 34k canonical full price;
- no-auto-equip behavior.

REPLACE:
- old `renderGameBundle()` visuals;
- hardcoded TTT/Checkers-only bundle list;
- generic fallback visual for non-Checkers bundles;
- any duplicated fake preview that does not use accepted game preview owners.

## Definition of done

MVP-19.13 is done only when all eight game bundles are present, visually coherent, purchasable with correct partial-ownership behavior, and manually accepted on staging.
