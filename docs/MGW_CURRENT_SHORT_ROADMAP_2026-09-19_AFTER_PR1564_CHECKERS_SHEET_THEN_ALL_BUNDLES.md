# MGW CURRENT SHORT ROADMAP — 2026-09-19 — AFTER PR #1564 — CHECKERS SHEET → ALL BUNDLES

## Current position

Milestone: **MVP-19.13 — «Наборы»**.

Staging branch:
`agent/mvp-13-2-staging`

Functional staging SHA:
`2f652a9d6edae73c4dc4be849ab11929d0c82fff`

Current reference games:
- Tic Tac Toe
- Checkers

Do not add the remaining six games yet.

---

## STEP 1 — manual review of PR #1564

The only open reference issue is:

**Store → Наборы → Шашки → Посмотреть и купить**

PR #1564 separated the new purchase detail from the old legacy `.store-v2-confirm-bundle` class.

Why this matters:
the old class forces `height:82px` and applies a 42×42 card style to every descendant `span`, which was corrupting labels, price cells and preview internals.

The new owner is:
`.store-v2-confirm-bundle-detail`

### Manual acceptance checklist

Check:
1. modal opens at the top;
2. title/close button do not overlap the first row;
3. no strange empty 42×42 squares;
4. two large items are aligned;
5. three effects are aligned;
6. text stays below previews;
7. “Неоновый комплект шашек” is readable;
8. price blocks are normal;
9. remaining balance is normal;
10. Buy button is reachable by scrolling;
11. no visible opening hitch.

If accepted: **freeze TTT + Checkers bundle reference.**

If rejected: fix only this sheet. Do not touch selector or accepted outer Checkers card.

---

## STEP 2 — if sheet still fails

Before any more cosmetic CSS tuning:

1. inspect the actual class list on the modal;
2. verify `.store-v2-confirm-bundle` is absent;
3. verify `.store-v2-confirm-bundle-detail` is present;
4. inspect computed styles for `height`, `align-items`, `justify-content`, and descendant `span`;
5. verify the frozen Checkers snapshot is not being rewritten by `store-screen-checkers-wrapper.js`;
6. verify Telegram/Hostinger is loading Store JS v65 / bundle detail cache identity v7.

Do **not** repeat blind clone/scale attempts.

---

## STEP 3 — freeze the two-game reference

After manual acceptance:

Freeze:
- horizontal game selector
- no status text in selector
- instant first switching / pre-render pattern
- TTT card/detail
- outer Checkers card
- corrected Checkers detail
- partial pricing behavior
- no auto-equip

Record that two-game reference as the template for generalized bundle work.

---

## STEP 4 — audit premium IDs for remaining six games

Remaining:
1. Chess
2. Reversi
3. Go
4. Domino
5. Four in a Row
6. Battleship

For each game verify:
- premium/highest theme ID
- premium/highest elements ID
- all 3 effect IDs
- existing Store preview owner/module
- current individual prices
- equip slots

Expected shared price model:
- premium theme: 12,000
- premium elements: 12,500
- effects: 2,500 + 5,000 + 7,500
- separate total: 39,500
- bundle cap: 34,000

Never guess IDs.

---

## STEP 5 — generalized backend/data pass

Create/update bundle offers for all remaining games.

Requirements:
- exactly five active valid game items per bundle;
- do not delete historical referenced offers blindly;
- preserve TTT and Checkers offer IDs;
- keep durable purchase history;
- keep missing-item fulfillment;
- keep idempotency;
- keep no-auto-equip.

Generalize Store snapshot so all eight game bundles are exposed in canonical order:

1. tictactoe
2. chess
3. checkers
4. reversi
5. go
6. domino
7. four_in_a_row
8. battleship

Client must not infer composition from display names.

---

## STEP 6 — generalized Bundles UI

Use the accepted two-game reference.

For each new game:
- add one selector item;
- add one pre-renderable panel;
- reuse accepted Store/Profile preview owners;
- do not recreate cosmetics;
- use same 2 + 3 five-member composition;
- use same progress / partial price language;
- use same detail structure;
- preserve mobile scroll and horizontal selector.

Performance:
- inactive panels may exist for instant switching;
- heavy inactive animations must be paused/stopped;
- do not let eight simultaneous animated previews run in the background.

---

## STEP 7 — tests

Expand MVP-19.13 contract to prove:

- eight bundles exist;
- five valid active members each;
- one premium theme + one premium elements + all three effects;
- full price = 34,000;
- separate total = 39,500;
- partial ownership excludes already-owned items;
- current partial quote is capped by full bundle price;
- purchase grants only missing items;
- no ownership duplicates;
- all-owned bundle is not purchasable;
- no auto-equip;
- purchase remains idempotent;
- all eight bundles exposed in canonical order;
- selector has all eight games;
- no TTT/Checkers-only fallback visual;
- accepted preview owners are reused;
- mobile geometry/scroll remains valid.

Keep existing Store, Profile, game-cosmetics and game-specific suites green.

---

## STEP 8 — final manual review

Bring the user back only when the six-game generalization is actually ready.

Manual review:
- horizontal selector across all eight;
- switch each game;
- inspect every 2 + 3 composition;
- inspect representative detail sheets;
- test at least one full purchase;
- test at least one partial purchase;
- verify all-owned state;
- verify Profile inventory refresh;
- verify nothing auto-equips;
- verify no accepted LIVE/Store/Profile cosmetic changed.

---

## Definition of done

MVP-19.13 is CLOSED only when:

- all eight bundles exist;
- two-game reference is preserved;
- all eight use correct accepted previews;
- purchase/detail flow is coherent;
- pricing/partial ownership is correct;
- targeted CI is green;
- exact staging SHA is deployed;
- user manually accepts the complete Bundles system.

Until then, do not start a different milestone.
