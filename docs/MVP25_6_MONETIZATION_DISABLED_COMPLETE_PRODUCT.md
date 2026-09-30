# MVP-25.6 — Monetization-disabled complete-product mode

**Status:** IMPLEMENTED CANDIDATE / POST-MERGE PROOF PENDING  
**Base staging:** `a90168cb84bf89631b97526684c13c2cdc8c0536`

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

MVP-25.6 closes only after the exact merged staging SHA passes the normal staging
deployment/projection and TEST PLAYER A/B Playwright proof.
