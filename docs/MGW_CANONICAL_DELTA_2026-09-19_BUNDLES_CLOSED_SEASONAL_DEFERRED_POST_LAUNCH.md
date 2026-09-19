# MGW CANONICAL DELTA — 2026-09-19 — BUNDLES CLOSED / SEASONAL COLLECTIONS DEFERRED POST-LAUNCH

## 1. Current accepted point

- Repository: `manufact-test/mini-games-world`
- Integration branch: `agent/mvp-13-2-staging`
- Accepted runtime staging SHA before this docs-only checkpoint: `1ad6b70e29b098a4d632db710178a667743139f0`
- Accepted runtime tree: `8830d1516295537cbe8d712e6d3cb6219dd2c056`
- Latest product PR: `#1570 — MVP-19.13: restore bundle selector clicks and add scroll hint`
- Final `staging-playwright-e2e`: SUCCESS.
- User manual acceptance: complete Bundles system accepted on staging.

## 2. MVP-19.13 «Наборы» — CLOSED / ACCEPTED / FROZEN

- All 8 game bundles are present: Tic Tac Toe, Chess, Checkers, Reversi, Go, Domino, Four in a Row, Battleship.
- Shared UX: one horizontal per-game selector; click/tap selects a game; mouse drag / touch swipe scrolls the selector; edge hint shows that more games exist off-screen.
- Bundle visuals reuse accepted Store/Profile preview owners; no parallel fake cosmetic implementation.
- Each premium game bundle keeps the accepted 2 + 3 composition: premium theme/elements + all 3 effects.
- Full bundle cap remains `34,000` coins.
- Partial quote remains `min(34,000, sum of missing individual items)`.
- Bundle purchase grants only missing items, is idempotent, and never auto-equips.
- Accepted Store cards, Profile cards, LIVE cosmetics and game mechanics remain frozen unless a new reproducible defect is found.

## 3. SUPERSEDING PRODUCT DECISION — seasonal collections

The older master contained two launch-era requirements:
- old `MVP-19.13 — Seasonal collection и полный cosmetics regression`;
- `на launch минимум одна full seasonal collection`.

These launch requirements are now **SUPERSEDED**.

Active rule:
- the first public launch does **not** require a seasonal cosmetic collection;
- seasonal collections are not a blocker for MVP-20–27 or for the first public release;
- seasonal content begins after public launch and initial stabilization, when the base product and launch bugs are under control;
- move seasonal collections/designs/assets into master section **23 — «Повторяемая эксплуатационная работа после запуска»**, as recurring post-launch content work;
- preserve the seasonal ownership contract: limited-time sale is allowed, but once purchased the item remains owned forever; no rental/expiry/loss; cross-device/account ownership rules remain the same;
- later seasonal collections are added gradually according to the post-launch content calendar.

Existing Future Plans item `Дополнительные игровые и сезонные эффекты` remains preserved and is not deleted by this decision.

## 4. NEXT

After the refreshed full canonical master is brought back, the next feature milestone is **MVP-20.1 — Per-game visible rating**.

Do not start MVP-20.2+ before MVP-20.1 is implemented, staged and manually accepted.

`main`, production and Cron remain untouched without separate explicit approval.
