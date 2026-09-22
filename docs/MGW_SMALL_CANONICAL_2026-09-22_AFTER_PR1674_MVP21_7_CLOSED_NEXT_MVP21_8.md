# MGW SMALL CANONICAL — MVP-21.7 CLOSED CHECKPOINT

**Date:** 2026-09-22  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging branch:** `agent/mvp-13-2-staging`  
**Runtime checkpoint SHA:** `3ebffe7a7ad20b1b6b5c694708e2f0a89c96bbef`  
**Runtime checkpoint source:** merged PR **#1674**  
**MVP-21.7:** **CLOSED**  
**MVP-21 overall:** **OPEN**  
**Next implementation slice:** **MVP-21.8 — cancellation and emergency stop**

---

## What #1674 closed

MVP-21.7 now owns the missing no-show/disconnect/technical-outcome branches without creating a second tournament, rating or reward owner.

### 1. One disconnected participant — 60 seconds

The existing reconnect owner remains authoritative.

- one tournament participant disconnected: canonical **60-second** reconnect window;
- gameplay clock remains frozen by the existing reconnect lifecycle;
- if the player returns in time, the match resumes;
- if the 60-second window expires while the opponent remains present, the present opponent receives a technical bracket win;
- technical finish reasons remain excluded from visible rating points by the existing `PerGameRatingService` rule that rates only `normal_win` and `draw`.

### 2. Both disconnected — shared 3-minute branch

Tournament matches now have a dedicated shared dual-disconnect branch:

- when both tournament participants are disconnected, the reconnect owner switches the pair to a shared **180-second / 3-minute** deadline;
- this does not alter ordinary non-tournament reconnect behavior;
- if both return, the same match resumes;
- if one returns and the other remains absent until the shared deadline, the returned participant receives the technical bracket win;
- if neither returns by the shared deadline, the runtime match closes with **no winner** and the bracket does not invent one.

### 3. Manual leave

The existing game surrender path remains the owner:

- explicit leave/surrender finishes the match with `player_left`;
- the opponent advances by technical result;
- the tournament progression layer records the technical outcome durably;
- no visible-rating points are awarded for the technical result.

### 4. Both absent at tournament start

The old unresolved `both_absent_at_start_pending` branch is closed.

Now:

- both-absent first-round pair is terminal immediately;
- `winner_mgw_id` remains null;
- result reason is `both_absent_at_start`;
- no fake winner is created;
- the vacancy propagates structurally through later rounds.

This removes the old requirement for a production-side manual winner choice while preserving the bracket shape.

### 5. Vacant bracket propagation

`mgw_tournament_round_matches` now supports nullable player slots where the tournament rules genuinely produce a vacancy.

Progression behavior:

- two real participants → normal Ready/round flow;
- one real participant + one vacant slot → deterministic technical bye;
- two vacant slots → completed vacant bracket row;
- final and third-place structure still materialize without inventing participants or placements.

Terminal settlement was adjusted accordingly: only real non-null terminal placements are rewarded.

### 6. Server/game technical failure

Technical infrastructure failure is no longer treated as a draw.

Canonical boundary implemented in 21.7:

1. first `server_failure`, `game_failure` or `technical_failure`:
   - same pair;
   - same sides;
   - no draw side swap;
   - **1-minute technical restart wait**;
   - new deterministic attempt id;
2. repeated technical failure on the restart:
   - pair enters `technical_cancel_required`;
   - no winner is invented;
   - no next round is created;
   - durable reason is `technical_restart_exhausted`;
   - control intentionally hands off to **MVP-21.8 cancellation/emergency owner**.

This keeps 21.7 from silently inventing a result and keeps full-refund/result-annulment ownership in 21.8.

### 7. Durable technical-outcome audit

Migration:

`bot/database/migrations/20260922_0058_add_tournament_technical_outcomes.php`

adds `mgw_tournament_technical_outcomes` with idempotent event keys.

Audited branches include:

- T0 one-sided no-show;
- T0 both absent;
- manual leave;
- single disconnect timeout;
- both-disconnect timeout;
- technical restart;
- repeated technical failure / cancellation-required escalation;
- automatic technical bye;
- fully vacant bracket slot.

The migration also preserves the caller's SQLite foreign-key mode; this was required to keep the unified-balance legacy identity regression green.

