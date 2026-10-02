# MiniGamesWorld — SMALL CANONICAL CHECKPOINT

**Date:** 2026-10-02  
**Repository:** `manufact-test/mini-games-world`  
**Authoritative integration branch:** `agent/mvp-13-2-staging`

## Exact accepted checkpoint

- staging SHA: `e32941a9276778d7b1031014b632935a0399f42b`
- staging tree: `644c772c2caf1ef1f8470d12f02dc2ab6aea4ff5`
- backup: `backup/mvp27-1-arena-localization-closed-2026-10-02`
- current workstream: **MVP-27.1 Localization architecture / hardcoded player text**
- production locale state: **RU-only remains truthful**
- production / `main` / production DB / production Cron: **UNTOUCHED**

## What is accepted now

The localization foundation is already established and the migration is being done owner-by-owner behind the existing i18n architecture.

Accepted/frozen in this chat:
- Home player-facing owner localization is closed;
- Arena / tournaments active owner localization is closed;
- active Home has **0 hardcoded Cyrillic source lines** and no direct `ru-RU` formatting owner;
- active `app/assets/js/screens/tournaments-screen-v1.js` has **0 hardcoded Cyrillic source lines** and **0 direct `ru-RU` locale ownership**;
- tournament mechanics, economy, rating, progression, rewards and archive semantics remained frozen while display copy moved to canonical localization ownership.

## Current localization debt ratchet

Authoritative baseline:
- total: **4,344**
- client: **2,663**
- backend: **1,653**
- client-entry: **28**
- scanned files: **776**

Baseline file:
`ops/checks/mvp27_1_hardcoded_text_baseline.json`

Current note:
> MVP-27.1 final Arena archive/Hall-of-Fame/leaderboard migration; active tournaments-screen-v1.js reduced from 10 to 0 Cyrillic source lines.

## Important merged PR chain from this chat

Foundation / Home:
- #1881 — localization architecture audit + hardcoded-text ratchet foundation.
- #1882 — Home shell/menu/history localization.
- #1883 — Home rules-guide localization.
- #1884 — Home player-report localization.
- #1885 — Home Support + timestamps; **Home closed at 0 Cyrillic**.

Arena:
- #1886 — registration + synchronization copy.
- #1887 — Tournament Hall + first-match readiness.
- #1889 — bracket + technical outcome presentation.
- #1890 — terminal rewards + progression messages.
- #1891 — tournament card, cancellation, rules/consent, schedule/capacity/prizes and canonical datetime formatting.
- #1892 — archive / Hall of Fame / leaderboard tail; **Arena closed at 0 Cyrillic**.

## Superseded PR warning

**PR #1888 is still open but is obsolete/superseded. DO NOT MERGE IT.**

Its Arena bracket/progression work was replaced by the accepted sequence #1889 → #1890 → #1891 → #1892.

Safe cleanup for the next chat:
- close #1888 as superseded;
- do not cherry-pick or merge its commits.

## Current top hardcoded-text audit after Arena closure

Latest focused audit top files:

1. `app/assets/js/screens/store-screen.js` — **132**
2. `app/assets/js/games/game-invites-v110.js` — **106**
3. `app/assets/js/screens/profile-screen-v110.js` — **105**
4. `app/assets/js/games/invite-controller-v120.js` — **101**
5. `app/assets/js/games/game-invites.js` — **91**
6. `app/assets/js/residual-ui-game-race-fix.js` — **82**
7. `bot/tournaments/TournamentRegistrationService.php` — **68**
8. `app/assets/js/screens/friends-screen-v110.js` — **65**
9. `app/assets/js/screens/account-data-sheet-v1.js` — **62**
10. `bot/accounts/AccountLinkService.php` — **55**

The audit is source-based, not ownership-aware. A high count does **not** automatically mean the file is active.

## Exact next candidate

The leading next candidate is:

`app/assets/js/screens/store-screen.js` — **132 Cyrillic lines**

It is present in the active version manifest through Store mappings, but before changing it the next chat must verify:
- which Store import specifier is actually active;
- whether the active owner is the raw `store-screen.js`, the checkers wrapper, or both;
- whether any of the 132 lines belong to inactive/legacy paths;
- which predecessor Store contracts hardcode RU text and need localization-successor-safe assertions.

Do not migrate based only on the audit ranking.

## Migration rules that must remain frozen

1. Production stays RU-only until a complete production EN locale is intentionally introduced.
2. Never add a fake/incomplete production `en.json`.
3. Move display copy only; preserve stable codes/IDs/state-machine values.
4. Dynamic labels must resolve through `t()` at render time when live language switching could otherwise leave stale module-load translations.
5. Use canonical locale-aware number/date/time formatting; do not introduce direct `ru-RU` ownership.
6. Every slice lowers the ratchet by the **measured** delta, not an estimate.
7. Make historical UX contracts localization-successor-safe instead of deleting behavioral assertions.
8. Verify active import/manifest ownership before touching any high-debt file.
9. No mechanics/economy/rating/tournament behavior change inside localization PRs.
10. Keep focused CI + bounded path guard + secret scan.

## Recommended next-chat inputs

Attach/use:
- the authoritative master canonical already used for the project;
- this SMALL CANONICAL;
- the CURRENT SHORT ROADMAP saved alongside it.

Start from staging `e32941a9276778d7b1031014b632935a0399f42b`, not from any old Arena feature branch.
