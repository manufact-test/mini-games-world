# MGW SMALL CANONICAL — 2026-10-07 — AFTER PR #2096 MANUAL ACCEPTANCE REGRESSIONS

## Status

This is the current small handoff canonical after PR #2096 was merged into staging.

- Repository: `manufact-test/mini-games-world`
- Staging branch: `agent/mvp-13-2-staging`
- PR #2096 merge SHA: `e8043aab8b2055655f37625e2fc0d03bdd5b3b2c`
- PR #2096 title: `MVP-27.1: close active Profile rail and presence regressions`
- Big authoritative canonical will be refreshed later. Until then, this file is the small manual-acceptance checkpoint for the currently open corrective work.

## What PR #2096 attempted

PR #2096 targeted three manual-acceptance areas:

1. Profile game selector/rail behavior.
2. Profile avatar / persistent top-shell behavior.
3. Public `online_players` / “Сейчас в игре” truthfulness.

The merge itself is **not accepted as a whole**. Manual verification immediately after the merge found regressions in the Profile/top-shell surfaces.

## Manual acceptance result after PR #2096

### Provisionally accepted

#### Public “Сейчас в игре”

Current manual observation: when one real user is present, the UI shows **1** and is currently behaving normally.

Treat this specific symptom as **provisionally fixed**, not as permission to rewrite presence again during the next Profile corrective.

Guardrail:
- do not change the currently accepted public presence behavior unless a reproducible presence regression appears;
- Profile fixes must not be bundled with another presence redesign.

### Rejected / still open

#### 1. Main topbar avatar regressed

Observed behavior:
- on entry, the real selected avatar image is visible briefly;
- about a second later it disappears;
- the persistent topbar avatar is replaced by the generic `MG` graphic.

Expected behavior:
- the selected user avatar must remain visible in the topbar;
- do not replace it after bootstrap/render convergence with the generic `MG` mark;
- the initial correct frame and the settled frame must agree.

This supersedes the incorrect assumption in PR #2096 that the persistent topbar should be avatar-neutral.

#### 2. Stale avatar-selection message leaks outside Profile

Observed behavior:
- avatar can now be changed quickly;
- after leaving Profile for Home, the avatar-selection confirmation/message remains visible or reappears there for a long time (reported after roughly a minute);
- the user explicitly does not want this message hanging on Home.

Expected behavior:
- selection may be confirmed inside the relevant Profile interaction;
- no stale “avatar selected” confirmation/toast may remain or reappear on Home after navigation;
- navigation must clear or scope the Profile-only feedback correctly.

#### 3. Profile → “Игры” selector UI regressed

Manual screenshots show the active game selector is visually wrong.

Current rejected behavior:
- added left/right circular arrows are visually awkward;
- arrows were not requested;
- the user wants to move the game row by normal finger swipe/native horizontal scrolling as before;
- there is an excessive vertical gap between the `Игры` heading and the first game row/tab;
- the first visible game (`Крестики-нолики`) starts too low under the heading.

Expected behavior:
- **remove the added arrow controls entirely**;
- keep native horizontal touch/finger scrolling;
- mouse/trackpad scrolling may work if it does not add visible controls or interfere with taps;
- restore compact accepted spacing directly below the `Игры` heading;
- no press/click distortion.

#### 4. Profile game preview artwork/layout was broken

Observed after PR #2096:
- game images/previews in the Profile collection no longer preserve the previously accepted appearance;
- user specifically called out `Четыре в ряд` and `Морской бой`;
- other game previews must be checked for the same regression.

Expected behavior:
- restore the exact previously accepted Profile game preview/card artwork and proportions;
- selector scrolling fixes must not rewrite, resize, crop, stretch, or replace game cosmetic previews;
- use the pre-PR2096 accepted presentation as the visual baseline;
- verify every game tab, not only Tic-Tac-Toe.

## Hard corrective scope

Next corrective must be narrow:

- topbar avatar settled state;
- Profile-only avatar selection feedback lifecycle;
- Profile game selector spacing and native horizontal scrolling;
- restoration of accepted game preview visuals.

Do **not** use this corrective to redesign:
- presence;
- game mechanics;
- tournament runtime/countdown;
- Store cosmetics;
- unrelated Profile collections;
- accepted localization;
- unrelated Home UI.

## Previously closed/deferred context

- Earlier manually accepted items 4, 5 and 7 remain closed unless a new reproducible regression is shown.
- Tournament/manual item 6 remains intentionally deferred: re-check it immediately before the real tournament rather than reopening it during this Profile corrective.

## Acceptance gate for the next corrective

Do not call the next corrective complete until manual testing confirms all of the following in the same staging build:

1. selected avatar remains visible in the Home/topbar after the initial second;
2. no Profile avatar-selection message remains/reappears on Home;
3. Profile `Игры` has no visible arrow buttons;
4. game row moves naturally by finger swipe;
5. spacing below `Игры` is compact again;
6. Tic-Tac-Toe, Four in a Row, Battleship and the remaining game tabs all preserve their previously accepted preview visuals;
7. public “Сейчас в игре” still shows the truthful count and was not regressed.
