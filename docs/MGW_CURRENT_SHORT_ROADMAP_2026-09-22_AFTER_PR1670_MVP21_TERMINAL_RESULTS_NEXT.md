# MGW CURRENT SHORT ROADMAP — MVP-21 TOURNAMENT CHECKPOINT AFTER PR #1670

**Date:** 2026-09-22  
**Repo:** `manufact-test/mini-games-world`  
**Staging branch:** `agent/mvp-13-2-staging`  
**Runtime checkpoint SHA:** `67e3e85168550ba67954e12f5336293c1cca24ad`  
**Exact staging E2E:** **SUCCESS**  
**Current state:** tournament manual scenario reaches **full bracket completion**, but **MVP-21 is still OPEN**.

---

## WHERE WE ARE NOW

The current staging tournament has been played/progressed all the way through:

- Round 1 completed.
- Round 2 created and completed.
- Final + third-place match created.
- Final round shows **2/2 completed**.
- Hall shows **«Все матчи турнира завершены»**.

Current bracket placement visible in UI:
1. **НеОлег**
2. **Тестовый участник 2**
3. **Тестовый участник 1**
4. **Тестовый участник 5**

This is bracket evidence only. Reward/ledger settlement is not yet accepted.

---

## ACCEPTED IN THIS LONG CORRECTIVE RUN

- Cold start reduced from roughly 10–16 s to roughly 3.3–3.4 s and manually accepted.
- First finished live tournament game now advances instead of staying `Матч запущен / Готов`.
- Hostinger MySQL `HY093` participant lookup bug fixed.
- Red post-match tournament error removed.
- Fixture-only Admin helper proven to advance the real bracket.
- Round 2 visible to winners and eliminated participants.
- Eliminated participant sees explicit eliminated state.
- Start bracket archive no longer auto-closes on heartbeat.
- Mixed real + fixture pair no longer loops through fake no-show/draw/replay games.
- Later-round real participant receives staging technical bye against synthetic fixture.
- One-sided real preparation timeout advances the only actually-ready participant instead of scheduling a draw replay.
- Final and third-place matches are created correctly.
- Final round can reach 2/2 completed.
- Round-grid spacing corrected.
- Smooth 1-second countdown code merged in #1670 and exact staging E2E is green.

---

## NEXT — DO THIS FIRST

### 1. Fix terminal tournament completion UX

Current terminal screen is too ambiguous.

Need to design/implement a clear **«Итоги турнира»** state that answers immediately:

- who won;
- who took 2nd place;
- who took 3rd place;
- what the current player’s place is;
- what prize/reward was actually credited;
- whether the tournament is fully closed;
- what action is available next.

The raw final bracket may remain as history/archive, but it should not be the only explanation of the terminal state.

For the current checkpoint the bracket says:
- champion: **НеОлег**;
- 2nd: **Тестовый участник 2**;
- 3rd: **Тестовый участник 1**.

Do not hardcode these identities; derive from durable tournament results.

### 2. Trace and verify tournament settlement/economy

Before changing the UI, inspect the existing canonical owners for:
- final placement persistence;
- prize payout;
- Golden Ticket award;
- reservation release/finalization;
- tournament win/rating handling;
- exactly-once/idempotency.

Then verify against the current completed tournament.

Required acceptance:
- payout occurs exactly once;
- no second entry charge/reserve occurred between rounds;
- expected player balance/reward is visible after completion;
- fixture technical results do not create unintended competitive rewards;
- replay/reopen cannot duplicate prizes.

### 3. Add terminal-result regression coverage

At minimum cover:
- winner / 2nd / 3rd derivation;
- terminal Hall state;
- exactly-once settlement;
- reopen/heartbeat idempotency;
- completed tournament does not launch another match.

---

## AFTER TERMINAL RESULT/ECONOMY

### 4. Manual Admin Reset focus check

Code fix is already merged (#1659), but manual acceptance is still missing.

Check:
- reset staging tournament;
- immediately focus a title/date input;
- no full-screen freeze;
- no need to minimize/restore Telegram.

### 5. Reset/reseed fixture lifecycle check

Synthetic staging fixtures are intentional.

To remove:
- Admin → **«Сбросить staging-турнир»**.
- This releases reservations, withdraws registrations and retires fixture runtime accounts without deactivating the real player.

To add again:
1. create/open a new staging tournament;
2. open registration;
3. Admin → **«Подготовить 6/8 для двух живых аккаунтов»**;
4. use the final 2 seats with the two real accounts.

During progression:
- **«Завершить fixture-only пары»** only for fixture-vs-fixture pairs;
- mixed real+fixture later-round pair is now handled automatically as a technical bye after the canonical wait.

### 6. Manual countdown check on the next timed window

PR #1670:
- local visual tick = every 1 second;
- heartbeat remains server authority.

Still manually verify on next tournament/replay:
`04:39 → 04:38 → 04:37...`
without multi-second visible jumps.

### 7. Registration cancellation check if still not explicitly accepted

Recheck **«Отменить регистрацию»** before final MVP-21 closure if no explicit manual acceptance is already recorded.

---

## DO NOT DO YET

- Do not declare MVP-21 closed.
- Do not reset the current completed tournament before inspecting settlement/reward evidence if that evidence is needed.
- Do not remove the staging fixture helper; it is still useful for acceptance.
- Do not manually edit fixture rows in DB.
- Do not change game engines or accepted tournament timing rules while working on terminal UX.

---

## KEY PRs TO READ IF CONTEXT IS LOST

- #1657 — cold-start fix.
- #1659 — Admin Reset focus + terminal progression corrective.
- #1663 — real Hostinger MySQL HY093 fix.
- #1666 — active round visibility + archive persistence.
- #1669 — fixture no-show/replay-loop + technical bye.
- #1670 — smooth 1-second round/final countdown.

Runtime checkpoint:
`67e3e85168550ba67954e12f5336293c1cca24ad`

Start the next chat by reading this roadmap + the small canonical, then inspect the completed tournament’s durable settlement before writing terminal-result UI.
