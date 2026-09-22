# MGW CURRENT SHORT ROADMAP — FINISH MVP-21 BEFORE FINAL MANUAL ACCEPTANCE

**Date:** 2026-09-22  
**Repo:** `manufact-test/mini-games-world`  
**Staging:** `agent/mvp-13-2-staging`  
**Checkpoint SHA:** `5c41acf24cc1e73027071b3e45a6641f421da752`  
**Latest merged runtime PR:** **#1672**  
**Staging E2E:** **SUCCESS**  
**MVP-21:** **OPEN**

---

## New working rule

Stop doing a full user manual acceptance after every small tournament slice.

Instead:
- keep focused CI/regression mandatory for every implementation PR;
- finish the remaining MVP-21 code in sequence;
- then create a fresh staging tournament and perform one complete manual acceptance pass from registration through rewards/cancellation/fraud branches;
- fix only what that final pass reproduces.

---

## What remains

### 21.7 — complete technical outcomes
Existing partial no-show fixes are not the full slice.

Finish:
- one disconnected = 60 seconds;
- both disconnected = 3 minutes;
- manual leave;
- both absent;
- server/game failure restart/cancel rules;
- durable technical-result audit;
- correct rating/reward interaction.

### 21.8 — cancellation/emergency
Implement:
- double-confirm cancellation;
- emergency stop + mandatory reason;
- full refund;
- result annulment;
- audit;
- retry/idempotency safety.

No reschedule/delay.

### 21.9 — finish product projection
#1672 already owns terminal settlement and rewards.

Still close:
- player-facing Golden Ticket state;
- permanent cups/results/badges in intended profile/archive/Hall surfaces;
- temporary crown/silver-frame/bronze-mark display and expiry;
- Hall of Fame projection;
- integration with cancellation and anti-fraud.

Manual acceptance of payout/result is intentionally deferred to the final pass.

### 21.10 — prize-path anti-fraud
Implement:
- top3/flagged-match review path;
- serious-signal provisional hold only;
- admin review;
- disqualification;
- placement shift;
- audit;
- release/settlement remains exactly once.

### 21.11 — full regression/release proof
Prove:
- 8 / 16 / 32 / 64 / 128;
- all eight games;
- draw/replay;
- no-show/disconnect;
- cancellation/emergency/refund;
- duplicate settlement;
- date/time/localization;
- complete automatic lifecycle without manual DB intervention.

---

## Final manual pass after implementation

Run one fresh tournament and check:
registration/reservation → cancel registration → full/close → schedule/reminders → Hall/presence → Ready → synchronized countdown → game → round break → draw replay → disconnect/no-show → final/third place → terminal result → payout/rewards → reopen idempotency → cancellation/emergency → anti-fraud review → Admin Reset/focus → fixture cleanup.

Also explicitly recheck:
- visible countdown ticks every second;
- Admin Reset does not poison Telegram input focus;
- test fixtures receive zero competitive reward;
- player-facing permanent/temporary reward surfaces are correct.

---

## Start next work

**Next implementation task: MVP-21.7 full technical-outcome audit and completion.**

Do not reset architecture or reimplement 21.1–21.6. Reuse the current progression/readiness/game-settlement owners and fill only the missing 21.7 contract.
