# MGW CURRENT SHORT ROADMAP — 2026-10-07 — AFTER PR #2096

## Current checkpoint

PR #2096 is merged to staging at:

`e8043aab8b2055655f37625e2fc0d03bdd5b3b2c`

Manual verification immediately after merge means **PR #2096 is not fully accepted**.

## What is now working

### Public “Сейчас в игре”
- Current manual test with one user shows **1**.
- Treat as provisionally accepted.
- Do not touch presence again while fixing the Profile regressions unless new evidence requires it.

## What is broken and must be fixed next

### A. Home/topbar avatar
- Correct selected avatar appears briefly on startup.
- Roughly one second later it is replaced by the generic `MG` image.
- Fix settled render/bootstrap convergence so the real selected avatar remains in the topbar.

### B. Avatar confirmation leaking to Home
- Rapid avatar switching itself works.
- After leaving Profile, the avatar-selected confirmation/message hangs or reappears on Home for too long.
- Remove/scoped-clear that Profile feedback on navigation.

### C. Profile → Игры rail
- Remove the newly added left/right arrow buttons completely.
- User did **not** request arrows.
- Restore normal finger/native horizontal swipe behavior.
- Fix the excessive vertical gap below the `Игры` heading.
- First game row/tab must sit compactly under the heading.
- Do not add press-scale/click deformation.

### D. Profile game images/previews
- PR #2096 damaged accepted preview presentation.
- Explicitly reported broken: `Четыре в ряд`, `Морской бой`.
- Audit all game tabs.
- Restore pre-PR2096 accepted artwork, sizing, cropping and proportions.
- The selector fix must not touch preview rendering unless restoring the old accepted state.

## Required work order

1. Diagnose exact active owners for topbar avatar settled render and stale Profile toast/message.
2. Compare Profile game collection against staging immediately before PR #2096 / accepted prior presentation.
3. Remove only PR2096-added visible arrow UI and restore compact spacing.
4. Keep native swipe/scroll behavior without visible controls.
5. Restore game previews to accepted visuals across every game.
6. Add narrowly targeted regressions for:
   - topbar selected avatar survives settled render;
   - Profile-only selection feedback does not leak to Home;
   - no visible rail arrows;
   - game preview markup/styles remain on accepted owner.
7. Merge to staging only after focused CI is green.
8. Ask for one manual acceptance pass covering all open points together.
9. Reconfirm “Сейчас в игре” did not regress.

## Do not reopen now

- Previously accepted items 4, 5 and 7.
- Tournament item 6: keep deferred until immediately before the real tournament.
- Presence architecture, unless the currently accepted one-user behavior regresses.
- Unrelated game mechanics, Store, localization, economy or tournament logic.

## Manual acceptance checklist for the next build

- Home topbar keeps selected avatar after waiting at least 10–15 seconds.
- Change avatars quickly, leave Profile: no stale selection message on Home.
- Profile → Игры: no arrow buttons.
- Swipe game row left/right by finger naturally.
- No giant gap below `Игры`.
- Open/check Tic-Tac-Toe, Four in a Row, Battleship and every remaining game: preview images look exactly like accepted pre-regression state.
- “Сейчас в игре” remains truthful.

## Handoff note

If the chat ends before the corrective is completed, start the next chat from this roadmap plus the matching small canonical:

`docs/MGW_SMALL_CANONICAL_2026-10-07_AFTER_PR2096_MANUAL_ACCEPTANCE_REGRESSIONS_NEXT_PROFILE_CORRECTIVE.md`
