# MVP-14R.0 — Exact JSON baseline and ownership map

## Scope

Read-only architecture record. This file does not authorize a production snapshot, rollback, deployment or runtime switch.

## Exact historical JSON baseline

### Repository baseline

- exact production commit before the DB-primary release package: `4295f42c84d28b02eae25fb9aa069ed186bde5ac`;
- latest functional commit beneath it: `c1f51e1188af12a18bd72a94cc289429f7d4960a`;
- comparison from the functional commit to the production commit is two commits with **zero changed files**;
- therefore both commits represent the same product code bytes, while `4295f42...` remains the exact production checkout identity.

### Runtime baseline

At `4295f42...`:

- `StorageFactory` supports only `json`;
- `StorageFactory::createJson()` directly returns `JsonStorageAdapter`;
- there is no production entrypoint interception that silently replaces JSON with DB-primary storage;
- the Telegram menu and `/start` path open `/app/?v=85`;
- `app/index.html` loads `main.css?v=85` and `main.js?v=86`;
- the app markup reports `v86-mvp13-runtime-controls`;
- the historical accepted client feature line is `v85-mvp12-invite-rebuild`.

### Operational confirmation

The project roadmaps recorded production at `4295f42...` as JSON-first, with DB cutover not executed. Later checkpoint `56dd3340...` independently confirmed a clean JSON runtime after failed cutover attempts, but that later code already contains part of the cutover package and is not the clean behavioral source.

### Baseline disposition

The rebuild uses two explicit identities:

1. **JSON behavior/code baseline:** `4295f42c84d28b02eae25fb9aa069ed186bde5ac` with the v85/v86 client graph.
2. **Current recoverable state checkpoint:** current production code, MySQL dump, private runtime files, deployed tree and existing JSON archive captured under `MGW_SAFETY_CHECKPOINT_2026-07-28_V109_PRE_RELATIONAL_REBUILD`.

Current user data will not be replaced by historical JSON data. Before temporary JSON recovery, current DB-primary state must be exported into a fresh verified JSON artifact.

## Why the current MySQL path is not relational

The current hot path stores the complete legacy application array in one singleton `state_json` row. A mutation locks that row, decodes the complete state, runs a legacy whole-array callback, serializes the complete state again and emits a full-state projection event.

The final MySQL architecture must instead mutate bounded domain rows. The singleton compatibility state may remain temporarily as rollback evidence, but it cannot be the serving hot path.

## Protected entrypoints and required relational owners

| Current entrypoint | Current whole-state responsibility | Target direct relational owner |
|---|---|---|
| `bot/api.php` | bootstrap, profiles, sessions, matchmaking, games, economy, history, shop and admin actions | explicit action router using account, session, matchmaking, match, ledger, history, shop and admin repositories |
| `bot/webhook.php` | Telegram updates, identities, commands, payments and admin flows | Telegram update service plus account, identity, payment and admin repositories |
| `bot/invites.php` | create, bind, accept, start, decline, cancel and synchronize invites | invite repository, invite-event repository, match repository and notification outbox |
| `bot/notifications.php` | list and mark notifications | notification repository with recipient-scoped queries and idempotent read updates |
| `bot/invite-opponents.php` | recent and available opponent list | account/opponent query repository plus presence read model |
| `bot/game-clock.php` | initialize/reset turn clock | match-turn repository using one guarded match row/version |
| `bot/game-live-v108.php` | live match state and authoritative timer reads | match read-model repository; no write and no whole-state load |
| `bot/search-speed.php` | accelerate bot fallback through queue timestamps | matchmaking queue repository and bot fallback policy |
| `bot/shop-history.php` | shop/order/history reads | shop order and ledger/history query repositories |
| `bot/cron/weekly-match.php` | eligibility and weekly Match coin grants | weekly grant repository plus ledger idempotency transaction |

## Legacy state keys and relational boundaries

| Legacy key | Relational destination |
|---|---|
| `users` | accounts, provider identities, account ownership, user profile and user settings tables |
| `games` | matches, match players, game state, turn clocks, actions and results tables |
| `queue` | matchmaking queue and queue lease tables |
| `transactions` | immutable ledger entries, balances, reservations and idempotency tables |
| `support` | support conversations/messages or explicitly archived support records |
| `shop_orders` | shop orders and order items tables |
| `payments` | payment intents, provider events and payment state tables |
| `notifications` | recipient notifications and notification event/outbox tables |
| `invites` | invitations, participants and invitation events tables |
| `system` | narrowly scoped operational metadata; no general application-state bucket |

## Client ownership map

The current v109 graph initializes modules from many historical versions. The relational rebuild freezes that graph and defines one owner per group:

| Action group | Required single owner |
|---|---|
| authenticated bootstrap and device session | one session/bootstrap controller |
| home/profile/history/shop screens | one navigation and passive-read controller |
| presence heartbeat and online count | one presence controller |
| direct invitation, link invitation and Telegram Share | one invitation controller |
| notification badge, list, toast tap and swipe | one notification controller |
| matchmaking start, polling, cancellation and bot fallback | one matchmaking controller |
| game entry, polling, action queue, timer, result and rematch | one game runtime controller |
| request priority, cancellation and retry | one transport scheduler |

No new v110-style interception layer may be added. A replacement controller must remove or bypass the previous owner from the active entry graph, not merely register earlier in capture order.

## Parity requirement

For every sub-MVP, the same fixture and action sequence must be executed against:

- the exact JSON baseline implementation;
- the new relational MySQL implementation.

The harness must compare:

- HTTP status and public response payload;
- resulting domain state;
- emitted notifications/events;
- ledger/balance effects;
- idempotency behavior under retry;
- concurrent-conflict behavior;
- request duration and database query count.

Production MySQL cutover is prohibited until automatic parity, load checks and the product owner's manual scenario acceptance are all complete.
