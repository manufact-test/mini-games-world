# MGW SMALL CANONICAL — 2026-09-15 — MVP-19.8 GO CLOSED / NEXT: MVP-19.9 DOMINO

## 0. Purpose

This is the newest small canonical handoff for continuing MiniGamesWorld in a fresh chat.

Source priority for the next chat:

1. factual current GitHub / runtime / staging;
2. newest CURRENT SHORT ROADMAP / SESSION CHECKPOINT;
3. this newest SMALL CANONICAL;
4. global authoritative master / 27-MVP roadmap;
5. older checkpoints only as historical context.

Do not reconstruct the current task from older Go/Reversi/Checkers documents when this checkpoint or factual GitHub says otherwise.

---

## 1. Exact accepted product checkpoint

Repository: `manufact-test/mini-games-world`  
Integration / staging branch: `agent/mvp-13-2-staging`

Accepted Go product checkpoint before this documentation-only handoff:

- staging HEAD: `e23aff4749c1a662d9575ba5b29100db1b25ac7d`
- tree: `6a92c73ed539200d45af3de9e68e50fbcd940d61`
- latest accepted product PR: **#1431 — align Go rule markers for 9x9 and 13x13**
- manual Telegram result after #1431: **ACCEPTED**

The documentation PR that adds this file may move staging HEAD without changing runtime behavior. Therefore every new chat must fetch the factual current `agent/mvp-13-2-staging` HEAD before branching.

`main` / production are not part of this handoff.

---

## 2. MVP-19.8 — FINAL STATUS

# MVP-19.8 GO COSMETICS = CLOSED / MANUALLY ACCEPTED / FROZEN

The user has accepted the full Go slice:

- Store catalogue and purchase/equip behavior;
- Go Store visual previews;
- Go Profile tab / owned inventory projection;
- Profile equipped state and Store → Profile refresh behavior;
- real Go board width / short-viewport scrolling / reachable controls;
- Effect 1 — placement effect;
- Effect 2 — group capture effect;
- Effect 3 — territory-finish effect;
- Effect 3 canonical trigger restored to real finished-game / final-score flow;
- Go rules diagram markers for 9×9 and 13×13, including forbidden `×` and ko `↺` alignment.

Do not reopen Go merely to clean historical corrective files, rename old cache markers, or refactor accepted code. Reopen only for a new concrete reproducible regression or an explicitly requested cleanup stage.

---

## 3. Frozen Go lessons that MUST carry forward

### 3.1 Store is the visual source of truth

After a Store cosmetic is manually accepted:

- Profile should reuse the same visual language / primitive instead of maintaining a stale approximation;
- live game should project the accepted cosmetic identity one-to-one;
- do not silently redesign an accepted effect while wiring it live;
- manual Telegram visual acceptance outranks a green static test for actual appearance.

### 3.2 Profile geometry is a known high-risk area

Repeated completed-game work proved that generic Profile CSS can shrink, crop or distort game previews even when Store is correct.

For every new game, including Domino:

- compare Profile preview geometry directly with the accepted Store card;
- use an exact game-specific owner such as `data-game-type="domino"` rather than broad generic selectors;
- load the exact-owner corrective last when generic Profile rules win the cascade;
- if CSS alone is unreliable, use the proven hard-size runtime pattern: measure the actual preview width and mirror the required height / min-height / aspect-ratio explicitly, then repair after render, sheet-open, inventory refresh, tab switch and resize;
- do not allow a compact Profile strip to replace a full accepted Store preview;
- detail-sheet preview must use the same identity and proportions as Store;
- horizontal game tabs must remain scrollable on mobile;
- selecting a game tab must not jump the whole Profile page or rebuild the full Profile root unnecessarily.

Important: Domino may not use a square preview. Do **not** blindly copy Go's square ratio. First establish the Domino Store primitive and then force Profile to match **that exact ratio and width**.

### 3.3 Live physical-state ownership

Do not create a second owner for real game-piece movement.

Go exposed the failure mode clearly:

