# MVP-25.6 — Monetization-disabled complete-product mode

**Status:** CLOSED / AUTOMATED + STAGING ACCEPTED / FROZEN  
**Base staging:** `a90168cb84bf89631b97526684c13c2cdc8c0536`  
**Accepted staging:** `822c45d7db2ccb327925fa66fd0376dd05ecdcc8`

## Scope

MVP-25.6 closes the final player-facing unfinished monetization surface identified by
MVP-25.1. Real-money purchasing remains intentionally **not implemented** in MVP-25.

The completed product state must:
- keep the existing in-game coin balance and cosmetic purchases;
- keep active Profile / Games / Bundles Store catalogues;
- not advertise provider coin packages when external billing is unavailable;
- not show “Скоро” or another future-feature promise for monetization;
- not restore legacy Gold/order/top-up UI from MVP-24;
- not alter economy, ledger, payment archive, tournament or game mechanics.

## Corrective

### Backend Store projection
`CosmeticStoreService` now owns an explicit disabled external-billing state for MVP-25:
- the `coins` tab is published with `available=false`;
- `coins.billing_available=false`;
- provider coin packages are not included in the player snapshot while billing is disabled;
- all in-game cosmetic catalogues and the player's coin balance remain unchanged.

### Client Store state
The Store client:
- treats `coins` as unavailable before the first API response;
- filters all server-disabled tabs;
- reconciles the active tab against the visible tab set after hydration;
- refuses navigation into an unavailable tab;
- contains no “Скоро” monetization copy;
- keeps a defensive finished disabled state if the coin tab is invoked unexpectedly.

### Delivery/cache identity
The canonical v110 client manifest advances the Store base-module destination to
`store-screen.js?v=69` with the product identity
`mvp25_6=monetization-disabled-complete-v1`.

## Explicitly unchanged

- no real-money provider integration;
- no PaymentService behavior change;
- no economy/ledger value change;
- no Gold/order UI restoration;
- no game/tournament behavior change;
- no production DB/Cron change.

## Acceptance

Automated candidate gates must prove:
1. disabled billing exposes no player coin-package catalogue;
2. internal coin balance and cosmetics remain intact;
3. the Store client hides unavailable monetization UI from cold load onward;
4. MVP-24 legacy Gold retirement remains intact;
5. the accepted MVP-19 Store purchase mechanics remain intact;
6. frozen economy/ledger/payment/game/tournament owners are unchanged.

## Closure evidence

The implementation PR #1826 merged to exact staging SHA
`822c45d7db2ccb327925fa66fd0376dd05ecdcc8`.

Focused MVP-25.6 candidate proof passed before merge:
- disabled-billing Store runtime: SUCCESS;
- completed Store client contract: SUCCESS;
- accepted MVP-19 Store purchase mechanics: SUCCESS;
- final MVP-24 Gold retirement contract: SUCCESS;
- canonical v110 manifest normalization: SUCCESS;
- frozen monetization/economy/game owners: SUCCESS;
- tracked repository secret scan: SUCCESS.

Post-merge staging proof on the exact accepted SHA:
- Hostinger deployment readiness: SUCCESS;
- staging Telegram webhook reconciliation: SUCCESS;
- managed migrations/projection diagnostics: SUCCESS;
- TEST PLAYER A/B two-context browser flow: SUCCESS;
- final staging commit status: SUCCESS;
- GitHub Actions workflow: **Staging Playwright E2E**, run **36682313363**.

Real-money purchasing remains intentionally **not implemented** in MVP-25.
The player Store is complete in the accepted monetization-disabled state.

MVP-25.6 is **CLOSED / AUTOMATED + STAGING ACCEPTED / FROZEN**.
