# MGW SMALL CANONICAL — MVP-21 TOURNAMENT MANUAL ACCEPTANCE CHECKPOINT

**Date:** 2026-09-22  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging branch:** `agent/mvp-13-2-staging`  
**Runtime checkpoint SHA:** `67e3e85168550ba67954e12f5336293c1cca24ad`  
**Runtime checkpoint source:** merged PR **#1670**  
**Exact staging E2E on this SHA:** **SUCCESS** (Linux A/B route + final commit status)  
**Status:** **MVP-21 remains OPEN.** Full tournament path now reaches terminal bracket completion, but terminal result/reward UX and several manual acceptance items remain.

---

## 1. What is accepted now

### Cold start
- Previous 10–16 s first-usable startup was reduced to about 3.3–3.4 s in live staging.
- Main root fix: bootstrap no longer blocks first paint on synchronous projection/catch-up hooks.
- User manually accepted the startup improvement.
- Key chain: **#1655 → #1656 → #1657**.

### Notification stale-state corrective
- Staging stale DB-primary fallback now covers notification inventory plus mutable `read_at` / `hidden_at` drift.
- This is not a current MVP-21 blocker unless reproduced again.

### First live tournament match → durable progression
- Finished first-round game no longer remains stuck as `Матч запущен / Готов`.
- Red generic tournament error after terminal game was traced on real Hostinger MySQL.
- Exact root cause was duplicated named PDO placeholder usage in a MySQL OR predicate; SQLite tests had accepted it.
- Fixed with distinct placeholders in **#1663**.
- User manually verified the red error disappeared and the first-round result advanced.

### Fixture-only helper and round advancement
- Admin control **«Завершить fixture-only пары»** is staging-only.
- It completes only pairs where both players are synthetic fixtures.
- It uses the canonical `TournamentRoundProgressionService`.
- It does not create a second tournament entry-fee path and does not overwrite the real played pair.
- Live diagnostic proved:
  - round 1 reached **4/4 completed**;
  - round 2 was created with **2 semifinal pairs**.
- The initially “missing” round transition was a client projection problem, not a server bracket problem.

### Round-2 Hall UX
Merged in **#1666**:
- active round grid is visible to both advancing and eliminated participants;
- eliminated participant explicitly sees that the bracket has moved on;
- current round completion counter is visible;
- **«Стартовая сетка · архив»** preserves its open/closed state across Hall heartbeat rerenders;
- spacing above the round grid was corrected.

Manual acceptance:
- eliminated account correctly saw round 2;
- advancing account correctly saw round 2;
- archive no longer self-closes on heartbeat.

### Mixed real + staging fixture no-show loop
Merged in **#1669**:
- a later-round pair containing one real participant and one synthetic fixture no longer starts an impossible live game forever;
- after the canonical round/replay wait, the real participant receives a staging technical bye;
- if a real tournament preparation timeout occurs and exactly one participant actually adopted the game, that participant advances by technical no-show instead of creating a false draw replay;
- first-round Ready behavior remains untouched.

Manual acceptance:
- round 2 progressed from **0/2** to **1/2** with the real participant marked **«прошёл дальше»**;
- no repeated fake game / draw / replay loop remained.

### Final round
After the remaining fixture-only semifinal was completed:
- the system created the final round with:
  - **Финал**;
  - **Матч за 3-е место**;
- the canonical inter-round break is **5 minutes**;
- the real finalist with a fixture opponent received the expected technical progression;
- the final fixture-only placement pair was completed through the staging helper.

Current manually observed terminal bracket:
- **Финал:** `НеОлег · вы` passed, `Тестовый участник 2` eliminated;
- **Матч за 3-е место:** `Тестовый участник 1` passed, `Тестовый участник 5` eliminated;
- Hall shows **«Все матчи турнира завершены»** and **2/2 completed**.

This bracket implies the current placement order:
1. **НеОлег**
2. **Тестовый участник 2**
3. **Тестовый участник 1**
4. **Тестовый участник 5**

Important: the screenshot proves bracket placement, **not yet prize/economy settlement**. Prize ledger and terminal reward UX are still unaccepted.

---

## 2. Smooth countdown corrective

PR **#1670** merged to the runtime checkpoint SHA.

Problem:
- after tournament start, progression countdown text was updated mainly by the 2–3 s Hall heartbeat;
- visible countdown jumped like `03:28 → 03:25 → 03:21`.

Fix:
- Hall now runs a local 1-second rendered countdown ticker;
- server heartbeat remains the authoritative state owner;
- no tournament-state semantics changed.

Evidence:
- exact staging deploy passed;
- staging Playwright E2E passed.

