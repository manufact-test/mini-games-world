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

## Status

**IMPLEMENTATION IN PROGRESS / MANUAL ACCEPTANCE REQUIRED**

After focused contracts and exact staging E2E are green, product-owner manual UX acceptance is required.
