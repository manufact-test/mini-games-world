# MGW CURRENT SHORT ROADMAP — 2026-09-20 — MVP-20.3 ARENA CORRECTIVE IN PROGRESS

**Repo:** `manufact-test/mini-games-world`
**Accepted staging:** `agent/mvp-13-2-staging` @ `cabe14ec7a6efb9a8d8399a4d916859ca8f91b18`
**Current working branch:** `agent/mvp20-3-leaderboard-ux-corrective-v2`
**Working head at checkpoint:** `325bb138508a1fbef8ebdaa94f05ad66cc5d6e52`
**Branch relation:** 10 commits ahead of staging, 0 behind at checkpoint.
**Current task:** finish and accept MVP-20.3 leaderboard corrective before MVP-20.4.

---

## A. What was already completed before this corrective

### MVP-20.1
Visible per-game rating infrastructure exists.

### MVP-20.2
Hidden skill model exists separately from visible rating.

### MVP-20.3 initial implementation
PR #1578 was merged to staging.

It delivered:

- leaderboards for all 8 games;
- eligibility: 5 rated human matches + 1 human win;
- anti-farming: max 3 credited wins vs same opponent per Moscow day;
- deterministic tie-breaks;
- dedicated leaderboard endpoint;
- visible rating integration;
- PRESEASON internal state;
- staging gates/tests.

The first staging E2E initially hit bootstrap HTTP 400, but a fresh rerun later passed preflight and full two-context E2E. That bootstrap incident is no longer the active blocker.

---

## B. Manual review problems found on 2026-09-20

The user manually reviewed the initial MVP-20.3 UI and rejected the following:

1. leaderboard rows load too slowly;
2. the 8-game selector cannot reliably reach off-screen games with mouse/desktop interaction;
3. visible **ПРЕДСЕЗОН** badge/copy is unnecessary;
4. `До таблицы: 1/5 матчей · 1/1 побед` is confusing and badly placed;
5. staging shows generated `PlayerXXXXXXX` users with points even though they are technical E2E accounts;
6. leaderboard border/contrast is too weak;
7. the full global leaderboard should not live in Profile;
8. the correct permanent navigation home needed to be decided before continuing.

---

## C. Product decision now accepted

Final navigation/content model:

### Bottom navigation
**Главная · Арена · Магазин · Профиль**

Visible label: **Арена**.

Keep the internal route name `tournaments` for now to minimize router risk.

### Inside Arena
Page title: **Соревнования**

Primary tabs:

- **Рейтинг**
- **Турниры**

### Rating
Global per-game MiniGamesWorld leaderboard for all 8 games.

### Tournaments
Reserved for real tournament events, schedule/participation and tournament-specific standings.

### Profile
Personal rating/statistics only. No full global leaderboard.

### PRESEASON
Keep internally; hide from normal user-facing UI.

---

## D. Corrective work already performed on the working branch

Changed files currently include:

- `app/assets/css/main.css`
- `app/assets/js/main-v110-handoff-shell.js`
- `app/assets/js/screens/profile-screen-v110.js`
- `app/assets/js/screens/tournaments-screen-v1.js` — new
- `app/locales/ru.json`
- `bot/leaderboard.php`
- `bot/ratings/LeaderboardService.php`

Already implemented in the branch:

- removed the global leaderboard modal/launcher from Profile;
- created the dedicated competition owner on the existing tournaments route;
- added **Рейтинг / Турниры** mode tabs;
- built the 8-game Rating selector;
- added left/right scroll buttons;
- added mouse wheel horizontal scrolling;
- added mouse drag;
- kept touch horizontal scrolling;
- made the board outline brighter;
- removed the PRESEASON badge from the new board;
- stopped rendering the confusing qualification strip in the public board;
- added client caching / in-flight request reuse and initial warm-up;
- changed leaderboard read path from heavy Profile synchronization to bounded projected-match catch-up;
- public leaderboard query now excludes accounts with `mgw_identities.provider = 'development'`.

Root cause of fake-looking staging leaders was confirmed:
E2E development accounts receive generated canonical names `PlayerXXXXXXX`, then automated wins produced real staging rating points. Those technical identities must be filtered from public boards, not treated as real players.

---

## E. Immediate next coding steps — do these before asking for manual review

1. **Change bottom navigation visible text from the temporary “Соревнования” to final “Арена”.**
   - Page heading remains **Соревнования**.
   - Primary tabs remain **Рейтинг / Турниры**.
   - Keep internal route identifier `tournaments`.

2. **Finish runtime/cache identity updates.**
   - update client manifest/cache-bust for the new/changed competition UI;
   - bump the MVP-20.3 corrective identity in `app/v110.php` / launch URL only as needed;
   - ensure new `tournaments-screen-v1.js` is reliably loaded by the active v110 graph.

3. **Rewrite obsolete MVP-20.3 integration contract expectations.**
   Current accepted test still asserts that Profile must provide the leaderboard launcher and visible PRESEASON marker. Those assertions are now obsolete and must be replaced.

   New contract must assert:
   - leaderboard owner is Arena/competition screen, not Profile;
   - all 8 game tabs are present;
   - Profile does not own full public leaderboard;
   - PRESEASON is not rendered in normal leaderboard UI;
   - bottom/public labels follow Arena → Соревнования → Рейтинг/Турниры.

4. **Add public test-identity exclusion coverage.**
   - a development-provider account with otherwise eligible score must not appear;
   - a normal public account must still appear;
   - do not delete staging rating audit data just to make the test pass.

5. **Protect rating rules while correcting UI.**
   Run the existing model tests for:
   - 5-match / 1-win eligibility;
   - +1 normal win / +2 tournament win;
   - Moscow-day anti-farming cap = 3;
   - tie-break order;
   - hidden skill separation.

6. **Run syntax/lint and frozen-game guards.**

7. **Open PR into `agent/mvp-13-2-staging`.**

8. **Wait for all focused CI to go green.**

9. **Merge to staging only after green CI.**

10. **Run fresh staging E2E / preflight.**

---

## F. Manual acceptance checklist after staging deployment

Only call the user when the branch is merged/deployed and these are ready to check manually.

Ask the user to verify:

- bottom navigation says **Арена** and fits normally;
- opening Arena shows page title **Соревнования**;
- **Рейтинг / Турниры** switch is obvious and visually clean;
- Rating loads quickly;
- no long “Загрузка…” like the rejected Profile version;
- all 8 game names can be reached on desktop and mobile;
- mouse wheel/drag/arrows work for overflow;
- border is clear enough against the background;
- no PRESEASON badge;
- no “До таблицы: …” strip above the list;
- no staging E2E `PlayerXXXXXXX` development accounts;
- real rows use canonical MGW nickname/avatar;
- Profile no longer contains the global leaderboard;
- personal rating in Profile remains intact;
- Tournaments tab does not pretend that the global rating is a tournament result.

If accepted, mark MVP-20.3 corrective CLOSED.

---

## G. After acceptance

Next planned stage:

**MVP-20.4 — quarterly seasons / FINALIZING**

Use the accepted Arena architecture as the stable competition home.

Do not start MVP-20.4 before this corrective is closed.

At closure, create/update:

- new small canonical checkpoint;
- current short roadmap;
- later fold this checkpoint into the large authoritative economic/master canonical so the Arena/Rating/Tournaments placement is not lost.
