# MGW SMALL CANONICAL — AFTER PR #1680 / MVP-21.10 CLOSED

**Date:** 2026-09-22  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging:** `agent/mvp-13-2-staging`  
**Runtime PR:** #1680  
**Runtime staging SHA:** `e71d6c5bc968cbd0f6895816e74c6d3839ec4da2`  
**MVP-21.7:** CLOSED  
**MVP-21.8:** CLOSED  
**MVP-21.9:** CLOSED  
**MVP-21.10:** CLOSED  
**MVP-21 overall:** OPEN  
**Next:** MVP-21.11 full regression / release proof

## Frozen MVP-21.10 contract

- Heavy prize review is not automatic. It is allowed only for top-3 or a participant from an explicitly flagged tournament match.
- A serious signal creates a provisional review state and holds only the affected prize path.
- Tournament entry reservation settlement remains canonical and exactly-once.
- `TournamentSettlementService` remains the only tournament result/reward/ledger writer.
- `TournamentPrizeReviewService` owns review state and durable audit only. It must never pay rewards, consume reservations, grant entitlements or create Golden Tickets.
- Admin review supports **release** and **disqualification**.
- Release retries the existing settlement owner with the existing payout idempotency key.
- Disqualification produces deterministic effective placement shift; canonical bracket history is not rewritten.
- Disqualified participant gets no competitive payout or entitlement.
- Durable audit stores serious signal and Admin resolution.
- Late serious signals after settlement has started fail closed; MVP-21 does not introduce prize clawback logic.

## Verification

PR #1680 final candidate: `188c54eff59bc0908a381af7110e92a086a91dfc`.

Green on the final PR candidate:

- MVP-21.10 focused SQLite contract;
- MVP-21.10 MySQL 8.4;
- MVP-21.5 Ready;
- MVP-21.6 rounds/draw replay;
- MVP-21.7 technical outcomes;
- MVP-21.8 cancellation/emergency;
- MVP-21.9 settlement/rewards/product projections;
- rating/Profile/Hall regressions;
- all-game frozen-mechanics baseline.

Exact staging E2E run **#35778351182**, final attempt **#3**: **SUCCESS**.

- exact Hostinger deployment — success;
- managed migration `20260922_0060_create_tournament_prize_review` — success;
- A/B preflight — success;
- full two-context staging browser suite — success;
- final publisher — success;
- commit status `staging-playwright-e2e` — **SUCCESS**.

An earlier attempt on the same staging SHA failed in an unrelated Checkers direct-invite path. The clean retry on the exact same runtime SHA passed, so no MVP-21.10 product defect remained reproduced.

## Next boundary — MVP-21.11

Required release proof:

- capacities **8 / 16 / 32 / 64 / 128**;
- all **8 games**;
- complete tournament lifecycle without manual DB intervention;
- draw + 1-minute replay + side swap;
- one-player no-show/disconnect and both-player absence/disconnect paths;
- technical restart/cancel path;
- cancellation and emergency stop;
- duplicate settlement / retry idempotency;
- rewards and synthetic-fixture reward exclusion;
- date/time/localization assertions;
- all existing MVP-21.1–21.10 contracts preserved.

Do not start the final manual Telegram acceptance until MVP-21.11 is green.
