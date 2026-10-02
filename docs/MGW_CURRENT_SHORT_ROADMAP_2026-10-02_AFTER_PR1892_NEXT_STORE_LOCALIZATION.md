# MiniGamesWorld — CURRENT SHORT ROADMAP

**Date:** 2026-10-02  
**Integration authority:** `agent/mvp-13-2-staging`

## Accepted baseline

- SHA: `e32941a9276778d7b1031014b632935a0399f42b`
- tree: `644c772c2caf1ef1f8470d12f02dc2ab6aea4ff5`
- backup: `backup/mvp27-1-arena-localization-closed-2026-10-02`
- localization debt: **4,344 total / 2,663 client / 1,653 backend / 28 client-entry**

Closed/frozen:
- localization architecture audit foundation;
- Home active owner localization;
- Arena/tournaments active owner localization;
- Arena direct `ru-RU` ownership removed;
- Home direct `ru-RU` ownership removed;
- production remains RU-only.

## Immediate cleanup

**Close PR #1888 as superseded. Do not merge it.**

Accepted Arena history is #1889 → #1890 → #1891 → #1892.

---

# NEXT — MVP-27.1 Store Localization

## First target candidate

`app/assets/js/screens/store-screen.js`

Latest audit count: **132 hardcoded Cyrillic lines**, currently the largest remaining client source finding.

## Step 0 — ownership proof first

Before editing:
1. read the current Store imports from the active shell;
2. inspect `app/runtime/client/version-manifest.php`;
3. confirm which Store source/wrapper is actually active;
4. separate active copy from legacy/dead source;
5. inspect predecessor Store tests/contracts for hardcoded RU assertions.

Known manifest mappings currently include:
- `./assets/js/screens/store-screen.js?v=34` → current checkers-board-source wrapper chain;
- `./assets/js/screens/store-screen.js?v=45&intent_base=1&mvp19_5=chess-catalog` → current Store target with accumulated MVP-19/MVP-25/MVP-26 identities.

Do **not** assume every line in raw `store-screen.js` is player-active until this ownership check is complete.

## Store migration sequence

After ownership is proven:

1. Measure Cyrillic count in the exact active Store owner/region.
2. Split into bounded presentation slices if needed:
   - Store shell/navigation/common labels;
   - catalog/bundle/card copy;
   - purchase/status/error copy;
   - game-specific preview labels.
3. Add canonical RU keys under an appropriate Store namespace.
4. Preserve:
   - product IDs;
   - bundle IDs;
   - ownership/equip semantics;
   - disabled monetization behavior;
   - prices/economy inputs;
   - preview/rendering behavior;
   - all game-specific Store visual contracts.
5. Replace module-load translated label maps with stable code → i18n-key mappings where live locale switching matters.
6. Use canonical number/date formatting; add no direct `ru-RU`.
7. Make predecessor Store contracts localization-successor-safe.
8. Add focused localization contract + bounded scope gate + secret scan.
9. Re-run the global hardcoded-text audit.
10. Lower baseline from **4,344** only by the exact measured delta.
11. Merge only after focused CI passes.

## After Store

Re-run and re-rank the audit. Current next candidates are approximately:
- `game-invites-v110.js` — 106;
- `profile-screen-v110.js` — 105;
- `invite-controller-v120.js` — 101;
- `game-invites.js` — 91;
- `residual-ui-game-race-fix.js` — 82;
- `friends-screen-v110.js` — 65;
- `account-data-sheet-v1.js` — 62.

Do not follow this list mechanically. For every candidate:
- verify active ownership first;
- prefer the current v110/current manifest owner;
- skip dead/legacy sources rather than localizing obsolete code.

## Frozen boundaries for MVP-27.1

- no production English enablement yet;
- no mechanics change;
- no economy change;
- no rating change;
- no tournament behavior change;
- no payment/monetization re-enable;
- no production/main/production DB/Cron changes;
- no broad refactors mixed into localization PRs.

## Definition of progress

Each accepted slice must:
- preserve visible RU behavior;
- move player-facing text behind canonical i18n;
- preserve stable codes and state semantics;
- lower or hold the hardcoded-text ratchet, never increase it;
- keep predecessor behavior contracts green;
- publish a fresh active cache identity when runtime source changes.

The next chat should begin with **Store ownership audit**, not with blind replacements.
