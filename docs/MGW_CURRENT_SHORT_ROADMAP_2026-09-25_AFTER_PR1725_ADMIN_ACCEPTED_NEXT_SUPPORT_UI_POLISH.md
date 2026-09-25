# MGW CURRENT SHORT ROADMAP — ADMIN ACCEPTED / NEXT SUPPORT UI POLISH

**Date:** 2026-09-25  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative staging:** `agent/mvp-13-2-staging`  
**Accepted runtime SHA:** `27b6095a512f0f6306fad9d0291d0515635099b8`  
**PR #1723:** Support ticket UX repair merged  
**PR #1724:** Web Admin rework merged  
**PR #1725:** Admin manual-review polish merged  
**Web Admin:** ACCEPTED FOR NOW  
**MVP-22.1 Support backend/functionality:** WORKING  
**Open:** 3 deferred Mini App visual corrections

## Current point

The Admin detour is complete enough to return to the Support acceptance that was paused on 2026-09-24.

Support functional checks already passed far enough to prove:

- existing ticket `SUP-260924-905F476B`;
- replies work;
- a second image below 2 MB works;
- PR #1723 owns immediate attachment validation and 3×2 MB selection rules.

Do **not** reopen attachment validation as a new task unless it reproduces again.

## Task 1 — «Мои обращения»

Start here.

Problem:

- the **«Мои обращения»** menu item does not visually match the rest of the menu;
- size / color / geometry differ from adjacent standard menu items.

Required result:

- same card dimensions as neighboring menu items;
- same border/background treatment;
- same radius and internal spacing;
- same icon geometry/alignment system;
- no unique Support-hub styling that makes this one item look like a different control;
- keep the existing click target and `supportTicketsBtn` behavior unchanged.

After implementation:

1. focused static/runtime regression;
2. merge to staging;
3. exact staging E2E;
4. manual Telegram visual check.

## Task 2 — «Ваше сообщение»

After Task 1 is accepted:

- user message card should use the full available thread width;
- keep admin/user distinction without making the user's card artificially narrow.

## Task 3 — red ×

After Task 2:

- remove unnecessary red/accented styling from the selected-file remove button;
- optically center the ×;
- preserve obvious remove affordance and tap target.

## Then

Resume the rest of MVP-22.1 manual acceptance from `SUP-260924-905F476B`.

If a new concrete Admin or attachment-open issue reproduces during that pass, fix the reproduced issue only; do not rebuild the accepted Admin/Support ownership.
