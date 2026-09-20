# MGW SMALL CANONICAL — MVP-20.3 ARENA / LEADERBOARD CORRECTIVE CHECKPOINT

**Date:** 2026-09-20
**Repository:** `manufact-test/mini-games-world`
**Authoritative staging branch:** `agent/mvp-13-2-staging`
**Accepted MVP-20.3 staging base:** `cabe14ec7a6efb9a8d8399a4d916859ca8f91b18` (PR #1578 merged)
**Current corrective work branch:** `agent/mvp20-3-leaderboard-ux-corrective-v2`
**Corrective branch head at this checkpoint:** `325bb138508a1fbef8ebdaa94f05ad66cc5d6e52`
**Status:** design decision is authoritative; corrective implementation is IN PROGRESS and is **not yet merged to staging**.

---

## 1. Why this checkpoint exists

MVP-20.3 introduced per-game public leaderboards and anti-farming. The first staging UI placed the full leaderboard inside **Profile**. Manual review on 2026-09-20 showed that this placement and several presentation/runtime details are not acceptable.

This document supersedes the first MVP-20.3 leaderboard placement decision for UI/navigation only. The rating/economy rules below remain authoritative unless explicitly changed here.

The global leaderboard **must not live inside Profile**.

---

## 2. Final information architecture decision

### Bottom navigation

Visible bottom navigation must be:

**Главная · Арена · Магазин · Профиль**

The visible word is **«Арена»** because it is short enough for the existing four-item bottom navigation and is broad enough to contain both global ratings and future tournaments.

Important implementation rule:

- the existing internal route / DOM ownership may remain named `tournaments` / `screen-tournaments` / `data-shell-nav="tournaments"`;
- do **not** rename the internal route only for wording;
- only the user-facing navigation label changes to **«Арена»**, unless a later architectural task explicitly migrates route IDs.

This avoids unnecessary router, shell and cache breakage.

### Arena screen

After opening **«Арена»**, the page title is:

**Соревнования**

The screen has two primary visible tabs:

1. **Рейтинг**
2. **Турниры**

Use tabs / segmented controls, not a dropdown. There are only two top-level choices and both must remain obvious.

### Rating tab

**Рейтинг** is the home of the global MiniGamesWorld rating/leaderboard.

It is **not a tournament leaderboard**.

It contains a per-game selector for all 8 supported games:

1. Крестики-нолики
2. Четыре в ряд
3. Морской бой
4. Русские шашки
5. Реверси
6. Шахматы
7. Го
8. Домино

Every game must always be reachable on mobile and desktop.

The game selector must support:

- touch horizontal swipe;
- mouse-wheel horizontal movement;
- mouse drag;
- explicit left/right affordances when content overflows;
- active-game highlighting;
- no hidden/unreachable tabs beyond the viewport.

The leaderboard surface must have a visibly stronger border/contrast than the rejected Profile modal. It must remain readable on the existing dark/neon background.

### Tournaments tab

**Турниры** is reserved for actual tournament entities:

- tournament events;
- schedules / status;
- entry/participation;
- tournament-specific results and tournament-specific leaderboards.

Until tournament functionality is implemented, this tab may show a restrained “coming later / preparing” state. It must not fake tournaments by reusing the global Rating table.

### Profile

Profile remains the player’s personal area.

Profile may show:

- the player’s own visible rating points per game;
- later, the player’s own rank/placement if useful;
- personal statistics and existing cosmetics/profile content.

Profile must **not** contain the full global leaderboard list.

For MVP-20.3 corrective, a cross-link such as “Открыть Арену” is optional and is not required for acceptance. Do not duplicate the leaderboard in Profile.

---

## 3. User-facing PRESEASON decision

The server-side competition state **PRESEASON** remains valid and must remain internally supported.

However, the word/badge **«ПРЕДСЕЗОН» must not be shown in the normal player-facing UI**.

Do not remove the internal state, database fields, season identifiers or safety semantics merely because the visible badge is removed.

The purpose of PRESEASON is technical/product staging before official season activation. It is not useful copy for the normal player at this point.

---

## 4. Rating model that must remain authoritative

MVP-20.3 public rating is per game, for all 8 supported games.

### Visible score

- normal eligible human win: **+1 visible rating point**;
- tournament eligible human win: **+2 visible rating points**;
- draw: **0**;
- loss: **0**;
- bot game: **0**;
- unsupported/technical/non-normal result: **0**;
- hidden skill is a separate internal model and must never be exposed by the public leaderboard.

### Eligibility to appear in a game leaderboard

A player is eligible after:

- at least **5 rated human matches** in that game; and
- at least **1 human win** in that game.

This rule remains authoritative.

What changes is presentation:

- do **not** show the rejected large line above the public list such as
  `До таблицы: 1/5 матчей · 1/1 побед`;
- eligibility should be applied automatically;
- if the product later needs to explain it, put it behind a compact info/help affordance or next to the player’s own status, not as a dominant line above the global list.

### Anti-farming

Authoritative rule:

- maximum **3 credited wins against the same opponent per Moscow day** for rating points;
- day boundary uses **Europe/Moscow**;
- the 4th and later same-opponent wins that day award **0 rating points**;
- the underlying human match/result remains durable and auditable;
- such a match may still count as a played human match / human win for eligibility; the anti-farming rule caps points, not the existence of the match.

Do not “fix” this by deleting match history.

### Tie-break order

Leaderboard order remains deterministic:

1. visible points descending;
2. credited wins descending;
3. earlier time reaching the equal score;
4. stable MGW ID ascending.

---

## 5. Identity rules for the public leaderboard

The leaderboard must display canonical MiniGamesWorld identity data:

- canonical `mgw_users.nickname`;
- canonical equipped avatar item;
- public MGW ID only where an ID is needed;
- never hidden skill or internal provider identifiers.

### Staging technical users

The manual staging review showed rows like `Player4576286` / `Player8249748` with rating points.

Root cause identified:

- staging E2E users are `development` provider identities;
- newly created MGW accounts receive generated canonical nicknames in the form `PlayerXXXXXXX`;
- automated staging matches legitimately produced visible rating rows.

These are **technical test identities**, not public players.

Authoritative corrective rule:

**Any MGW account with a `mgw_identities.provider = 'development'` identity must be excluded from the public leaderboard query.**

Do not destructively delete their match/rating audit history merely to hide them. Public filtering is the correct boundary.

Real player rows continue to use the canonical MGW nickname, not Telegram first name/provider display name.

---

## 6. Performance rule for leaderboard reads

The rejected staging behavior could spend a long time on “Загрузка…”.

The public leaderboard is a read-heavy surface. Switching game tabs must not run the heavy Profile synchronization path.

Rejected pattern:

- leaderboard endpoint calling Profile-style `snapshotForProfile()`;
- that path can synchronize legacy JSON to DB and process a large pending set before every board read.

Corrective direction:

- leaderboard endpoint reads from normalized DB rating/participation data;
- it may run a **bounded lightweight projected-match catch-up** if required;
- it must not trigger a full legacy JSON realtime synchronization on each tab switch;
- client should cache recent per-game results and coalesce duplicate in-flight requests;
- default game may be warmed after app-ready, without blocking initial application boot.

The corrective branch currently uses a 45-second client cache and one in-flight request per game. This is implementation detail, not a forever product constant; the invariant is that tab switching must feel fast and must not repeatedly pay a full synchronization cost.

---

## 7. Visual/interaction acceptance requirements

The corrected Arena → Rating UI must satisfy all of the following:

- full leaderboard is absent from Profile;
- bottom label reads **«Арена»**;
- page heading reads **«Соревнования»**;
- primary tabs read **«Рейтинг» / «Турниры»**;
- Rating is the default competition surface for the current MVP;
- all 8 games are reachable;
- overflow works on touch and mouse;
- border/outline is clearly visible against the background;
- no visible PRESEASON badge/copy;
- no dominant “До таблицы …” qualification strip above the leaderboard;
- no `development` E2E/test accounts in public rows;
- canonical player nickname/avatar are used;
- loading is materially faster than the rejected Profile modal;
- empty state is clean and does not imply an error;
- hidden skill is never shown.

---

## 8. What is already changed on the corrective branch

The current work branch is ahead of accepted staging and already contains partial implementation:

- removed the full leaderboard launcher/modal ownership from `profile-screen-v110.js`;
- added a dedicated competition screen module `tournaments-screen-v1.js`;
- added Rating / Tournaments primary tabs;
- added 8-game horizontal selector with arrows, wheel, mouse drag and touch overflow behavior;
- added stronger leaderboard borders and dedicated Arena/competition styling;
- changed leaderboard endpoint away from Profile’s heavy `snapshotForProfile()` path to bounded projected-match processing;
- added public query exclusion for `development` provider identities;
- removed PRESEASON from the new user-facing competition presentation;
- removed the rejected eligibility-progress strip from the new public board;
- added short client caching/in-flight request coalescing and default-board warm-up.

Important: at this exact checkpoint, the branch still has the bottom visible label temporarily set to **«Соревнования»**. Before merge it must be changed to the final accepted bottom label **«Арена»** while keeping the page heading **«Соревнования»**.

---

## 9. What is NOT yet accepted / must not be called finished

This corrective is **not yet merged** and is not yet manually accepted.

Before closure it still requires:

- final bottom-nav copy = **Арена**;
- client/runtime version cache-bust updates as needed;
- MVP-20.3 contract tests updated from the obsolete “leaderboard must be in Profile” requirement to the new Arena architecture;
- tests for excluding development identities;
- tests/guards for no user-facing PRESEASON in the Arena board;
- tests/guards that all 8 game tabs exist in the Arena rating owner;
- syntax/lint/focused model tests;
- PR to `agent/mvp-13-2-staging`;
- green CI;
- merge to staging;
- fresh staging E2E;
- manual visual/interaction acceptance by the user.

Do not move to MVP-20.4 until this corrective is accepted.

---

## 10. Next economic/season context

After MVP-20.3 corrective is accepted, the planned next stage remains **MVP-20.4 — quarterly seasons / FINALIZING**.

The Arena architecture is intentionally chosen so future features have one stable home:

- global per-game Rating;
- official seasons;
- season timers/status;
- tournament events;
- tournament-specific standings;
- future seasonal rewards / medals / Hall of Fame.

Do not move these competition systems back into Profile.

---

## 11. Frozen boundaries

This corrective must not alter accepted game mechanics.

Do not change:

- rules/move legality of the 8 games;
- accepted game rendering/cosmetics mechanics except unrelated cache/version plumbing;
- balance/economy ownership;
- hidden-skill calculations unless a separate task explicitly requires it;
- existing public rating arithmetic defined above;
- production/main/Cron unless explicitly authorized in a later task.

The work is an MVP-20.3 **leaderboard UX/performance/public-filter corrective**, not a rewrite of the game system.
