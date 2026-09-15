# MGW CURRENT SHORT ROADMAP — 2026-09-15 — GO CLOSED → NEXT MVP-19.9 DOMINO

## CURRENT CHECKPOINT

Repo: `manufact-test/mini-games-world`  
Staging branch: `agent/mvp-13-2-staging`

Accepted Go product checkpoint:
- HEAD: `e23aff4749c1a662d9575ba5b29100db1b25ac7d`
- tree: `6a92c73ed539200d45af3de9e68e50fbcd940d61`
- latest accepted product PR: **#1431**
- user manual Telegram result: **ACCEPTED**

A documentation-only handoff PR may move staging HEAD after this checkpoint. Always fetch the factual current staging HEAD before branching.

Status:
- Chess — CLOSED / frozen
- Checkers — CLOSED / frozen
- Reversi — CLOSED / frozen
- Go — **CLOSED / frozen**
- **Next: MVP-19.9 Domino cosmetics**

## GO CLOSURE — DO NOT REOPEN

Accepted:
- Store;
- Profile / Portfolio;
- purchase / ownership / equip flow;
- board width and short-viewport behavior;
- Effect 1 placement;
- Effect 2 group capture;
- Effect 3 territory finish on canonical final trigger;
- Go rule diagrams / `×` / `↺` for 9×9 and 13×13.

Do not do opportunistic Go cleanup/refactors in the Domino chat.

---

# ACTIVE WORK ORDER FOR DOMINO

## PHASE A — FAST FACTUAL AUDIT

Do this first, without asking the user for information that can be recovered from GitHub/runtime.

Resolve:
- exact staging HEAD and active launch/import map;
- Domino client owners under `app/assets/js/games/domino/`;
- Domino CSS under `app/assets/css/games/domino/`;
- catalogue/inventory/equip support and migrations;
- reusable Store owners;
- reusable Profile owners;
- any already seeded Domino cosmetic products/slots;
- current Domino live event/state model.

Known current Domino files:
- `entry.js`
- `renderer.js`
- `rules.js`
- `meta.js`
- `chain-layout.js`
- `game.css`
- `rules.css`

Known live presentation facts:
- renderer rebuilds its surface using `container.innerHTML`;
- chain tiles use `.domino-chain-slot`;
- newest played tile uses `.latest`;
- base `animateLatest()` applies `.animate-in` on play;
- draw feedback uses `.draw-pulse`;
- useful authoritative inputs include `last_action`, `move_count`, `chain`, `viewer_hand`, `playable_sides`, `stock_count`, turn/status;
- `last_action.type` includes `start`, `draw`, `pass`, `play`.

Do not change game rules/legality/server behavior for cosmetics.

If the catalogue is not already defined, make the necessary MVP-19.9 product decisions yourself from the established cosmetics architecture and label them as NEW decisions. Do not pretend they were canonical before this stage.

---

## PHASE B — BUILD DOMINO STORE COMPLETELY

Implement the complete Store slice before stopping:
- product catalogue;
- prices / slots;
- purchase states;
- owned state;
- equip / unequip;
- previews;
- animated effect previews;
- mobile Store game-tab behavior;
- focused contracts/regressions.

Rules:
- purchase never auto-equips;
- one equipped item per slot/family;
- no DOM-faked ownership;
- Store preview becomes the visual source of truth.

### IMPORTANT USER WORKFLOW CHANGE

**DO NOT STOP FOR USER REVIEW AFTER STORE.**

Go directly to Profile / Portfolio in the same chat/work block.

---

## PHASE C — BUILD DOMINO PROFILE / PORTFOLIO IMMEDIATELY AFTER STORE

Required:
- Domino game tab;
- all owned Domino cosmetics visible;
- correct equipped state;
- Store → Profile inventory refresh;
- exact visual parity with Store previews;
- detail-sheet preview parity;
- mobile horizontal game-tab scrolling;
- no viewport jump on repeated game-tab selection;
- no unnecessary full Profile-root rebuild.

### CRITICAL PROFILE GEOMETRY RULE

Previous games repeatedly failed here. Treat this as a first-class acceptance requirement, not polish.

