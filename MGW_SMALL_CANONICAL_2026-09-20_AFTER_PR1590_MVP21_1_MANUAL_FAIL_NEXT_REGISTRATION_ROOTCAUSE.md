# MGW SMALL CANONICAL — 2026-09-20 — AFTER PR #1590 — MVP-21.1 MANUAL ACCEPTANCE FAILED

## Authority
This is the current operational checkpoint for MiniGamesWorld after the authoritative master:
`MGW_CANONICAL_MASTER_FULL_27MVP_AUTHORITATIVE_2026-09-20_THROUGH_PR1588_MVP20_CLOSED_NEXT_MVP21_1_STAGING_66109299.md`.

It does not replace the historical master. It records the real project state after PR #1589 and corrective PR #1590 plus the latest manual staging acceptance.

Repository: `manufact-test/mini-games-world`  
Staging branch: `agent/mvp-13-2-staging`  
Exact staging commit at checkpoint: `35a736a438aa8e649fa0697336b9d4dd8e2f6aa2`  
Exact tree: `3a0e9ceb83013419c521461f4738232ddcd5b941`

## Closed before this checkpoint
- MVP-20.1 through MVP-20.8 are closed.
- PR #1588 closed MVP-20.
- Production/main/Cron/live DB were not advanced by MVP-21.1 work.

## MVP-21.1 implementation already merged
### PR #1589
`MVP-21.1: add official tournament registration reservations`

Implemented:
- one official tournament slot;
- admin draft creation;
- capacities 8 / 16 / 32 / 64 / 128;
- fixed entry fee 50,000 `mgw_coin`;
- immutable tournament reward snapshot;
- admin open-registration action;
- player UI inside existing `Арена → Турниры`;
- register / status / leave through canonical `bot/api.php`;
- registration reserves 50,000 through canonical `LedgerWriteService`;
- entry is reserved, not spent;
- duplicate register must not reserve twice or take a second seat;
- leave before full releases reservation;
- full-capacity membership becomes locked at this stage;
- no MVP-21.2 rules/auto-close ownership yet.

### PR #1590
`MVP-21.1 corrective: fix manual registration acceptance`

Merged improvements:
- Tournament Admin localized to Russian;
- clearer player balance/reservation copy;
- persistent registration error rendering;
- fresh status read after register/leave;
- cache/version refresh for tournament modules;
- real MySQL 8.4 + DB-primary registration acceptance test;
- manual-acceptance UX contract;
- unified balance and MVP-20 preservation gates.

## Manual acceptance — latest real result
Accepted visually:
- Web Admin is now Russian.
- Tournament card generally looks correct.
- Tournament is open.
- Test tournament: `Проба один`.
- Game: Tic-Tac-Toe.
- Capacity: 8.
- Entry: 50,000.
- Balance shown before registration: 154,702.

Still requested UI cleanup:
1. Remove the subtitle under `Официальный турнир`:
   `Регистрация, зарезервированный взнос и текущий состав турнира.`
2. Remove duplicated money/balance text from the lower status area. The 50,000 entry is already obvious in the entry block and CTA, so do not repeat the same monetary information around the card.
3. Keep insufficient-balance behavior explicit and simple.

Current insufficient-balance code behavior:
- backend error `Insufficient available balance.` is mapped to:
  `Недостаточно доступных коинов для взноса 50 000.`
- the register button is NOT currently pre-disabled based on insufficient balance;
- preferred next UX: if available balance < 50,000, disable registration and show a short clear `Недостаточно коинов` state, while backend validation remains authoritative.

## Critical unresolved defect
Manual registration still fails on staging.

Reproduction:
1. Open `Арена → Турниры`.
2. Click `Зарегистрироваться · 50 000`.
3. Confirm OK.
4. UI shows:
   `Не удалось загрузить данные. Закройте и снова откройте приложение.`

Observed after failure:
- participants remain `0 / 8`;
- balance remains `154702`;
- registration is not visibly saved;
- user cannot proceed.

Therefore MVP-21.1 is NOT manually accepted and MUST remain open.

## Important staging signal
Post-merge staging run:
- workflow: `Staging Playwright E2E`
- run: `#996` / run id `35516861848`
- exact commit: `35a736a438aa8e649fa0697336b9d4dd8e2f6aa2`
- final status: FAILURE
- 3 tests passed, 1 failed
- failure: `CURRENT FINAL CORE: canonical Telegram v110 two-player TTT lifecycle`
- observed server error: `/bot/api.php` returned HTTP 500.

This 500 must be investigated before MVP-21.1 can be closed. It may or may not share the same root cause as the manual tournament registration failure; do not assume either way without evidence.

## Exact next work
1. Branch from exact staging `35a736a...`.
2. Reproduce authenticated staging `tournament_register` and capture the raw HTTP status/body and server-side failure path.
3. Investigate the current `/bot/api.php` HTTP 500 from staging E2E and determine whether it shares the root cause.
4. Fix the smallest canonical owner only. Do not create a second wallet, balance, reservation or tournament state system.
5. Apply requested UI cleanup:
   - remove top subtitle;
   - remove duplicated balance/money line;
   - add clean insufficient-balance state.
6. Add an acceptance test that covers the real API/runtime path, not only direct service calls.
7. Run focused MVP-21.1 gate.
8. Merge only when focused gate is green.
9. Run final staging E2E and require final `staging-playwright-e2e = success`.
10. Manual Telegram acceptance:
    - register;
    - verify 1/8;
    - verify 154,702 -> 104,702 available + 50,000 held;
    - leave;
    - verify 0/8;
    - verify exact balance return to 154,702.
11. Only after manual acceptance close MVP-21.1 and move to MVP-21.2.

## Hard boundary
Do NOT start MVP-21.2 yet.
Do NOT touch production/main/Cron/live DB.
Do NOT consume tournament reservation in MVP-21.1.
Do NOT change game rules as part of this corrective.
