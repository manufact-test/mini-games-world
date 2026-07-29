# MVP-14R.2.5 — economy and supporting domains JSON baseline

## Purpose

This package freezes the current JSON behavior of balances, settlement history, the prize shop, draft payments and the weekly Match bonus before later storage migration or performance work. It is a code-and-CI-only harness and is not loaded by the production bootstrap.

## Frozen scenarios

### Economy and history

1. **Match win and history**
   - reserves 10 Match coins from both players;
   - settles a 20-coin bank with 2 coins commission and 18 coins payout;
   - releases both players and updates statistics once;
   - exposes winner and loser operation history;
   - rejects duplicate settlement effects.

2. **Gold draw and history**
   - reserves 20 Gold from both players;
   - counts Gold wager turnover;
   - refunds both stakes on a draw;
   - charges no commission;
   - exposes the refund and entry in history.

3. **Insufficient balances**
   - rejects both Match and Gold starts with the current public error;
   - creates no game, transaction or partial debit.

### Prize shop

4. **Order creation and completion**
   - normalizes enabled catalog countries, items and denominations;
   - validates the selected price against the active catalog;
   - debits available Gold and records ownership/snapshot data;
   - replays an identical request key without creating another order or ledger row;
   - completes a pending order once.

5. **Order rejection and refund**
   - debits Gold when the order is created;
   - refunds the exact amount once when rejected;
   - restores `gold_shop_spent_total`;
   - blocks an incompatible later completion.

### Draft payments

6. **Payment apply once**
   - creates a draft-only payment request;
   - does not change the balance before an admin decision;
   - applies the configured coin rate once;
   - updates deposited totals and writes one `payment_apply` row;
   - blocks repeat apply and rejection after payment.

7. **Rejected and cancelled payments**
   - rejects one draft with a reason;
   - models a separate cancelled terminal request;
   - keeps balances unchanged;
   - blocks application of both terminal states.

No external payment provider is called by any fixture.

### Weekly Match bonus

8. **Eligibility and timezone boundary**
   - uses `Europe/Warsaw` and Monday 12:00;
   - counts only finished Match-room games in the half-open qualifying interval;
   - excludes Gold games, out-of-window games and development users;
   - awards exactly +50 Match coins after at least three qualifying games;
   - leaves an ineligible user unchanged;
   - writes one `weekly_bonus` transaction and one notification;
   - makes a repeated run report `already_checked` without a duplicate grant;
   - verifies the exact next-week boundary.

## Determinism and integrity

Every fixture uses a fixed clock and deterministic IDs. The complete workflow is executed twice from the same state and must produce identical payloads, domain snapshots, side effects and SHA-256 fingerprints.

Rejected actions must leave the complete state unchanged. Financial scenarios also assert exact ledger order, one-time balance effects and one-time settlement markers.

## Source contracts

The source-contract test binds the model to the current owners of behavior:

- `GameService` and `GameSettlementService`;
- `HistoryService`;
- `UserService`;
- `ShopCatalogService` and `ShopService`;
- `AdminService`;
- `PaymentService`;
- `WeeklyMatchEconomyService`.

A source change that alters reservation, settlement, history, ownership, payment idempotency or weekly qualification must update the baseline deliberately.

## Latency boundary

This part freezes behavior only. It keeps:

- `latency.measured = false`;
- `samples = 0`;
- `cold_ms = null`;
- `warm_ms = null`.

Cold/warm measurements and product acceptance belong to MVP-14R.2.6.

## Safety boundary

The package does not:

- load from the production bootstrap;
- contact production, Telegram, a payment provider or any other network service;
- write a database or live JSON;
- change private configuration;
- change webhook registration or Cron;
- deploy anything;
- update issue #174.
