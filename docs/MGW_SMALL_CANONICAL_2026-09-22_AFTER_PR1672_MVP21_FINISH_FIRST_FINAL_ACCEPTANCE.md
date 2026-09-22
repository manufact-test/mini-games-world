# MGW SMALL CANONICAL — MVP-21 FINISH-FIRST CHECKPOINT

**Date:** 2026-09-22  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging branch:** `agent/mvp-13-2-staging`  
**Runtime checkpoint SHA:** `5c41acf24cc1e73027071b3e45a6641f421da752`  
**Runtime checkpoint source:** merged PR **#1672**  
**Exact staging E2E on this SHA:** **SUCCESS**  
**Status:** **MVP-21 remains OPEN.**

---

## Strategy decision

From this checkpoint forward, finish the remaining MVP-21 implementation first and perform one consolidated manual acceptance pass afterward.

Do **not** treat this as permission to skip automated/CI tests while implementing. Each slice still needs focused contracts/regressions before merge. The deferred part is the repeated user-facing Telegram manual acceptance after every small slice.

Final manual acceptance will walk the complete tournament lifecycle end-to-end and then fix anything reproduced.

---

## Current implementation state

### MVP-21.1–21.6
Core official tournament lifecycle is implemented and has already received substantial manual acceptance/corrective work:

- tournament creation + 50 000 entry reservation;
- rules consent and automatic close;
- date/time and reminders;
- Hall/presence/bracket;
- Ready and synchronized launch;
- rounds/breaks/draw replay;
- final + third-place match;
- staging fixture helpers;
- MySQL and cold-start corrective chain.

These slices are **not being reopened by default**, but they must be included in the final integrated acceptance pass.

### MVP-21.7 — PARTIAL / OPEN
Some no-show behavior already exists from the corrective chain:
- mixed real + synthetic fixture later-round pair receives a staging technical bye;
- one-sided real preparation timeout can advance the only participant that adopted the game instead of generating a false draw replay.

Still must be completed against the master contract:
- one disconnected participant: 60-second rule;
- both disconnected: 3-minute rule;
- manual leave;
- both absent branch;
- server/game failure restart/cancel rules;
- durable technical-outcome audit and correct interaction with rating/rewards/settlement.

### MVP-21.8 — OPEN
Must implement:
- normal tournament cancellation with double confirmation;
- emergency stop with mandatory reason;
- full refund;
- result annulment;
- durable audit;
- idempotent replay/retry safety.

Reschedule/delay remains explicitly out of scope.

### MVP-21.9 — IMPLEMENTED CORE, PRODUCT CLOSURE STILL OPEN
PR **#1672** implemented and merged:
- durable 1/2/3/4 placement settlement;
- exact 50 000 reservation consumption;
- canonical payouts;
- exactly-once/idempotent settlement;
- permanent/temporary tournament reward entitlement records;
- Golden Ticket and repeat `championship_count`;
- reward-ineligible synthetic staging fixtures;
- terminal podium/result/reward UI;
- post-settlement staging reset compatibility;
- SQLite + MySQL 8.4 focused regression;
- exact staging Playwright success.

Still must be verified/completed before declaring 21.9 closed:
- actual product-facing permanent result/cup/badge visibility outside the terminal screen where required by final UI;
- Hall of Fame/profile/archive projection;
- temporary crown/frame/bronze-mark lifecycle and expiry presentation;
- Golden Ticket visibility/state in the intended player-facing surface;
- live manual payout/balance/reopen acceptance;
- interaction with later 21.8 cancellation and 21.10 anti-fraud.

### MVP-21.10 — OPEN
Master contract:
- heavy anti-fraud only for top-3 and flagged matches;
- provisional reward hold only on serious signal;
- admin review;
- disqualification;
- placement shift;
- audit.

This must integrate with the 21.9 settlement owner without creating a second reward owner.

### MVP-21.11 — OPEN
Full tournament regression and release proof:
- sizes **8 / 16 / 32 / 64 / 128**;
- all eight games;
- draws;
- absences;
- disconnects;
- cancellation;
- emergency;
- duplicate settlement;
- date/time/localization.

Release rule: no intentional public release limited to only 8/16. All five sizes must be proven before MVP-21 is closed.

---

## Deferred final manual acceptance checklist

After 21.7–21.11 implementation is green, run one consolidated staging acceptance from a fresh tournament.

Check in order:

1. Admin create/open tournament; rules are correct; 50 000 is reserved once.
2. Player can cancel registration while open; reserve is released correctly.
3. Last-seat/full behavior is atomic; no ninth participant.
4. Date/time is correct in local UI; day/hour/15-minute reminders arrive once.
5. Hall opens at the intended window; presence syncs; bracket freezes at T0.
6. Admin Reset focus: after reset, title/date field works immediately without minimizing Telegram.
7. Fixture lifecycle: prepare 6/8, complete fixture-only pairs, mixed fixture technical bye, safe reset/retire.
8. Ready window: two-minute Ready, both-ready state, locked board, synchronized 10-second countdown, gameplay timer starts only afterward.
9. Round lifecycle: wait for all matches, five-minute break, visible countdown ticks each second, next round appears.
10. Draw branch: one-minute replay, side swap, no repeat fee.
11. No-show/disconnect: one disconnected 60 sec, both disconnected 3 min, manual leave, both absent, technical result.
12. Server/game failure branch: restart/cancel behavior is deterministic and auditable.
13. Final + third-place match produce canonical placements.
14. Terminal result clearly shows champion/top3/self placement and next action.
15. Economy: payout/reservation finalization happens exactly once; reopen/heartbeat cannot duplicate it.
16. Rewards: Golden Ticket, permanent result/cup/badge and temporary style rewards are visible in their intended surfaces; expiry behavior is correct.
17. Synthetic staging fixtures never receive real competitive rewards.
18. Cancellation/emergency: double confirm/reason/full refund/result annulment/audit.
19. Anti-fraud: serious flag holds only affected prize path, admin review can release/disqualify/shift placements, audit remains durable.
20. Full regression proof for 8/16/32/64/128 and all eight games, plus localization/date-time and duplicate-settlement cases.

---

## Frozen boundaries

While finishing MVP-21:
- do not alter accepted game mechanics casually;
- do not create a second economy/ledger/tournament/reward owner;
- do not change the canonical 50 000 entry semantics except through the defined cancellation/refund branches;
- do not move tournament UI outside Arena → Tournaments;
- do not deploy main/production/Cron/live DB changes without explicit authorization;
- do not remove staging fixture helpers before final acceptance is complete.

---

## Exact continuation point

Continue from staging:

`5c41acf24cc1e73027071b3e45a6641f421da752`

Execution order from this checkpoint:

**finish MVP-21.7 → MVP-21.8 → complete remaining MVP-21.9 product projections → MVP-21.10 → MVP-21.11 → one consolidated manual Telegram acceptance → corrective fixes if reproduced → close MVP-21.**
