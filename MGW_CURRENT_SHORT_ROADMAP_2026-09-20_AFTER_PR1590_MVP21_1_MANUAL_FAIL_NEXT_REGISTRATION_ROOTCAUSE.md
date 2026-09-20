# MGW CURRENT SHORT ROADMAP — 2026-09-20 — AFTER PR #1590 — MVP-21.1 MANUAL FAIL

## Current point
MVP-20 is closed.  
MVP-21.1 is implemented but NOT accepted.

Current staging:
- branch: `agent/mvp-13-2-staging`
- commit: `35a736a438aa8e649fa0697336b9d4dd8e2f6aa2`
- tree: `3a0e9ceb83013419c521461f4738232ddcd5b941`

Merged:
- PR #1589 — MVP-21.1 tournament registration/reservation foundation
- PR #1590 — first manual-acceptance corrective

Latest manual result:
- Russian admin accepted;
- tournament card visually mostly accepted;
- registration still fails after confirmation;
- visible error: `Не удалось загрузить данные. Закройте и снова откройте приложение.`
- state stays 0/8;
- balance stays 154,702.

Latest staging E2E:
- run #996 / `35516861848`
- final result: failure
- `/bot/api.php` returned 500 during current-core TTT lifecycle.

## Next task — MVP-21.1 corrective v3

### Phase A — find the real runtime failure
Do not start with more UI changes.

1. Reproduce the exact authenticated staging registration request.
2. Capture:
   - requested API action;
   - HTTP status;
   - raw API response/body;
   - server exception/error path;
   - DB/runtime state before and after the request.
3. Check whether registration fails:
   - before reservation;
   - inside reservation;
   - while syncing DB-primary runtime balance;
   - while serializing/returning the API response;
   - during the immediate follow-up `tournament_status`.
4. Investigate the independent staging E2E `/bot/api.php` 500 from run #996.
5. Compare both failures and only join them if evidence shows the same root cause.

### Phase B — minimal canonical fix
Fix only the real owner.

Preserve:
- `LedgerWriteService` as reservation owner;
- `mgw_coin` unified balance;
- available amount as spendable balance;
- reserved amount as held amount;
- registration semantics = reserve, NOT spend;
- exact single-seat/duplicate safety;
- leave releases the exact reservation before full.

No parallel wallet, no extra reservation subsystem, no second balance.

### Phase C — requested player UI cleanup
After runtime fix:

1. Remove subtitle:
   `Регистрация, зарезервированный взнос и текущий состав турнира.`
2. Remove the duplicated lower money/balance line.
3. Keep 50,000 visible in the entry block and CTA only where useful.
4. Insufficient funds:
   - backend must continue rejecting insufficient balance;
   - if available < 50,000, preferably disable the registration CTA before request;
   - show a concise `Недостаточно коинов` state;
   - do not create a seat or reservation.
5. Keep real server errors persistent and readable.

### Phase D — tests that must exist
Focused MVP-21.1 gate must cover:

- real MySQL DB-primary service path;
- actual API/runtime registration path;
- registration from 154,702:
  - available -> 104,702;
  - reserved -> 50,000;
  - participants -> 1/8;
- duplicate registration does not reserve twice;
- leave before full:
  - participants -> 0/8;
  - available -> 154,702;
  - reservation released;
- insufficient balance:
  - no reservation;
  - no participant row/seat;
  - clear user-facing state;
- MVP-20 preservation;
- unified balance preservation;
- no production/main changes.

### Phase E — staging acceptance
After merge:

1. Wait for exact staging deployment.
2. Confirm exact revision.
3. Run staging preflight.
4. Run full two-context staging Playwright.
5. Final commit status MUST be green, not merely an individual job step.
6. If `/bot/api.php` returns any 5xx, MVP-21.1 stays open.

### Phase F — manual Telegram acceptance
User checks:

1. `Арена → Турниры`.
2. Tournament is open, 0/8.
3. Click register and confirm.
4. Expected:
   - no generic reload error;
   - state becomes 1/8;
   - CTA becomes cancel/leave;
   - available balance 154,702 -> 104,702;
   - 50,000 held as tournament reservation.
5. Cancel registration.
6. Expected:
   - state returns 0/8;
   - available balance returns exactly 154,702;
   - tournament reservation is released.
7. Low-balance UX must be understandable without duplicated explanatory money text.

## Exit condition for MVP-21.1
MVP-21.1 is closed only when all are true:
- focused CI green;
- final staging E2E green;
- no `/bot/api.php` 5xx in acceptance flow;
- register works manually;
- leave works manually;
- balance/reservation values are correct;
- requested UI cleanup accepted.

## After that
Only then proceed to MVP-21.2.

MVP-21.2 remains deferred and must not be implemented during this corrective.