- base renderer owns real physical state / removal / timing;
- a paid cosmetic should decorate the authoritative event;
- never hide the real piece before the paid layer is known to render;
- if the paid effect fails, the base animation must remain a visible fallback;
- renderer `innerHTML` replacement can destroy child overlays, so do not depend on a fragile detached element living inside a subtree that the base renderer replaces.

Keep this rule for Domino tiles.

### 3.4 Economy / ownership invariants

- purchase never auto-equips;
- ownership is persistent and authoritative;
- no duplicate ownership;
- explicit equip / unequip only;
- one equipped item per slot/family;
- do not infer ownership from DOM state;
- Store and Profile must read the same inventory truth.

---

## 4. Previous cosmetics stages that remain frozen

- MVP-19.5 Chess — CLOSED / frozen
- MVP-19.6 Checkers — CLOSED / frozen
- MVP-19.7 Reversi — CLOSED / frozen
- MVP-19.8 Go — **CLOSED / frozen**

Do not regress these games while implementing Domino.

---

## 5. NEXT FORMAL STAGE

# MVP-19.9 — DOMINO COSMETICS

Authoritative remaining game order:

1. **19.9 Domino — NEXT**
2. 19.10 Tic-Tac-Toe
3. 19.11 Four-in-a-row
4. 19.12 Battleship
5. 19.13 Seasonal collection + full cosmetics regression

Bundles / `Наборы` remain a separate later Store scope and are **not** required to close Domino.

---

## 6. Factual Domino runtime starting point

Current factual Domino client owners on accepted staging include:

`app/assets/js/games/domino/`

- `entry.js`
- `renderer.js`
- `rules.js`
- `meta.js`
- `chain-layout.js`

Current Domino CSS owners:

`app/assets/css/games/domino/`

- `game.css`
- `rules.css`

Important current renderer facts:

- `renderDominoSurface(...)` owns the real Domino surface;
- it rebuilds the Domino surface markup through `container.innerHTML`;
- real table chain uses `.domino-chain-slot`;
- the newest played tile receives `.latest`;
- base animation owner `animateLatest(...)` adds `.animate-in` for a real `play` action;
- draw feedback uses `.draw-pulse` on the hand;
- authoritative presentation inputs include `game.last_action`, `game.move_count`, `game.chain`, `game.viewer_hand`, `game.playable_sides`, `game.stock_count`, turn/status state;
- `last_action.type` currently distinguishes at least `start`, `draw`, `pass`, `play`;
- the base renderer owns click/action dispatch for tile selection, chain-end placement and draw.

Do not modify Domino legality/rules/server behavior merely to make cosmetics easier.

---

## 7. Domino catalogue status: MUST BE AUDITED, NOT ASSUMED

This handoff does **not** claim a final canonical Domino cosmetic catalogue, exact product IDs, prices, slot names or effect concepts.

At the start of MVP-19.9, inspect factual current staging for:

- catalogue migrations / product inventory service;
- any Domino product rows already seeded;
- current Store game-cosmetic family conventions;
- current Profile game-cosmetic family conventions;
- reusable Chess / Checkers / Reversi / Go patterns.

If Domino products are not already defined, the next chat is authorized to make the new product decisions needed for a complete Domino slice and implement them, but must label them as **new MVP-19.9 decisions**, not recovered canon.

Do not stop just to ask the user to invent every product name or price unless a genuine product ambiguity blocks safe implementation.

---

## 8. NEW USER-REQUESTED WORKFLOW FOR DOMINO

For Domino the next chat should **not stop after Store**.

The requested order is:

### A. Factual audit first

Resolve exact current staging / import graph / Domino owners / catalogue / inventory / Store / Profile reuse points.

### B. Build Store completely

Implement the complete meaningful Domino Store slice:

- products / prices / slot mapping from factual evidence or clearly marked new decisions;
- purchase states;
- owned / equip / unequip states;
- polished previews;
- effect previews;
- mobile tab behavior;
- no auto-equip on purchase.

### C. Immediately build Profile / Portfolio in the SAME work block

