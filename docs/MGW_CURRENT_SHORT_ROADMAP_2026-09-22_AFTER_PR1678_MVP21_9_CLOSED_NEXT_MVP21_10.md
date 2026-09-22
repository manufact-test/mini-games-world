# MGW CURRENT SHORT ROADMAP — MVP-21.9 CLOSED

**Date:** 2026-09-22  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging:** `agent/mvp-13-2-staging`  
**Runtime checkpoint:** `7dd268ae71005819021cde2678061a9ff4997f5b`  
**Merged runtime PR:** **#1678**  
**Exact staging Playwright:** run **35772333623**, attempt **#1**, **SUCCESS**  
**MVP-21.7:** CLOSED  
**MVP-21.8:** CLOSED  
**MVP-21.9:** CLOSED  
**MVP-21 overall:** OPEN  
**Next:** MVP-21.10 prize-path anti-fraud

## What closed in MVP-21.9

The existing `TournamentSettlementService` remains the only tournament settlement/reward writer.

Player-facing durable projections now include:

- Golden Ticket state and repeat `championship_count`;
- Golden Ticket copy preserves: not sold, not transferred, valid until future Big Tournament, no fixed date/20-player promise;
- permanent winner/finalist/third-place results;
- permanent gold/silver/bronze cup representation;
- winner badge, champion-set achievement and Hall-of-Fame entitlement;
- champion crown 30 days;
- silver frame 30 days;
- bronze mark 30 days;
- expiry-aware temporary reward presentation;
- active crown/silver-frame/bronze styling projected onto Profile identity while valid;
- Profile tournament summary/history with game/date/result;
- Arena → Archive → Tournaments;
- public tournament Hall of Fame;
- reward-ineligible synthetic fixtures excluded from public competitive podium/Hall projections.

A read-only `TournamentRewardProjectionService` owns presentation reads only. It does not write ledger, settlement, rewards or inventory.

## Verification

PR #1678 final candidate:

`a0c8b10b27b3513c752392707aa05f383224e6c3`

Focused MVP-21.9 checks:

- settlement/terminal regression — SUCCESS;
- projection/integration contracts — SUCCESS;
- MySQL 8.4 settlement + projection — SUCCESS;
- MVP-20.7 Profile/archive regression — SUCCESS;
- MVP-21.5/21.6/21.7/21.8 preserved.

Merged staging:

`7dd268ae71005819021cde2678061a9ff4997f5b`

Exact staging E2E:

- run **35772333623**;
- Hostinger exact deployment — success;
- managed migrations — success;
- A/B preflight — success;
- two-context browser suite — success;
- `staging-playwright-e2e` — **SUCCESS**.

## Next — MVP-21.10

Canonical scope:

- heavy review only for top-3 and flagged matches;
- provisional reward hold only when a serious signal exists;
- admin review;
- release path;
- disqualification;
- deterministic placement shift;
- durable audit;
- exactly-once reward release through the existing settlement/reward owner;
- no second payout owner.

Manual Telegram acceptance remains deferred until MVP-21.11 is also complete.
