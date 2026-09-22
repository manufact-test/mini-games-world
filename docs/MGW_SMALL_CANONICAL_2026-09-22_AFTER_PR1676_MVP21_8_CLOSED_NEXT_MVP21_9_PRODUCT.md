# MGW SMALL CANONICAL — MVP-21.8 CLOSED CHECKPOINT

**Date:** 2026-09-22  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging branch:** `agent/mvp-13-2-staging`  
**Runtime checkpoint SHA:** `35597596a4017b1473132049e1d8acb08b741ede`  
**Runtime checkpoint source:** merged PR **#1676**  
**Exact staging E2E:** **SUCCESS**, run **35769561682**, attempt **#3**  
**MVP-21.7:** **CLOSED**  
**MVP-21.8:** **CLOSED**  
**MVP-21 overall:** **OPEN**  
**Next implementation slice:** **MVP-21.9 product closure**

---

## What PR #1676 closed

MVP-21.8 now owns tournament cancellation and emergency stop while preserving the existing ledger, bracket, runtime-game and settlement owners.

### 1. Normal cancellation

Canonical behavior:

- admin cancellation requires a deliberate two-step confirmation;
- the backend independently requires `confirmation.mode = double_confirm`;
- every registration still belonging to the tournament is closed as cancelled;
- every participant receives the full snapshotted **50 000** entry back;
- the active official slot is released;
- no reschedule/delay branch is introduced.

### 2. Emergency stop

Emergency stop uses the same cancellation owner, with stricter input:

- mandatory reason;
- double confirmation;
- full participant refund;
- result annulment;
- durable actor/reason audit.

The MVP-21.7 terminal escalation `technical_cancel_required` is consumed here:

- ordinary cancellation is disabled for that escalated state;
- emergency stop remains available;
- no technical winner is invented.

### 3. Ledger safety

`LedgerWriteService` remains the money owner.

- active tournament reservation → canonical reservation release;
- consumed-but-unrewarded reservation → one idempotent compensating available-delta refund;
- cancellation refuses to proceed if durable tournament result rows or tournament-reward ledger payouts already exist;
- this intentionally avoids unsafe automatic prize clawback.

No direct balance edit path and no second reward/economy owner were added.

### 4. Result annulment and durable audit

Cancellation preserves evidence rather than deleting it.

It marks as annulled:

- tournament round rows;
- tournament match-attempt rows;
- tournament technical-outcome rows.

A single durable `mgw_tournament_cancellation_events` record stores:

- tournament id;
- cancellation kind;
- prior state;
- actor;
- reason;
- double-confirm mode;
- participant/refund counts and total;
- released vs consumed-refund counts;
- annulled round/attempt/technical counts;
- timestamp.

Exact replay is idempotent and cannot duplicate refunds/audit.

### 5. Settlement/progression race safety

Cancellation and settlement now serialize on the tournament row.

After cancellation/emergency:

- terminal settlement returns cancelled rather than awarding placements;
- late runtime game results are ignored by tournament progression;
- a stale client cannot revive the bracket.

### 6. Runtime cleanup

The authorized Admin cancellation path also:

- closes active tournament runtime games as no-contest;
- marks tournament runtime results annulled;
- converges runtime balances to canonical ledger balances;
- hides pending system tournament notifications.

### 7. Player/Admin UX

Tournament Admin now exposes:

- reason input;
- normal cancellation;
- emergency stop;
- second-confirm UX;
- explicit full-refund/result-annulment copy.

Participant Arena → Tournaments now shows after cancellation:

- cancelled vs emergency-stopped state;
- full entry refund;
- result annulment;
- reason;
- local close time.

---

## Schema / implementation anchors

Added migration:

`bot/database/migrations/20260922_0059_add_tournament_cancellation_emergency.php`

Added canonical owner:

`bot/tournaments/TournamentCancellationService.php`

Main integration points:

