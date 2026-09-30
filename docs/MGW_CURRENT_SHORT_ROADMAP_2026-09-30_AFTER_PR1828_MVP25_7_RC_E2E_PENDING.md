# MGW CURRENT SHORT ROADMAP — 2026-09-30 — AFTER PR #1828 / MVP-25.7 RC E2E PENDING

## Stop point

Current staging at checkpoint:
`60f855e4f10ed53afc03f433e056cd6e157ee88b`

PR #1828 (`MVP-25.7: Telegram Product Release Candidate`) is already MERGED.

Focused MVP-25.7 RC gate:
- run `36685443574`
- SUCCESS

Secret scan:
- run `36685443727`
- SUCCESS

Current unfinished proof:
- `Staging Playwright E2E`
- run `36685706578`
- exact head `60f855e4f10ed53afc03f433e056cd6e157ee88b`
- status at checkpoint: IN PROGRESS
- current step: TEST PLAYER A/B two-context browser flow.

MVP-25.6 is already fully CLOSED/FROZEN through PR #1827.

---

## Next chat — do this first

1. Re-read current `agent/mvp-13-2-staging` SHA.
2. Re-read workflow run `36685706578`.
3. If staging is still `60f855e4...`, continue evaluating that exact RC.
4. If staging moved, do NOT manually accept the old SHA blindly:
   - identify what advanced staging;
   - compare it with `60f855e4...`;
   - decide whether the RC proof must be repeated on the new head.

---

## If run 36685706578 is SUCCESS

Record:
- Hostinger readiness SUCCESS;
- webhook reconciliation SUCCESS;
- projections/migrations SUCCESS;
- TEST PLAYER A/B SUCCESS;
- final staging commit status SUCCESS.

Then create an immutable RC checkpoint branch from the exact accepted staging SHA, suggested name:

`backup/mvp25-7-telegram-product-rc-green-2026-09-30`

Do not change runtime before manual acceptance.

Then hand the product owner the final manual checklist from:
`docs/MVP25_7_MANUAL_ACCEPTANCE.md`

Ask for one final regression sweep on the exact RC SHA.

---

## If run 36685706578 fails

Do not start a broad cleanup.

Classify the exact failed step:
- Hostinger/deploy;
- webhook;
- migrations/projections;
- TEST PLAYER A/B;
- final status publication.

If the failure is in TEST PLAYER A/B, inspect that exact browser scenario first.

Only create a corrective if a real defect is reproduced.
Preserve all frozen MVP-25.1–25.6 owners.

---

## Human acceptance phase

Manual checklist covers:
1. cold launch + shell;
2. More/shared sheets;
3. Profile;
4. Store;
5. Arena;
6. Friends/invites/notifications;
7. all eight games visual smoke;
8. one real two-player flow;
9. network recovery;
10. explicit final product-owner verdict.

Important Store expectation:
- visible tabs: Profile / Games / Bundles;
- coin balance visible;
- no real-money top-up;
- no `Скоро` monetization card.

Known accepted residual:
- slight Profile hitch from MVP-25.4 may remain.

---

## If manual result is PASS

Do not silently close MVP-25.

Create a closure-only branch/PR that:
- records the exact accepted RC SHA;
- records exact green Staging Playwright E2E run;
- records explicit product-owner manual PASS;
- changes MVP-25.7 status to CLOSED / MANUALLY ACCEPTED / FROZEN;
- records final MVP-25 closure if the authoritative roadmap requires it;
- changes no runtime/UI/economy/payment/game/tournament/database/Cron files.

Run the closure contract + secret scan.

Merge only if the closure-only scope is clean.

After merge:
- verify staging head;
- verify the normal exact-SHA E2E if triggered/required;
- create final immutable MVP-25 checkpoint.

Only then determine the next authoritative roadmap point.
The current MVP-25.7 doc says Android work begins after MVP-25 closes.

---

## If manual result finds a defect

Record the exact reproduction:
- screen/game;
- device/viewport;
- action sequence;
- expected vs actual;
- whether it reproduces after full Mini App reopen.

Repair only that reproduced defect in a bounded branch.
Do not reopen already frozen areas without evidence.

---

## Frozen / do not reopen casually

- MVP-25.5 Security / resilience — CLOSED.
- MVP-25.6 Monetization-disabled complete Store — CLOSED.
- real-money/provider integration — outside MVP-25.
- accepted eight-game mechanics/cosmetics — frozen unless a new reproducible regression appears.
- accepted MVP-25.4 slight Profile hitch — known residual, not a release blocker by itself.
