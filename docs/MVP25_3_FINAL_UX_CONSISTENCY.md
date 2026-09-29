# MVP-25.3 — Final UX consistency

Date: 2026-09-29

Base staging before work:
- SHA: `747389d820b12010727f3b47bd722079dbfed625`
- MVP-25.2: **CLOSED / MANUALLY ACCEPTED / FROZEN**

## Active runtime audited

Launch graph was verified before changing UX:

`bot/helpers/WebAppLaunchUrl.php → /app/v110.php → app/runtime/client/version-manifest.php → active v110 shell/screens/styles`

The audit intentionally follows the active runtime graph rather than newest filenames.

## Scope

MVP-25.3 is a product-wide visual/interaction consistency pass. It must not reopen accepted game mechanics, economy or cosmetic behavior without a reproduced defect.

Audit axes:
- spacing and typography;
- primary/secondary navigation;
- sheets/modals;
- button hierarchy and touch geometry;
- disabled/loading/empty/error states;
- scroll ownership;
- viewport/safe-area handling;
- visual hierarchy;
- no player-visible implementation language regression from MVP-25.2.

## Source audit findings

### 1. Short-viewport More menu can clip — corrective required

The shared sheet uses a fixed maximum height and stable `overflow:hidden` ownership. The Home “Меню” sheet contains eight 52px menu rows plus gaps/header. Its direct `.menu-list` had no independent scroll owner.

On short Telegram/WebView heights this can hide lower menu actions.

Correction:
- the direct sheet menu list becomes the scroll owner;
- sheet header/frame remain stable;
- no menu action or route changes.

### 2. Shared touch geometry is inconsistent — corrective required

The canonical `.btn` floor is 48px, but ordinary shell/secondary controls still contain 34–43px interactive targets:
- topbar icon buttons;
- sheet close buttons;
- Notifications actions;
- Friends tabs/actions/more menu;
- Account Data actions;
- report reason buttons;
- low-height sheet buttons.

Correction:
- normalize these cross-product controls to a 44px minimum tap floor;
- do **not** resize accepted game boards or cosmetic-card internals in this pass.

### 3. Safe-area ownership is incomplete — corrective required

The shell uses safe-area insets, but the generic overlay and generic toast do not consistently reserve them.

Correction:
- overlay top/bottom padding respects safe-area insets;
- generic toast respects the top inset.

### 4. Keyboard focus is hidden in shared sheet menus — corrective required

A legacy rule removes outline/box-shadow from `.sheet .menu-item:focus-visible`.

Correction:
- restore a clear focus-visible state for shared sheet controls, shell controls and Friends controls;
- pointer/normal visual state remains unchanged.

### 5. Disabled-state hierarchy is inconsistent — corrective required

Account Data and player-report actions explicitly preserve active-looking white CTA styling while disabled, conflicting with the canonical disabled button system.

Correction:
- use the existing disabled foreground/background/border tokens;
- behavior and API state remain unchanged.

### 6. Growing moderation history has no explicit scroll owner — corrective required

The moderation sheet can grow with account history while the shared sheet itself owns `overflow:hidden`.

Correction:
- moderation content receives bounded internal scrolling;
- the sheet header remains stable.

### 7. Friends loading state collapses compared with empty state — polish required

The loading state is currently plain text while empty states reserve a card-like region.

Correction:
- give loading the same stable visual rhythm as the empty surface to reduce layout jump.

## Explicit non-goals

This slice does **not**:
- change game rules or game-board geometry;
- change economy/tournament settlement;
- redesign accepted Store cosmetic cards;
- alter player copy already accepted in MVP-25.2 except if a new reproduced leakage is found;
- touch main/production/production DB/Cron.

## Technical readiness

Corrective PR #1805 was merged to staging as `c28d83f27b71d69a0ef0a5285ce92b3b3b50c2ff`.

Exact post-merge evidence:
- focused **MVP-25.3 Final UX consistency** gate: SUCCESS;
- Staging Playwright E2E run `36603236354`: SUCCESS;
- Hostinger exact-deployment wait: GREEN;
- managed staging migrations / projection diagnostics: GREEN;
- staging A/B preflight: GREEN;
- full two-context Playwright run: GREEN on Linux;
- final `staging-playwright-e2e` commit status: SUCCESS.

Relevant predecessor failures observed on the PR were inspected rather than treated as product regressions:
- Notification Center functional/static/visual contracts passed; its old cache-bust identity check expected a superseded manifest identity;
- Friends server/social proofs passed; old frozen owner/cache assertions expected superseded invite identities;
- Account Data SQLite lifecycle (50 assertions) and MySQL 8.4 lifecycle passed; the failing old first-open guard expected a bounded historical cache token;
- Profile corrective/static proofs passed; its old JSON boundary test expected the technical phrase `Не удалось сформировать ответ API.`, which MVP-25.2 intentionally removed from public copy.

No current product behavior was regressed to satisfy those stale predecessor identities.

## Status

**TECHNICALLY COMPLETE / MANUAL ACCEPTANCE PENDING**

Formal closure now requires product-owner review using `docs/MVP25_3_MANUAL_ACCEPTANCE.md`.

Do not advance to MVP-25.4 until that review passes.
