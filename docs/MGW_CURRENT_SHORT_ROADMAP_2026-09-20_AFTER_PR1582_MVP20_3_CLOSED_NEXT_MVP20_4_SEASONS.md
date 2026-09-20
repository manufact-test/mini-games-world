# MGW CURRENT SHORT ROADMAP — AFTER PR #1582 — MVP-20.3 CLOSED

**Date:** 2026-09-20  
**Repo:** `manufact-test/mini-games-world`  
**Staging branch:** `agent/mvp-13-2-staging`  
**Accepted runtime SHA:** `2c320483eef959f63e50fdec51dd301599f1ecc9`  
**Current state:** **MVP-20.3 CLOSED**  
**Next stage:** **MVP-20.4 — quarterly seasons / FINALIZING**

---

## CLOSED

### MVP-20.1 — visible per-game rating
- +1 normal human win.
- +2 tournament human win.
- loss/draw/bot/technical = 0.
- duplicate result cannot award twice.
- visible rating is separate from hidden skill.

### MVP-20.2 — hidden skill
- hidden skill remains matchmaking-only.
- never expose it in Profile or public leaderboard.

### MVP-20.3 — Arena / leaderboards / anti-farming
Final accepted architecture:

- bottom nav: **Арена**;
- page: **Соревнования**;
- tabs: **Рейтинг / Турниры**;
- full leaderboard lives in Arena, not Profile;
- Profile keeps personal rating only;
- all 8 game ratings are available;
- game strip supports touch, wheel, drag and arrow buttons;
- first and last tabs align to the content edges;
- arrows are centered SVG controls;
- table header: **№ / Игрок / Очки**;
- real rows use canonical MGW nickname + equipped avatar;
- staging `development` identities are excluded publicly;
- PRESEASON stays internal and is hidden from normal UI;
- rejected `До таблицы...` strip stays removed;
- leaderboard reads use lightweight DB projection + short client caching.

Eligibility:

- 5 rated **human** matches in that game;
- at least 1 **human** win;
- bot games do not count.

Anti-farming:

- max 3 credited wins vs the same opponent / game / Moscow day;
- later same-opponent wins remain durable but give 0 additional rating points.

Tie-break:

1. points desc;
2. credited wins desc;
3. earlier equal-score arrival;
4. stable MGW ID.

---

## FINAL MVP-20.3 PR CHAIN

- #1578 — base leaderboards + anti-farming.
- #1579 — Arena ownership / performance / scroll interaction.
- #1580 — intermediate manual corrective; superseded on technical-player visibility.
- #1581 — final public identity filtering + №/Игрок/Очки + SVG arrows.
- #1582 — final right-edge selector alignment.

Accepted runtime after #1582:

`2c320483eef959f63e50fdec51dd301599f1ecc9`

Fresh staging E2E on that exact SHA: **SUCCESS**.

---

## MANUAL ACCEPTANCE RESULT

User manually verified:

- Arena layout;
- fast loading;
- scrolling/swiping;
- real nickname;
- real equipped avatar;
- rating points;
- Profile personal rating.

Last reported visual issue was only excess empty space after the final game tab. PR #1582 removes that permanent spacer and keeps scroll-padding only for the overlay arrows.

No further MVP-20.3 feature work should be started unless a reproducible bug appears.

---

## NEXT — MVP-20.4

Start **quarterly seasons / FINALIZING**.

Before coding:

1. re-read the authoritative global master plus the latest season-readiness delta;
2. verify exact staging SHA/branch;
3. preserve the accepted Arena architecture;
4. trace the current rating/season owners before adding a writer.

MVP-20.4 must cover the already agreed direction:

- quarterly season calendar;
- Moscow season boundaries;
- official ACTIVE vs internal PRESEASON semantics;
- automatic/idempotent close + awards + next season when READY;
- durable FINALIZING / ASSETS_REQUIRED if required award assets are missing;
- safe resume without duplicate awards;
- no production/main/Cron/live DB action without explicit approval.

Keep accepted game mechanics, rating arithmetic, hidden skill and Arena/Profile ownership frozen.
