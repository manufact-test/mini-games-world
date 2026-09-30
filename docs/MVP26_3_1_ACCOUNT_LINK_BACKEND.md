# MVP-26.3.1 — existing-account link backend

Parent staging: `60c3e25ac4bf695e47da4049e0cca888450562b5`.

## Goal

Allow a fresh Android identity to attach to an already existing Telegram-backed MGW account without creating a second economy/profile owner.

## Proof-of-control flow

1. authenticated Android session creates a short-lived one-time challenge;
2. raw challenge token is sent only in a Telegram deep link and only its SHA-256 is persisted;
3. the existing Telegram identity claims the challenge;
4. Telegram shows an explicit confirmation button;
5. the same Telegram identity confirms;
6. the original authenticated Android session finalizes;
7. Android identity/device/session move to the existing target MGW account.

Neither side alone can complete the link.

## Pristine-only rule

Automatic linking is allowed only while the temporary Android account is still disposable:
- only its Android identity;
- only starter inventory;
- no game/match/queue/invite/social/report/support/moderation/tournament/rating/compensation activity;
- no active reservations;
- runtime balance exactly the canonical starter amount;
- exactly one starter welcome transaction;
- zero gameplay/stat/economy activity.

Any real activity fails closed. There is no heuristic merge.

## Economy rule

The temporary Android starter balance is never transferred to the Telegram account.

If its DB projection contains the starter 1000 coins, they are retired through the canonical append-only `LedgerWriteService` with category `account_link_retirement`. The target balance is untouched.

## Ownership rule

After link:
- target MGW ID remains canonical;
- Telegram identity remains attached to target;
- Android identity is attached to target;
- Android session resolves the target's existing runtime legacy user id;
- temporary Android MGW shell becomes `linked_retired` audit history;
- its active runtime JSON state and ownership locator are retired.

## Recovery

The DB identity move establishes `db_linked` before JSON/ledger cleanup. If cleanup fails, the same Android session already resolves the target account and finalize can retry idempotently.

## Boundaries

Staging only for MVP-26.3.1. No main, production runtime, production DB, production Cron, game-engine, Store, rating or tournament semantic changes.
