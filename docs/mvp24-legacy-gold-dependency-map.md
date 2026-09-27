# MVP-24.1 — Legacy Gold dependency map

Audit base:
- repository: `manufact-test/mini-games-world`
- integration: `agent/mvp-13-2-staging`
- exact baseline SHA: `94be005e340abfdd65469495c5f98b0e7a177734`
- exact baseline tree: `2bfff5434de1a976844d3a580c74983d4f6a0565`
- baseline `staging-playwright-e2e`: SUCCESS, run `36280050928`
- code rollback: `backup/mvp24-pre-gold-removal-green-2026-09-27`
- active Telegram launch: `bot/helpers/WebAppLaunchUrl.php -> /app/v110.php`

No schema/data deletion is part of the first removal slice. Historical financial rows, immutable ledger/audit and migration/archive evidence are preserved.

## ACTIVE RUNTIME — must be removed or narrowed in bounded slices

### Mini App
- `app/assets/js/main-v110-handoff-shell.js`
  - actively imports and initializes `store-order.js` + `store-orders.js`;
  - imports `setRoom()` and calls it after bootstrap.
- `app/assets/js/screens/store-order.js`
  - legacy Gold certificate/prize-order confirmation and request UI.
- `app/assets/js/screens/store-orders.js`
  - legacy Gold order history UI.
- `app/assets/js/screens/home-screen.js`
  - balance history still exposes legacy RUB top-ups and Match/Gold room labels;
  - `setRoom()` still writes the obsolete client room state.
- `app/assets/js/api/client.js`
  - still publishes `shopStatus`, `shopOrders`, `shopOrder`, `paymentCreateDraft`;
  - `startSearch(room, bet, ...)` still accepts obsolete client room/bet arguments although the server ignores them.
- `app/assets/js/config.js`
  - still contains `defaultRoom`, `goldBets`, `shopMinOrder`, legacy shop-history URL.
- `app/assets/js/state.js`
  - still carries obsolete `room: 'match'`.

The modern cosmetics Store is a separate owner (`bot/cosmetic-store.php` + `store-screen.js`) and MUST NOT be removed.

### Bot/API/Admin
- `bot/api.php`
  - constructs legacy `ShopService` and `PaymentService`;
  - bootstrap/profile/history/status still project legacy shop/payment reads;
  - legacy `payment_create_draft` and `shop_order` writes are already rejected by `UnifiedGameZonePolicy`;
  - canonical matchmaking ignores client room/bet and forces `storageRoom()='match'` + canonical entry cost.
- `bot/webhook.php`
  - actively wires `AdminGoldTopupNotificationGuard`;
  - actively wires `AdminPaymentRejectGuard`, which chains legacy shop-order handling.
- `bot/helpers/AdminGoldTopupNotificationGuard.php`
  - remains an active Gold write path and must be retired before MVP-24 closure.
- `bot/helpers/AdminPaymentRejectGuard.php`, `AdminShopOrderNotificationGuard.php`, `AdminShopOrderUiGuard.php`
  - legacy payment/order operator actions are still reachable from Telegram Admin.
- `bot/services/AdminService.php`
  - legacy Telegram Admin still exposes order/payment archive UI and old Match/Gold counters/copy; write actions must be removed while archive evidence stays readable.

## ARCHIVE / HISTORY ONLY — preserve data

- legacy `payments`, `shop_orders`, related `transactions`;
- immutable ledger/audit rows referring to old Match/Gold/payment/order operations;
- historical games/invites with old `room` values;
- `mgw_legacy_payments`, `mgw_legacy_shop_orders`, `mgw_legacy_financial_transactions`;
- `ops/ledger/LEGACY_FINANCIAL_ARCHIVE.md` and import/reconciliation evidence;
- old order/payment identifiers referenced by support/audit/dispute history.

These records are not product owners and are not to be physically deleted merely because Gold is obsolete.

## MIGRATION / COMPATIBILITY — preserve until separately proven removable

- `bot/runtime/UnifiedGameZonePolicy.php`
  - `storageRoom()='match'` is still the compatibility storage identity for the unified game zone;
  - Gold invite writes are explicitly rejected.
- `bot/economy/UnifiedBalance*` and `bot/economy/unified_balance_mapping.php`.
- `bot/ledger/LegacyEconomyShadowSyncService.php`,
  `LegacyEconomyDeltaImportService.php`,
  `LegacyEconomyRuntimeReconciliationService.php`.
- `bot/ledger/LegacyFinancialArchiveImportService.php` and related archive services.
- `bot/payments/RuntimePaymentRepository.php` / `PaymentRuntimeBridge.php`.
- `bot/shop/RuntimeShopRepository.php` / `ShopRuntimeBridge.php`.
- DB migrations that created historical/ledger/archive structures.

Do not remove these in the first client/product cleanup. They protect historical reconstruction, rollback, migration or DB-primary compatibility.

## PUBLIC CONTENT — stale product claims, sanitize in MVP-24.4

Confirmed repository routes/content:
- `site/blog/gold-room/index.html`;
- `site/blog/bot-and-match-coins/index.html`;
- landing/legal/sitemap must be checked for Gold/Match/certificate/cash-payout claims before redirects/removal.

Do not redesign the full site in MVP-24.

## SAFE-DELETE CANDIDATES — first bounded client slice

After focused reference checks, remove only the obsolete active player-facing owners:
- imports/init for `store-order.js` and `store-orders.js`;
- `store-order.js`, `store-orders.js` and their CSS;
- old client API methods for certificate orders / legacy payment draft / old shop history;
- old client config fields `defaultRoom`, `goldBets`, `shopMinOrder`, legacy shop-history URL;
- obsolete client `state.room` + `setRoom()`;
- player-facing legacy top-up tab/copy from balance history while retaining the underlying historical records.

Server/API/Admin removal follows as a separate bounded slice after the client graph is green.

## Safety invariants

- no `main`, production, production DB or production Cron changes;
- no DB/schema/data destructive cleanup in this slice;
- Git rollback does not roll back DB;
- unified MGW balance/ledger, modern cosmetics Store, matchmaking, invites, games, rating, tournaments, Support/Admin and notifications remain authoritative;
- if a modern path breaks, revert the removal slice to the green baseline and repair the dependency instead of adding a compatibility mask.
