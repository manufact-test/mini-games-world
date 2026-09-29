# MVP-25.3 — Manual acceptance checklist

Date: 2026-09-29

Status before human review:

**TECHNICALLY COMPLETE / MANUAL ACCEPTANCE PENDING**

Technical staging proof:
- implementation SHA: `c28d83f27b71d69a0ef0a5285ce92b3b3b50c2ff`;
- exact Staging Playwright E2E: `36603236354` — SUCCESS.

This check is visual/interaction only. It does not reopen accepted game mechanics, economy, tournament settlement or cosmetic behavior.

## Manual checks

1. **Home → More on a short/mobile viewport**
   - open “Ещё” / More;
   - all menu rows must remain reachable;
   - if the list is taller than the available sheet, only the menu list scrolls;
   - the sheet header/close control stays stable.

2. **Shared sheet geometry**
   - open Settings, Rules, History, Support and one game setup/result sheet;
   - close button and primary actions must be easy to tap;
   - no clipped bottom action near the phone home indicator;
   - no unexpected horizontal scrolling.

3. **Topbar and bottom navigation**
   - Home / Arena / Store / Profile switching remains unchanged;
   - topbar notification/profile/balance controls remain aligned;
   - no nav jump, overlap or clipped icon after the 44px tap-target normalization.

4. **Notifications**
   - open Notification Center;
   - “mark all” and card actions remain visually aligned and easy to tap;
   - loading / empty / error states still fit without clipping;
   - notification toast does not collide with the top safe area.

5. **Friends**
   - open Friends;
   - tabs, search, player-card actions and “more” control remain aligned;
   - loading state no longer collapses into a small text line;
   - long public profile/report content still scrolls normally.

6. **Profile → Data and account / Moderation**
   - disabled buttons must visibly look disabled;
   - enabled buttons must preserve the primary/danger hierarchy;
   - a long moderation list must scroll inside the sheet without moving the header off-frame;
   - Account Data confirmation remains fully reachable.

7. **Desktop keyboard spot-check**
   - with Telegram Desktop, Tab through a sheet/menu;
   - focused controls must have a visible violet focus indicator;
   - mouse/touch appearance should remain unchanged.

8. **Regression sweep**
   - briefly open Arena, Store, Profile and one accepted game;
   - no game-board/cosmetic layout change;
   - no raw API/HTTP/DB/runtime/owner text appears.

## Acceptance result

**PASS — CLOSED / MANUALLY ACCEPTED / FROZEN**

Product-owner review:
- the MVP-25.3 manual sweep was reported as good;
- the expanded More → Rules guide was reviewed on the large-screen presentation;
- one final screenshot-driven correction was requested: reduce the empty space above the first rules section;
- PR #1808 reduced only that top inset to 8px and preserved the remaining section/bottom rhythm;
- the product owner explicitly authorized MVP-25.3 closure after that exact correction.

Final accepted implementation SHA:
- `b2c60acce74375e21eef3b0824a66b69c34502e1`

Final exact staging proof:
- focused MVP-25.3 gate — SUCCESS;
- Staging Playwright E2E `36608738815` — SUCCESS;
- `staging-playwright-e2e` — SUCCESS.

**NEXT: MVP-25.4 — Performance / reliability.**
