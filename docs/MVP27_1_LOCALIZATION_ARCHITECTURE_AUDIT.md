# MVP-27.1 — LOCALIZATION ARCHITECTURE AUDIT

Status: **IN PROGRESS — audit slice A**.

Authoritative goal:
- remove hardcoded user-visible strings;
- preserve one locale owner;
- establish RU/EN fallback behavior;
- verify plurals, dates, numbers and time/timezone handling;
- preserve consistent coin/tournament terminology.

## Current architecture found

The project already has a real localization foundation:
- client owner: `app/assets/js/localization/i18n.js`;
- server owner: `app/runtime/localization/LocalizationCatalog.php`;
- production manifest: `app/locales/manifest.json`;
- production RU catalog: `app/locales/ru.json`;
- canonical v110 entry inlines the localization payload;
- explicit/account/platform/fallback locale precedence already exists;
- client uses `Intl.PluralRules`, `Intl.NumberFormat`, `Intl.DateTimeFormat`;
- rules metadata already has per-game language declarations for all eight games.

## Truthful production state

Production remains RU-only during MVP-27.1:
- default locale: `ru`;
- fallback locale: `ru`;
- supported locales: `["ru"]`;
- there is intentionally no production `en.json` yet.

MVP-27.1 must not claim a complete English product before MVP-27.2 supplies the actual player-facing English catalog and migrated surfaces.

## Architecture proof added by this slice

The audit contract uses a **synthetic EN fixture only in tests** to prove that:
- regional English locale values normalize to `en`;
- client translation lookup works;
- English one/other plural behavior works;
- number/date formatting can be configured for EN;
- rules metadata can resolve an EN game title;
- locale precedence remains explicit > account > platform > fallback;
- server `LocalizationCatalog` can load RU+EN catalogs and resolve English strings without a production EN catalog.

## Hardcoded-text audit

A repository audit scans runtime client/backend sources for remaining Cyrillic lines outside the canonical localization sources.

This first slice records the debt; it does **not** fail merely because hardcoded text exists. Subsequent MVP-27.1 migration slices will move player-facing strings into canonical keys and ratchet the baseline downward until the player runtime has no unowned user-visible text.

Exact player-facing baseline recorded from the PR candidate after excluding admin/control-plane sources:

- scanned runtime source files: **776**;
- Cyrillic lines total: **4,694**;
- client JS: **3,013**;
- backend PHP: **1,653**;
- client entry PHP: **28**.

Largest current source owners:

1. `app/assets/js/screens/tournaments-screen-v1.js` — 192 lines;
2. `app/assets/js/screens/home-screen.js` — 158;
3. `app/assets/js/screens/store-screen.js` — 132;
4. `app/assets/js/games/game-invites-v110.js` — 106;
5. `app/assets/js/screens/profile-screen-v110.js` — 105;
6. `app/assets/js/screens/friends-screen-v110.js` — 65;
7. `app/assets/js/screens/account-data-sheet-v1.js` — 62;
8. `app/assets/js/screens/game-screen-v102.js` — 54;
9. `bot/tournaments/TournamentRegistrationService.php` — 68;
10. `bot/accounts/AccountLinkService.php` — 55;
11. `bot/moderation/ModerationService.php` — 51;
12. `bot/support/SupportTicketService.php` — 44.

The broad source baseline can still contain superseded/legacy runtime owners. Migration work must prioritize the current v110 import graph and current backend owners first; legacy files are not allowed to masquerade as active product work.

The baseline is stored in `ops/checks/mvp27_1_hardcoded_text_baseline.json`. The audit now fails if total/client/backend/client-entry Cyrillic debt increases above this recorded baseline. Later migration slices are expected to ratchet these numbers downward.

## Frozen boundaries

This slice changes no player-facing copy, no game mechanics, no economy, no rating/tournament behavior, no Android product behavior and does not enable English in production.


## Client-entry active-owner classification — 2026-10-03

Factual launch proof after merged PR #1918:
- Telegram launch owner points to `/app/v110.php`;
- rejected `app/v120.php` is a permanent redirect tombstone back to v110;
- `app/runtime/server/` is a separate clean-runtime server that requires explicit staging configuration and an explicit host allowlist;
- it is not part of the factual Telegram v110 player graph.

The 25 Cyrillic source lines under `app/runtime/server/` are therefore classified as staging-only/internal runtime evidence, not active player localization debt.

Current player-facing debt after this classification:
- scanned runtime source files: **761**;
- total: **3,049**;
- client JS: **1,396**;
- backend PHP: **1,653**;
- active client-entry PHP: **0**.

This is an audit-scope correction only. No runtime, game, economy, tournament, DB, Cron or production behavior changes.


## Shadowed invite-owner classification — 2026-10-03

The factual v110 bootstrap/import graph uses `game-invites-v110.js`. It does not import:
- `app/assets/js/games/invite-controller-v120.js` — rejected with the failed v120 runtime and forbidden by the rollback route contract;
- `app/assets/js/games/game-invites.js` — superseded by the v110 invite owner.

These two files contain **192** Cyrillic source lines in total (**101 + 91**) and are classified out of active player localization debt.

Current player-facing debt after this classification:
- scanned runtime source files: **759**;
- total: **2,857**;
- client JS: **1,204**;
- backend PHP: **1,653**;
- active client-entry PHP: **0**.

This remains an audit-scope correction only; no product/runtime behavior changes.
