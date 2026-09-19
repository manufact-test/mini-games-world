# MGW SMALL CANONICAL — 2026-09-19 — MVP-19.13 BUNDLES — TTT + CHECKERS REFERENCE

## 0. Authoritative checkpoint

Repository: `manufact-test/mini-games-world`  
Working/staging branch: `agent/mvp-13-2-staging`  
Functional staging SHA after PR #1564: `2f652a9d6edae73c4dc4be849ab11929d0c82fff`

Milestone: **MVP-19.13 — game bundles / «Наборы»**.

This checkpoint supersedes the previous short roadmap that only said “Battleship closed → build all bundles”. We intentionally did **not** build all eight immediately. The user required one reference bundle first, then a second reference bundle, and only after both are correct may the pattern be generalized.

Current reference games:
1. Tic Tac Toe
2. Checkers

Do **not** add the remaining six games until the Checkers purchase sheet is manually accepted.

---

## 1. Previous milestone status

MVP-19.12 Battleship remains CLOSED / FROZEN.

Do not reopen accepted:
- Store cards,
- Profile cards,
- LIVE cosmetics,
- mechanics,
- fire reliability,
- accepted effects.

The next active milestone is exclusively MVP-19.13 Bundles unless a real regression is reproduced.

---

## 2. Bundle architecture already accepted / retained

The backend bundle foundation is valid and must remain:

- `mgw_product_offers.offer_type = 'bundle'`
- `members_json`
- durable purchase audit/idempotency
- already-owned protection
- only missing items are granted
- bundle purchase never auto-equips
- partial-ownership quote is supported
- Store/Profile inventory refresh remains authoritative

Existing canonical reference bundle offers:
- `ttt-premium-bundle`
- `checkers-premium-bundle`

Both are five-item premium bundles:
- premium theme / field / board
- premium elements / marks / pieces
- all three effects

Reference economy:
- separate premium contents total: **39,500 coins**
- full bundle cap: **34,000 coins**
- full saving: **5,500 coins**
- partial quote: `min(full_bundle_price, sum(individual prices of missing items))`

Therefore if only one missing item costs 12,000, current bundle quote is 12,000. There is no extra per-item bundle discount below the 34,000 full-bundle cap.

Do not change this economy unless the user explicitly requests it.

---

## 3. Tic Tac Toe reference bundle

TTT was the first reference implementation and is the visual/UX baseline for bundle composition.

Accepted behavior:
- premium five-item bundle card
- clear 2 + 3 composition
- ownership checks on already-owned members
- progress / missing count
- partial-price behavior
- “Посмотреть и купить”
- no duplicate “Комплект собран” controls in the completed state
- detail / purchase flow
- purchase does not auto-equip

TTT should remain unchanged while fixing Checkers.

---

## 4. Shared Bundles navigation — accepted direction

The Bundles tab now uses a **horizontal game selector** instead of stacking one complete screen per game.

Current selector contains only:
- Крестики-нолики
- Шашки

Required behavior already implemented:
- natural-width horizontal items
- same typography/height for every item
- no “Собран”, counts, prices or ownership status inside selector items
- labels do not truncate merely because only two games currently exist
- when more games are added, selector scrolls horizontally
- selector is touch-scrollable
- one game is active visually at a time
- both current panels may be pre-rendered so first switch does not hitch
- inactive Checkers effect previews must not keep active animation work running

The user manually confirmed the previous first-switch lag is gone after pre-render/prewarm work.

Do not return to a long vertical list of all games.

---

## 5. Checkers outer bundle card — current accepted visual state

The user manually confirmed that the **outer Checkers bundle screen/card is now good**:

- switching between TTT and Checkers no longer visibly lags;
- top two member cards are aligned;
- bottom three effect cards are aligned;
- overall heights are even;
- selector works;
- outer card previews are acceptable;
- page scrolling works;
- purchase button is reachable.

Important: do not “improve” or redraw the accepted outer card while fixing the purchase sheet.

The current Checkers bundle members are:
- `game-checkers-board-neon`
- `game-checkers-pieces-neon`
- `game-checkers-effect-move`
- `game-checkers-effect-capture`
- `game-checkers-effect-promotion`

---

## 6. Checkers purchase sheet — ONLY OPEN VISUAL ISSUE

At this checkpoint, the only user-reported bundle problem is the Checkers **“Подтвердить покупку”** sheet.

Symptoms seen before PR #1564:
- first row appeared clipped / started too high;
- preview geometry inside the sheet looked broken;
- labels/price areas contained strange square blocks;
- content looked unlike the accepted TTT purchase sheet;
- repeated attempts to clone/scale the accepted outer grid appeared to make little or no visible difference.

### Root cause found after the last screenshot

The new MVP-19.13 bundle detail was reusing an **old legacy class**:

`.store-v2-confirm-bundle`

In old `app/assets/css/screens/store-v2.css`, that legacy class has primitive pilot rules including:

- `height:82px`
- centered flex layout
- and a broad descendant rule:
  `.store-v2-confirm-bundle span { ... width:42px; height:42px; ... }`

