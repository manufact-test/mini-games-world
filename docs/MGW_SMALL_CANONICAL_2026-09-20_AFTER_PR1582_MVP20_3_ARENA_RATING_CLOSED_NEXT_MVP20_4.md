# MGW SMALL CANONICAL — AFTER PR #1582 — MVP-20.3 ARENA / RATING CLOSED

**Date:** 2026-09-20  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging branch:** `agent/mvp-13-2-staging`  
**Accepted runtime SHA:** `2c320483eef959f63e50fdec51dd301599f1ecc9`  
**Status:** **MVP-20.3 CLOSED**. Arena / public per-game rating is accepted.  
**Next:** **MVP-20.4 — quarterly seasons / FINALIZING**.

---

## 1. Final competition navigation

Bottom navigation is:

**Главная · Арена · Магазин · Профиль**

Visible player-facing label is **«Арена»**.

Internal route ownership remains `tournaments` / `screen-tournaments`; do not rename that route only for wording.

Inside Arena:

- page title: **«Соревнования»**;
- primary tabs: **«Рейтинг» / «Турниры»**;
- **Рейтинг** is the current global per-game leaderboard;
- **Турниры** is reserved for actual tournament events, participation, schedules and tournament-specific standings.

The full global leaderboard must **not** return to Profile.

Profile keeps the player’s own per-game visible rating/statistics only.

---

## 2. Arena → Rating accepted UI

The Rating surface supports all 8 games:

1. Крестики-нолики
2. Четыре в ряд
3. Морской бой
4. Русские шашки
5. Реверси
6. Шахматы
7. Го
8. Домино

The horizontal game selector is accepted with:

- touch swipe;
- mouse wheel horizontal scrolling;
- mouse drag;
- explicit left/right buttons;
- SVG chevrons centered inside the buttons;
- active-game highlighting;
- first tab aligned to the left content edge;
- final tab aligned to the right content edge;
- no permanent empty spacer at either end.

The final right-edge correction was PR **#1582**.

The leaderboard itself has:

- clear visible border/contrast;
- game title;
- table header **№ / Игрок / Очки**;
- rank;
- canonical player avatar;
- canonical MGW nickname;
- visible rating points.

Redundant Arena subtitle below **«Соревнования»** is removed.

---

## 3. Player identity rules

Public leaderboard rows use:

- `mgw_users.nickname`;
- `mgw_users.equipped_avatar_item_id`;
- stable public MGW identity only where needed.

Real-player manual staging verification confirmed that the leaderboard displays the actual MGW nickname and equipped avatar after eligibility is reached.

Generated `PlayerXXXXXXX` rows seen during development were staging E2E accounts:

- staging A/B identities use provider `development`;
- newly created technical MGW accounts receive generated `PlayerXXXXXXX` nicknames;
- repeated automated human-vs-human staging matches can accumulate valid visible-rating history.

Final public rule:

**accounts linked to a `development` provider identity are excluded from the public player-facing leaderboard.**

Their audit/rating history is not destructively deleted.

---

## 4. Rating rules — authoritative

Rating is separate for every game.

### Visible points

- normal human win: **+1**
- tournament human win: **+2**
- loss: **0**
- draw: **0**
- bot game: **0**
- technical/unsupported result: **0**

Hidden matchmaking skill remains separate and must never be displayed as public rating.

### Leaderboard eligibility

To appear in a specific game leaderboard, the player must have:

- at least **5 rated matches against human players** in that game;
- at least **1 human win** in that game.

Bot matches:

- do not give visible rating points;
- do **not** count toward the 5 required matches;
- bot wins do **not** satisfy the 1 required win.

The rejected large UI line such as `До таблицы: 1/5 матчей · 1/1 побед` remains removed from the global board.

### Anti-farming

Maximum credited rating wins against the same opponent:

**3 per Europe/Moscow day per game.**

The 4th and later same-opponent wins that Moscow day:

- remain real durable human matches;
- remain auditable;
- award **0 additional visible rating points**.

Do not delete or rewrite match history to enforce this.

### Tie-break order

1. points descending;
2. credited wins descending;
3. earlier arrival at the current equal score;
4. stable MGW ID ascending.

---

## 5. PRESEASON rule

Internal competition state **PRESEASON** remains valid server-side.

Normal player-facing Arena/Profile UI must **not** show the word/badge **«ПРЕДСЕЗОН»**.

Do not remove the internal state or its safety semantics.

---

## 6. Performance / runtime owner

Leaderboard reads must remain lightweight.

Accepted direction:

- normalized DB is the public leaderboard source;
- endpoint uses bounded projected-match catch-up;
- endpoint must not run Profile’s heavy full synchronization path on every game-tab switch;
- client reuses duplicate in-flight requests;
- recent per-game boards are cached briefly;
- default board may be warmed after app-ready.

The accepted implementation uses `processProjectedMatches(50)` rather than Profile-style `snapshotForProfile()` for leaderboard reads.

---

## 7. Accepted corrective PR history

- **#1578** — initial MVP-20.3 leaderboards + anti-farming.
- **#1579** — moved global leaderboard out of Profile into Arena/competition UI; added performance and interaction corrective.
- **#1580** — intermediate manual-polish pass; temporarily restored staging technical rows while their identity was being diagnosed. This behavior is superseded.
- **#1581** — final public table semantics: № / Игрок / Очки, centered SVG arrows, technical development identities hidden, real canonical nickname/avatar preserved.
- **#1582** — final selector end-edge alignment; removed the permanent right spacer.

PR #1580 must not be used as the final identity/public-row rule; #1581+ supersede it.

---

## 8. Acceptance evidence

Manual staging acceptance established:

- Arena navigation and structure are understandable;
- fast leaderboard loading;
- horizontal selector works by swipe/scroll/drag/buttons;
- real MGW nickname and equipped avatar are shown;
- personal rating remains in Profile;
- full leaderboard is absent from Profile;
- table columns are understandable;
- PRESEASON and qualification-progress clutter are absent;
- final remaining issue was the extra empty space after the last game tab.

PR #1582 fixed that final end-edge spacing.

Exact staging runtime `2c320483eef959f63e50fdec51dd301599f1ecc9` passed:

- Hostinger exact-revision readiness;
- staging preflight;
- full two-context staging E2E;
- final published staging E2E status = **SUCCESS**.

---

## 9. Frozen boundaries

MVP-20.3 corrective work must not be reopened to casually alter:

- accepted rules/move legality of the 8 games;
- visible-rating arithmetic;
- 5-human-match / 1-human-win eligibility;
- Moscow-day anti-farming cap;
- hidden-skill calculation;
- accepted economy/store/cosmetics ownership;
- production/main/Cron/live DB without explicit authorization.

Future competition work belongs under Arena and builds on this accepted foundation.

---

## 10. Next authoritative stage

**MVP-20.4 — quarterly seasons / FINALIZING**

Continue from the existing season-readiness decisions:

- quarterly seasons;
- Moscow boundaries;
- PRESEASON stays internal until official activation;
- durable FINALIZING / ASSETS_REQUIRED behavior;
- idempotent season close/award/next-season handling;
- no broken or partial awards when required assets are missing.

Do not move competition systems back into Profile.