1. Determine the exact accepted Domino Store preview width/aspect ratio.
2. Profile must use that same preview primitive/geometry.
3. Do not allow generic Profile CSS to turn it into a narrower compact strip.
4. Use an exact Domino selector/owner, e.g. `data-game-type="domino"`, loaded after generic Profile rules.
5. If a parent fixed height/max-width still wins, use the proven hard-dimension runtime approach:
   - measure actual card/preview width;
   - set required height/min-height/aspect ratio explicitly;
   - re-apply after render, sheet-open, inventory refresh, tab switch and resize.
6. Detail sheet must not use a separate approximation.
7. Buy / Owned / Equipped states must not change card dimensions.
8. Animation must remain clipped/bounded to the preview card.

Do **not** blindly force a square. Domino should match its own Store composition.

### BEFORE DECLARING PROFILE READY

Compare Store and Profile side by side for:
- width;
- height/aspect;
- padding;
- tile scale;
- effect scale;
- text/footer state;
- equipped border;
- tab icon size/alignment.

If they differ, fix parity before asking the user to review.

---

## PHASE D — ONE COMBINED USER REVIEW: STORE + PROFILE

Only now stop for manual Telegram review.

The user will check:
- catalogue/products;
- visual style;
- card geometry;
- Store/Profile parity;
- effect preview concepts and animation quality;
- mobile behavior.

During this phase, iterate on **preview animations first** until the user says they are visually correct.

Do not wire unfinished/rejected preview effects into the live Domino game.

---

## PHASE E — LIVE DOMINO LAST

Start only after Store + Profile + preview animations are accepted.

Project equipped cosmetics into the real game one-to-one.

### LIVE OWNER RULES

- base Domino renderer remains the real tile/action owner;
- derive paid animations from authoritative `last_action` / `move_count` / chain state;
- use the actual `.domino-chain-slot.latest` / real tile when possible;
- do not stack a second transform owner onto the base `.animate-in` movement;
- do not hide a real tile before a paid effect is guaranteed;
- standard base animation must remain a fallback if paid presentation fails;
- renderer rebuilds `container.innerHTML`, so fragile child overlays can be destroyed on rerender;
- key one-shot effects to authoritative move identity to prevent polling replay;
- preserve hit targets, hand selection, playable chain ends, draw action, timer, settlement/result and leave navigation.

### SHORT-VIEWPORT / MOBILE RULE

Do not solve vertical overflow by shrinking the entire Domino table/hand into unreadable geometry.

Prefer:
- bounded internal vertical scroll;
- reachable action/leave controls;
- safe-bottom spacing;
- accepted table width;
- usable hand interaction.

---

## PHASE F — CLOSE DOMINO

Only after live manual acceptance:
- mark MVP-19.9 Domino CLOSED / frozen;
- update small canonical + short roadmap;
- move to MVP-19.10 Tic-Tac-Toe.

---

# REMAINING ORDER

1. **19.9 Domino — NEXT**
2. 19.10 Tic-Tac-Toe
3. 19.11 Four-in-a-row
4. 19.12 Battleship
5. 19.13 Seasonal collection + full cosmetics regression

Bundles / `Наборы` remain separate later work and are not a blocker for individual game closure.

---

# SAFETY / PROCESS

- staging only unless explicitly approved otherwise;
- no `main` / production / Cron;
- no broad frozen-engine refactors;
- branch from exact fresh staging;
- focused CI before merge;
- merge into `agent/mvp-13-2-staging`;
- Hostinger staging redeploy when required;
- fully reopen Telegram Mini App after cache bump;
- manual Telegram appearance review outranks green CI;
- if the user says an effect/preview is wrong, fix the exact presentation instead of redesigning accepted behavior.

---

# FRESH-CHAT START SENTENCE

**Continue MiniGamesWorld from the factual current `agent/mvp-13-2-staging` HEAD. MVP-19.8 Go is fully manually accepted, CLOSED and frozen. Begin MVP-19.9 Domino with a factual runtime/catalogue/owner audit, then build the complete Domino Store AND Domino Profile/Portfolio in the same work block before stopping. Store is the visual source of truth; proactively enforce exact Store/Profile preview width and aspect-ratio parity using a Domino-specific last owner / hard-dimension repair if generic Profile CSS crops or narrows it. Stop for one combined Store+Profile manual review, tune preview animations until accepted, and only then wire those accepted cosmetics into the real Domino renderer from authoritative `last_action`/`move_count` without creating a second physical tile movement owner.**
