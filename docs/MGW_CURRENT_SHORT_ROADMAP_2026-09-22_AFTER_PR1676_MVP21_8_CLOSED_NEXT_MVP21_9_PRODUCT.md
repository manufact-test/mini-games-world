# MGW CURRENT SHORT ROADMAP — AFTER MVP-21.8 CLOSED

**Date:** 2026-09-22  
**Repo:** `manufact-test/mini-games-world`  
**Staging:** `agent/mvp-13-2-staging`  
**Runtime checkpoint:** `35597596a4017b1473132049e1d8acb08b741ede`  
**Merged runtime PR:** **#1676**  
**MVP-21.7:** **CLOSED**  
**MVP-21.8:** **CLOSED**  
**MVP-21:** **OPEN**  
**Next:** **MVP-21.9 product closure**

---

## Closed now — MVP-21.8 cancellation / emergency stop

Implemented and tested:

- normal tournament cancellation requires deliberate **double confirmation**;
- emergency stop requires the same double confirmation plus a **mandatory reason**;
- every still-registered participant receives the full **50 000** entry refund;
- active reservations are released through the canonical `LedgerWriteService`;
- a consumed-but-not-yet-rewarded entry has one idempotent refund path;
- cancellation fails closed if tournament settlement/reward payout already exists, so no unsafe prize clawback is attempted;
- durable round rows, game attempts and technical-outcome evidence remain in audit but are explicitly **annulled**;
- one durable cancellation event records actor, reason, refund totals and annulment counts;
- exact cancellation retry is idempotent;
- cancellation and terminal settlement serialize on the tournament row;
- stale/late runtime game finishes cannot revive a cancelled tournament;
- active runtime tournament games are closed as no-contest and marked annulled;
- ledger balances are projected back into runtime after refund;
- pending tournament notifications are hidden after cancellation;
- participant UI shows cancellation/emergency status, full refund, annulled results and reason;
- MVP-21.7 `technical_cancel_required` disables normal cancellation and hands off to emergency stop;
- **reschedule/delay remains out of scope**;
- frozen game engines remain unchanged.

---

## Validation

PR **#1676** final head:

`3b4d481073f18a462d452fffd615f9e7dbd13269`

Relevant PR-head checks are green:

- MVP-15.3 unified balance foundation;
- MVP-16.6 critical game regressions;
- MVP-17.4 reconnect/timers;
- MVP-21.5 Ready / first match;
- MVP-21.6 rounds / draw replay;
- MVP-21.7 technical outcomes;
- MVP-21.8 cancellation / emergency — focused regression + MySQL 8.4;
- MVP-21.9 settlement / terminal rewards.

Merged runtime SHA:

`35597596a4017b1473132049e1d8acb08b741ede`

Exact staging Playwright:

- run **35769561682**;
- final successful attempt: **#3**;
- Hostinger exact-deployment wait — success;
- managed migrations — success;
- staging A/B preflight — success;
- two-context browser suite — success;
- final `staging-playwright-e2e` commit status — **SUCCESS**;
- macOS fallback skipped.

Earlier attempts on the same exact SHA hit the unrelated Checkers staging direct-invite diagnostic with HTTP 500. The unchanged SHA passed attempt #3, so no deterministic MVP-21.8 regression remained.

---

## Next — MVP-21.9 product closure

Keep PR **#1672** / `TournamentSettlementService` as the only settlement/reward writer.

Do not create a second reward owner.

Finish the player-facing projections already backed by the durable 21.9 state:

- Golden Ticket state + championship count;
- permanent tournament cups/results/badges;
- champion set / exclusive champion achievement presentation without inventing a new sellable SKU;
- champion crown **30 days**;
- silver frame **30 days**;
- bronze mark **30 days**;
- expiry-aware presentation of temporary tournament styling;
- tournament history with date/place/result;
- tournament Hall of Fame;
- tournament archive projection;
- Profile projection;
- integration with the now-closed 21.8 cancellation boundary;
- preserve compatibility with upcoming 21.10 prize-path anti-fraud.

Manual payout/balance/reopen acceptance remains deferred to the consolidated final pass.

---

## Then

### MVP-21.10 — prize-path anti-fraud

- heavy review only for top-3 and flagged matches;
- provisional reward hold only on a serious signal;
- admin review;
- disqualification;
- placement shift;
- durable audit;
- exactly-once release/settlement through the existing reward owner.

### MVP-21.11 — full tournament regression

Prove automatically:

- **8 / 16 / 32 / 64 / 128**;
- all eight games;
- draw/replay;
- no-show/disconnect;
- technical restart/cancel;
- cancellation/emergency/refund;
- duplicate settlement;
- date/time/localization;
- full lifecycle without manual DB intervention.

All five sizes remain required before public MVP-21 closure.

---

## Final manual acceptance remains deferred

After 21.9–21.11 are green, run one fresh consolidated Telegram acceptance:

registration/reservation → cancel registration → full roster → schedule/reminders → Hall → Ready → synchronized countdown → gameplay → round break → draw replay → no-show/disconnect → technical restart/emergency → final/third-place → terminal settlement/rewards → persistent Profile/archive/Hall rewards → anti-fraud review → reopen/idempotency → Admin Reset/focus → fixture cleanup.

---

## Continue from

`35597596a4017b1473132049e1d8acb08b741ede`

**Next implementation task: MVP-21.9 product-facing reward/history/Hall projections.**

Stop here before starting 21.9 unless the user explicitly says to continue.