---

## Player-facing tournament UI

Arena → Tournaments now has explicit technical-result copy for:

- no-show;
- manual leave;
- disconnect timeout;
- both absent;
- technical bye;
- vacant bracket slot;
- technical restart;
- repeated technical failure requiring cancellation.

Vacant rows render as a vacant slot instead of a fake player.

No frozen game-engine code under `bot/games` was changed.

---

## Validation

### Focused MVP-21.7 gate

Workflow:

`.github/workflows/mvp21-7-tournament-technical-outcomes.yml`

Final PR-head result:

- **SQLite focused regression — SUCCESS**
- **MySQL 8.4 technical-outcome schema/regression — SUCCESS**

The focused test covers:

- T0 both absent and vacancy propagation;
- manual leave;
- single disconnect technical win;
- duplicate observer idempotency;
- 60-second single reconnect;
- 3-minute dual reconnect;
- one-return/one-missing;
- both-missing;
- technical restart;
- repeated failure escalation;
- rating exclusion;
- all-game frozen baseline.

### Preserved owners/regressions on final PR head

Also green after the final fixes:

- **MVP-21.5 Tournament Ready — SUCCESS**
- **MVP-21.6 Rounds / draw replays — SUCCESS**
- **MVP-21.9 settlement / terminal rewards — SUCCESS**
- **MVP-21.9 MySQL 8.4 settlement — SUCCESS**
- **MVP-17.4 reconnect/timers — SUCCESS**
- **MVP-16.6 critical game regressions — SUCCESS**
- **MVP-15.3 unified balance foundation — SUCCESS**

### Exact staging proof

Merged runtime SHA:

`3ebffe7a7ad20b1b6b5c694708e2f0a89c96bbef`

Staging Playwright run:

`35765046358`

Final attempt **#3**:

- Hostinger deployment wait — success;
- managed staging migrations — success;
- staging A/B preflight — success;
- exact two-context Playwright suite — success;
- final `staging-playwright-e2e` commit status — **SUCCESS**.

Attempts #1 and #2 hit unrelated transient staging-test failures on the same SHA (first a synthetic Player B auth 403 in the Checkers diagnostic, then a transient `rating-archive.php` 500). The same exact runtime revision passed on attempt #3 without a code change, so no deterministic MVP-21.7 regression remained.

---

## Frozen boundaries after this checkpoint

Do not reopen 21.7 by default.

Keep:

- single disconnect = 60 seconds;
- both tournament players disconnected = shared 3 minutes;
- manual leave = technical loss;
- both absent = no invented winner;
- technical outcome = no visible rating points;
- first infrastructure failure = one technical restart;
- repeated infrastructure failure = `technical_cancel_required` handoff to 21.8;
- durable audit exactly once;
- frozen eight game engines unchanged.

---

## MVP-21 state from here

### MVP-21.7
**CLOSED.**

### MVP-21.8
**NEXT / OPEN.**

Implement the master contract:

- normal tournament cancellation with double confirmation;
- emergency stop with mandatory reason;
- full refund;
- result annulment;
- durable audit;
- retry/idempotency safety;
- consume the `technical_cancel_required` boundary created by 21.7.

No reschedule/delay.

### MVP-21.9
Core settlement/reward owner from #1672 remains implemented and preserved by #1674.

Still needs later product-closure work already listed in the previous checkpoint:

- Golden Ticket player-facing state;
- permanent tournament cups/results/badges in intended Profile/archive/Hall surfaces;
- temporary reward presentation/expiry;
- Hall of Fame projection;
- final integration with 21.8 and 21.10.

### MVP-21.10
Still open: prize-path anti-fraud.

### MVP-21.11
Still open: full tournament regression/release proof.

---

## Manual acceptance rule

The project remains on the finish-first strategy:

- focused automated verification is mandatory per slice;
- repeated Telegram manual acceptance is deferred;
- after 21.7–21.11 are implemented, run one consolidated fresh-tournament manual acceptance pass.

---

## Exact continuation point

Runtime checkpoint:

`3ebffe7a7ad20b1b6b5c694708e2f0a89c96bbef`

**Next task: MVP-21.8 cancellation and emergency stop.**

Stop here before starting 21.8 unless the user explicitly says to continue.
