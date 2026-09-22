# MGW CURRENT SHORT ROADMAP — AFTER MVP-21.7 CLOSED

**Date:** 2026-09-22  
**Repo:** `manufact-test/mini-games-world`  
**Staging:** `agent/mvp-13-2-staging`  
**Runtime checkpoint:** `3ebffe7a7ad20b1b6b5c694708e2f0a89c96bbef`  
**Merged runtime PR:** **#1674**  
**MVP-21.7:** **CLOSED**  
**MVP-21:** **OPEN**  
**Next:** **MVP-21.8**

---

## Closed now — 21.7 technical outcomes

Implemented and tested:

- one disconnected participant → **60 sec** reconnect;
- both tournament participants disconnected → shared **3 min** reconnect;
- one returns / one remains absent → technical win for returned participant;
- neither returns → terminal no-winner technical result;
- manual leave → technical loss;
- both absent at T0 → terminal no-winner row;
- vacant slots propagate without fake players/winners;
- technical outcomes do not award visible rating points;
- first server/game technical failure → one restart after **1 min**, same sides;
- repeated technical failure → `technical_cancel_required`, no invented result, no next round;
- durable idempotent technical-outcome audit;
- settlement ignores null/vacant placements;
- player-facing technical-result copy added.

All eight game engines remain frozen.

Validation is green on focused SQLite + MySQL 8.4, reconnect, Ready, rounds, terminal settlement, critical-game and unified-balance owners.

Exact staging Playwright is green on runtime SHA `3ebffe7a7...` (run `35765046358`, final attempt #3).

---

## Next — 21.8 cancellation / emergency stop

Implement only the canonical 21.8 owner:

- normal cancellation with double confirmation;
- emergency stop with mandatory reason;
- full refund of the tournament entry;
- result annulment;
- durable audit;
- idempotent retries;
- safe handling of every registration/reservation state;
- consume 21.7 `technical_cancel_required`;
- no second ledger/reward owner.

**Out of scope:** reschedule/delay.

After 21.8 is fully green: stop, write a new canonical + short-roadmap checkpoint, then ask before continuing.

---

## Then

### 21.9 product closure
Keep #1672 as settlement owner. Finish:

- Golden Ticket player-facing visibility;
- permanent cups/results/badges;
- temporary crown/silver-frame/bronze-mark presentation + expiry;
- tournament Hall of Fame/profile/archive projection;
- cancellation and anti-fraud integration.

### 21.10
Prize-path anti-fraud:

- serious-signal provisional hold only;
- top3/flagged match review;
- admin review;
- disqualification;
- placement shift;
- durable audit.

### 21.11
Final automated release proof:

- 8 / 16 / 32 / 64 / 128;
- all eight games;
- draws/replays;
- no-show/disconnect;
- cancellation/emergency/refund;
- technical restart/cancel;
- duplicate settlement;
- localization/date-time;
- automatic lifecycle without manual DB intervention.

---

## Final manual acceptance remains deferred

Only after 21.7–21.11 are implemented:

fresh registration → reservation/cancel → full roster → schedule/reminders → Hall → Ready → synchronized countdown → gameplay → round breaks → draw replay → no-show/disconnect → technical failure → final/third-place → terminal settlement/rewards → cancellation/emergency → anti-fraud → Admin Reset/focus → fixture cleanup.

---

## Continue from

`3ebffe7a7ad20b1b6b5c694708e2f0a89c96bbef`

**Do not start 21.8 until the user says to continue.**