Do not wait for a separate user approval between Store and Profile.

Profile must include:

- Domino game tab;
- all owned Domino cosmetics;
- correct equipped state;
- exact Store visual reuse / parity;
- correct detail-sheet preview;
- correct width and aspect ratio;
- no clipping / tiny preview / generic compact strip;
- horizontal mobile tab scroll;
- no full-page jump when choosing Domino;
- inventory refresh must make newly bought items visible without needing a lucky delayed remount.

### D. STOP for one combined manual review

The user will inspect Store + Profile together.

During this review, tune product visuals and preview animations until accepted.

### E. Only after Store + Profile + preview animations are accepted: wire Live

Then project equipped cosmetics into the real Domino game.

### F. Manual live review → freeze

Only after live Domino is accepted:

- mark MVP-19.9 CLOSED / frozen;
- update checkpoint/canonical;
- move to MVP-19.10 Tic-Tac-Toe.

---

## 9. Domino Profile / Portfolio anti-regression checklist

This section is intentionally explicit because Profile sizing caused repeated rework in previous games.

Before saying Domino Profile is ready, verify all of the following:

1. Store preview and Profile card have the **same intended content width and aspect ratio**.
2. The card does not become narrower because of generic Profile padding/max-width.
3. Preview content is not cropped by a parent fixed height.
4. Tile art is not scaled independently from its Store counterpart.
5. Any animation remains bounded inside the preview card.
6. Detail sheet uses the same Domino preview primitive, not a second approximation.
7. Owned cards do not change geometry when button state changes from Buy → Owned/Equipped.
8. Equipped border/state uses the accepted common green language.
9. Game tab icon size/alignment matches the other accepted game tabs.
10. On mobile, game tabs scroll horizontally and Domino can be selected repeatedly without viewport jumping.
11. After purchase/equip, Profile refresh reflects the current inventory in the same session.
12. If generic Profile CSS wins, add a Domino exact-owner layer loaded last; do not spend iterations on random broad overrides.

---

## 10. Domino live-animation guardrails for the later phase

When live work begins:

- base Domino renderer remains the physical tile/action owner;
- derive paid effects from authoritative `last_action` / `move_count` / real chain state;
- decorate the real `.domino-chain-slot.latest` / real tile where possible;
- do not run two competing transforms on the same tile;
- do not suppress the standard base animation unless the replacement is proven and has a safe fallback;
- remember `renderDominoSurface` rebuilds `container.innerHTML`; overlays placed inside that replaced subtree may vanish;
- key one-shot effects so polling does not replay them on every render;
- skipped polling frames must never result in an invisible tile or broken chain;
- preserve table geometry, hand interaction, playable target hit areas, draw action, timers, result/settlement and leave navigation;
- on short Telegram viewports prefer bounded vertical scrolling / reachable controls over shrinking the whole game into a tiny surface.

---

## 11. Scope safety

Unless the user explicitly changes scope:

- staging only;
- no `main`;
- no production;
- no Cron changes;
- no manual production DB changes;
- no broad refactor of frozen game engines;
- root-cause fixes only;
- focused CI + relevant regression contracts before merge;
- real Telegram manual acceptance is the final authority for appearance.

---

## 12. Exact handoff instruction for the next chat

First, fetch the factual current staging HEAD. The accepted Go product checkpoint is `e23aff4749c1a662d9575ba5b29100db1b25ac7d`; a later docs-only merge may be newer.

Then start:

**MVP-19.9 Domino cosmetics. Go is CLOSED and frozen. Perform a factual Domino runtime/catalogue/owner audit, then in the same work block build the complete Domino Store AND Domino Profile/Portfolio. Do not stop after Store. Store is the visual source of truth; Profile must reuse the exact Store preview geometry and must proactively defend against generic Profile width/height cropping. Stop only when Store + Profile are both deployed and ready for one combined manual review. After the user accepts/tunes those previews and animations, only then wire the accepted cosmetics into the real Domino game using authoritative `last_action` / `move_count` events without creating a second physical tile-movement owner.**