Manual status:
- **NOT manually accepted yet** because the user reopened after the final five-minute break had already elapsed.
- Recheck on the next tournament/replay wait.

---

## 3. Synthetic staging participants / “bots”

These are **staging synthetic fixtures**, not production tournament bots.

Current helper mode:
- Tournament Admin button: **«Подготовить 6/8 для двух живых аккаунтов»**.
- It fills **6 of 8** seats with synthetic participants through the canonical registration flow.
- Each fixture receives the same canonical **50 000** tournament reserve.
- Two seats remain for the two real manual-test accounts.
- Synthetic legacy IDs use the staging fixture family `stg_tour_...` / `stg_tour_v2_...`.

### How to finish fixture pairs
Use:
- **«Завершить fixture-only пары»**

It is allowed only for pairs where **both** sides are synthetic fixtures.

Mixed real + fixture later-round pairs are handled automatically by the staging technical-bye rule after the canonical wait.

### How to remove them safely
Use Tournament Admin:
- **«Сбросить staging-турнир»**
- requires the existing confirmation flow.

Canonical reset behavior:
- releases tournament reservations through the ledger;
- marks registrations withdrawn;
- retires/removes synthetic fixture accounts from test runtime;
- **does not deactivate the real player account**;
- leaves the old tournament in audit;
- frees the active slot for a new staging tournament.

### How to add them again
For a fresh staging tournament:
1. create/open the new tournament;
2. open registration;
3. press **«Подготовить 6/8 для двух живых аккаунтов»**;
4. let the two real accounts take the remaining seats.

Do **not** manually insert/delete fixture DB rows.

---

## 4. Important corrective PR chain in this checkpoint

- **#1655** — stale notifications, terminal Ready attempt, Admin focus corrective, cold-start instrumentation.
- **#1656** — selective stale-primary notification probe.
- **#1657** — real cold-start fix: defer bootstrap projection hooks.
- **#1659** — terminal progression ownership correction + Admin Reset focus release.
- **#1660–#1662** — temporary live staging diagnostics used to locate the real post-terminal failure.
- **#1663** — real MySQL `HY093` next-round participant lookup fix.
- **#1664** — temporary diagnostics cleanup.
- **#1665** — round-state diagnosis + archive heartbeat persistence.
- **#1666** — visible round-two bracket / eliminated participant state.
- **#1669** — fixture no-show/replay-loop fix and staging technical bye.
- **#1670** — smooth 1-second final/round countdown ticker.

Superseded/closed diagnostic or intermediate PRs such as **#1658, #1667, #1668** are not authoritative runtime endpoints.

---

## 5. Still OPEN / not accepted yet

### A. Terminal tournament completion UX — NEXT
Current screen is technically complete but unclear to a player:
- says **«Все матчи турнира завершены»**;
- still primarily shows the final-round bracket;
- does not clearly say **who is champion / 2nd / 3rd**;
- does not clearly show whether/when prizes were credited;
- does not explain what the player should do next.

This is the immediate next product task.

### B. Prize/economy settlement
Must be traced and manually verified before MVP-21 closes:
- first-place reward;
- second-place reward;
- third-place reward;
- Golden Ticket if required by the existing tournament prize rules;
- exactly-once payout;
- no duplicate tournament entry fee/reservation;
- reservations released/finalized correctly;
- technical fixture results must not accidentally award player-facing competitive rating/reward semantics that are not intended.

Do not infer payout from the bracket screenshot.

### C. Admin Reset focus
PR #1659 includes the code fix:
- release focused Reset button before `withBusy()` disables/rerenders it.

But the user has **not manually re-accepted this after the fix**.
Still needs one staging manual check:
1. reset staging tournament;
2. immediately tap tournament title/date field;
3. keyboard/input must work on the first tap without minimize/restore.

### D. Countdown manual acceptance
PR #1670 is code/E2E green but manual verification remains deferred to the next timed wait.

### E. Registration cancellation
If not already explicitly accepted in a later/manual run, recheck the player-facing **«Отменить регистрацию»** path before final MVP-21 closure.

---

## 6. Frozen boundaries

Until the remaining MVP-21 acceptance is finished, do not casually change:
- accepted 8-game mechanics;
- tournament entry fee/reserve semantics;
- accepted first-round Ready behavior;
- 5-minute round break;
- 1-minute draw replay semantics;
- durable round progression ownership;
- Arena/Rating accepted architecture;
- production/main/Cron/live DB without explicit authorization.

---

## 7. Exact next point

Continue from the **completed live staging tournament** at runtime SHA:

`67e3e85168550ba67954e12f5336293c1cca24ad`

The first next task is **terminal tournament result + reward UX/economy verification**, not another bracket reset and not MVP-21 closure.