That means **every descendant span** in the new sheet could be turned into an old 42×42 pilot tile, including labels, price spans and preview internals. This explains the “empty squares”, collisions and apparent failure of the previous layout fixes.

### PR #1564 — actual root corrective

PR #1564 separates the new detail flow from the legacy pilot class.

New class:
`.store-v2-confirm-bundle-detail`

All MVP-19.13 detail sizing/scroll rules now target the new class.

The Checkers wrapper also detects:
`.store-v2-confirm-bundle-detail`

The new detail sheet must **never reuse** `.store-v2-confirm-bundle`.

Functional staging SHA:
`2f652a9d6edae73c4dc4be849ab11929d0c82fff`

At the moment this document is written:
- exact Hostinger deployment wait passed;
- staging preflight passed;
- targeted Bundle Reference / Checkers Store / Store v2 / Game Cosmetics / TTT C1+C2 / Profile Avatars suites were green on the candidate;
- manual user acceptance of the corrected Checkers purchase sheet is still **PENDING**.

Do not mark MVP-19.13 reference closed until that one sheet is visually accepted.

---

## 7. Relevant corrective PR chain

Bundle reference work in this session:

- #1558 — game selector + scrollable bundle purchase sheet
- #1559 — selector cleanup + Checkers preview parity attempt
- #1560 — native Checkers Store preview owner + prewarm
- #1561 — uniform Checkers preview fit / sheet parity attempt
- #1562 — clone accepted outer Checkers grid into purchase sheet
- #1563 — freeze exact outer Checkers grid geometry as modal snapshot
- #1564 — **root fix:** isolate new bundle purchase detail from legacy `.store-v2-confirm-bundle` CSS

The important lesson is not to repeat #1559–#1563 style tuning if #1564 still has an issue. First inspect computed styles/context on `.store-v2-confirm-bundle-detail`; the legacy class collision was the real systemic bug.

---

## 8. Current visual owner rule

For Bundles, accepted game visuals should be **composed from existing Store preview owners**, not redrawn as a third independent cosmetic implementation.

For Checkers:
- outer bundle uses the accepted Store-owned preview language;
- purchase detail may reuse/freeze the accepted outer composition;
- no bundle code should independently redesign board/piece/effect artwork;
- the frozen purchase snapshot must be excluded from Checkers repair/animation rewriting.

General rule for later six games:
**reuse accepted Store/Profile preview owners; do not create fake bundle-only cosmetics when an accepted owner already exists.**

---

## 9. Frozen areas during remaining MVP-19.13 work

Do not change while finishing Bundles:
- individual Store item prices
- individual Store cards
- Profile cards
- accepted LIVE cosmetics
- game mechanics
- equip slots
- one-effect-slot behavior
- purchase idempotency
- no-auto-equip semantics
- Battleship accepted work
- accepted TTT bundle reference
- accepted outer Checkers bundle card

Only touch bundle composition/data/detail flow needed for MVP-19.13.

---

## 10. Remaining games after two-game reference acceptance

Only after TTT + Checkers reference is manually accepted, generalize to:

3. Chess
4. Reversi
5. Go
6. Domino
7. Four in a Row
8. Battleship

For each future bundle:
- exactly five premium members
- premium theme
- premium elements
- all three effects
- same canonical full bundle price unless explicitly changed
- actual existing item IDs
- accepted preview owners
- partial ownership
- no auto-equip

Do not invent member IDs or visuals. Verify each catalog first.

---

## 11. Delta that should be carried into the BIG canonical

When the full master canonical is next updated, append these decisions:

1. **MVP-19.13 Bundles UX:** horizontal per-game selector, not eight vertically stacked screens.
2. Selector item contains only game name; no ownership/status text.
3. Panels can be pre-rendered for instant first switching, but inactive animations must be idle.
4. TTT is the first accepted visual bundle baseline.
5. Outer Checkers bundle card is manually accepted as the second reference visual.
6. Bundle details use `.store-v2-confirm-bundle-detail`; the legacy `.store-v2-confirm-bundle` is forbidden for the new flow because its old descendant-span styling corrupts modern detail content.
7. Bundle visuals reuse accepted Store/Profile owners; no parallel fake cosmetic truth.
8. Partial quote remains `min(34,000, missing individual total)`.
9. Bundle purchase grants missing items only and never auto-equips.
10. Reference phase remains OPEN until the user accepts the Checkers purchase sheet after PR #1564.
11. Only after reference acceptance should the remaining six game bundles be added in one generalized pass.

---

## 12. Immediate manual checkpoint

Open:
**Store → Наборы → Шашки → Посмотреть и купить**

Verify after PR #1564:
- no 42×42 legacy squares in text/price areas;
- first row begins below the sheet header;
- five previews are readable and aligned;
- layout resembles the accepted TTT detail language;
- vertical scrolling remains available;
- price and remaining balance remain correct;
- purchase button remains reachable.

If this passes, freeze the TTT + Checkers reference and proceed to the six remaining games.