- `bot/tournaments/TournamentRegistrationService.php`
- `bot/tournaments/TournamentSettlementService.php`
- `bot/tournaments/TournamentRoundProgressionService.php`
- `bot/admin-tournaments.php`
- `bot/tournament-status.php`
- `bot/core/bootstrap.php`
- `app/admin.php`
- `app/assets/js/admin-tournaments.js`
- `app/assets/js/screens/tournaments-screen-v1.js`
- `app/runtime/client/version-manifest.php`

Focused tests:

- `bot/tests/Mvp21_8TournamentCancellationTest.php`
- `bot/tests/Mvp21_8TournamentCancellationUxContractTest.php`

Focused workflow:

`.github/workflows/mvp21-8-tournament-cancellation.yml`

---

## Verification

PR #1676 final candidate SHA:

`3b4d481073f18a462d452fffd615f9e7dbd13269`

Relevant preserved owners/checks on final PR head were green:

- unified balance;
- critical frozen-game regression;
- reconnect/timers;
- tournament Ready;
- tournament rounds/draw replay;
- MVP-21.7 technical outcomes;
- MVP-21.8 cancellation/emergency;
- MVP-21.9 settlement/terminal rewards;
- MySQL 8.4 focused tournament checks.

Merged staging SHA:

`35597596a4017b1473132049e1d8acb08b741ede`

Exact staging Playwright run:

`35769561682`

Final attempt **#3**:

- exact Hostinger deployment — success;
- migration `0059` applied, pending migrations zero;
- staging preflight — success;
- two-context browser suite — success;
- final commit status `staging-playwright-e2e` — **SUCCESS**.

Earlier attempts on this exact same SHA hit an unrelated Checkers direct-invite staging diagnostic HTTP 500; the unchanged revision passed on attempt #3. No 21.8 code change was required.

---

## Frozen boundary after this checkpoint

Do not reopen MVP-21.8 by default.

Keep:

- cancellation = explicit double confirm;
- emergency = explicit double confirm + mandatory reason;
- all registered participants = full 50 000 refund;
- no reschedule/delay;
- evidence retained but annulled;
- cancellation audit exactly once;
- settlement cannot race through cancellation;
- cancellation cannot automatically claw back already-paid tournament prizes;
- 21.7 repeated technical failure hands off to emergency;
- frozen game engines unchanged.

---

## Current MVP-21 state

### MVP-21.7
**CLOSED.**

### MVP-21.8
**CLOSED.**

### MVP-21.9
**CORE SETTLEMENT IMPLEMENTED; PRODUCT CLOSURE NEXT.**

PR #1672 remains the settlement/reward owner and already provides:

- durable placements;
- 50 000 reservation consumption;
- 200k / 80k / 50k canonical payout path;
- reward entitlements;
- Golden Ticket + repeat championship count;
- fixture reward suppression;
- terminal result screen;
- exactly-once/idempotent settlement.

Still close product-facing projections:

- Golden Ticket state and championship count;
- permanent cups/results/badges;
- champion set/exclusive achievement presentation;
- temporary crown/silver-frame/bronze-mark display and expiry;
- Profile tournament honors/history;
- tournament archive;
- tournament Hall of Fame;
- integration with 21.8 and future 21.10.

Do not create a second settlement/reward writer and do not invent sellable tournament rewards.

### MVP-21.10
**OPEN:** prize-path anti-fraud.

### MVP-21.11
**OPEN:** full 8/16/32/64/128 × eight-game tournament regression and release proof.

---

## Manual acceptance rule remains unchanged

Keep the finish-first strategy:

- every implementation slice must have focused automated verification;
- repeated Telegram manual acceptance stays deferred;
- after 21.9–21.11 are implemented, perform one consolidated fresh-tournament manual pass.

The existing completed staging tournament remains preserved until that acceptance/reset plan explicitly needs it.

---

## Exact continuation point

`35597596a4017b1473132049e1d8acb08b741ede`

**Next: MVP-21.9 product-facing reward/history/Hall projections.**

Stop before implementation until the user explicitly says to continue.
