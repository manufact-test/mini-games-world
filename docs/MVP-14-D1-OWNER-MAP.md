# MVP-14 D1 — AUTHORITATIVE OWNER MAP

## Checkpoint

```text
BRANCH: agent/mvp14-d1-owner-audit-rebuild
BASE STAGING: 7264519c1dcd61b0479ee052d4855323a4deef47
PRODUCTION/main: untouched
AUDIT TYPE: read-only architecture audit
IMPLEMENTATION STATUS: not started at this checkpoint
```

# 1. NOTIFICATIONS — CURRENT OWNERS

## 1.1 Canonical import slot

`app/assets/js/main.js` imports and initializes:

```text
./screens/notifications-screen-v99.js?v=99
initNotificationsScreen()
```

However `app/v114.php` remaps that canonical slot to `notifications-passive-v130.js`, then independently injects three more notification assets before application boot.

## 1.2 Active owners and conflicts

### A. `screens/notifications-passive-v130.js`

Current responsibilities:

- notification polling;
- unread badge;
- announced-ID storage;
- pending notification selection;
- toast creation, swipe and dismissal;
- `mgw:notification-sync` and `mgw:notifications-refresh` listeners.

It deliberately does not own the notification sheet.

Conflict:

- notification data/toast state is separated from the sheet owner;
- uses global `window.__MGW_INVITE_LINK_OPENING__` supplied by another module;
- receives some events only after another owner has captured/intercepted them.

### B. `screens/notification-window-owner-v121.js`

Current responsibilities:

- global capture `pointerdown`, `pointerup`, `pointercancel`, `click`, `keydown`;
- bell and toast activation;
- compatibility-click suppression;
- notification sheet loading, cache and rendering;
- notification sync refresh while sheet is open;
- its own request generation and local item cache;
- overlay-close detection through `MutationObserver`.

Conflict:

- competes with the toast module for the same `#notificationToast`;
- opens on `pointerup`, then separately suppresses generated `click`;
- stops event propagation globally;
- duplicates notification state and rendering responsibility;
- relies on DOM observation instead of one explicit sheet lifecycle.

### C. `notification-compat-click-guard-v127.js`

Current responsibilities:

- another global capture listener set for `pointerdown`, `pointerup`, `pointercancel`, `click`;
- remembers coordinates and timing;
- programmatically calls `trigger.click()` if the pointerup path did not open the sheet;
- suppresses a later click if it is retargeted to the overlay.

Conflict:

- duplicates physical-input interpretation already present in v121;
- can generate a synthetic click while another click is pending;
- must predict browser/Telegram retargeting after the UI changes under the pointer;
- directly creates the intermittent behavior it was intended to hide.

### D. `notification-deeplink-toast-policy-v131.js`

Current responsibilities:

- detects incoming invite token independently;
- sets a global suppression flag;
- injects CSS hiding `#notificationToast`;
- polls every 50 ms for the invite sheet;
- removes toast classes directly;
- releases after sheet detection or 15 seconds.

Conflict:

- edits another owner’s DOM and state from outside;
- uses polling and CSS to compensate for an unmodelled transition;
- depends on the sheet markup and global flag;
- duplicate-toast prevention is not part of the notification state machine.

### E. `games/invite-link-entry-v115.js`

Legitimate responsibility:

- opens the invitation link;
- publishes `mgw:notification-count`;
- publishes the matching notification with `announce:false`;
- renders the incoming invitation decision sheet.

This is not a notification UI owner. It provides an explicit silent notification event and should remain the producer of that intent.

## 1.3 Root cause

The bell and toast do not have one input/state/render owner. Two global pointer/click interpreters, one separate sheet renderer, one separate toast renderer and one deep-link DOM policy all operate on the same elements and overlay lifecycle.

The real Telegram event sequence is therefore order-dependent. Controlled Chromium can pass one ordering while Telegram Desktop/mobile produces another.

# 2. NOTIFICATIONS — TARGET OWNER

The canonical slot `screens/notifications-screen-v99.js` becomes the only active notification owner.

It owns:

- one delegated `click` activation path for `#notificationsOpen` and visible `#notificationToast`;
- keyboard activation;
- one notification state object;
- one request generation/abort policy;
- one sheet shell and body renderer;
- unread badge;
- toast queue and swipe dismissal;
- polling/sync;
- silent deep-link consumption through `announce:false` and local incoming-link state.

State machine:

```text
closed
→ opening
→ loading
→ ready(items) | ready-empty | error
→ closing
→ closed
```

Deep-link transition:

```text
incoming invite token exists
→ canonical notification owner starts in silent-link mode
→ invite-link entry dispatches matching item with announce:false
→ item is marked handled, pending toast is cleared
→ decision sheet opens
→ silent-link mode ends through explicit event, not DOM polling
```

## 2.1 Notification retirement list

Remove from active graph and delete after responsibilities are transferred:

```text
app/assets/js/screens/notifications-passive-v130.js
app/assets/js/screens/notification-window-owner-v121.js
app/assets/js/notification-compat-click-guard-v127.js
app/assets/js/notification-deeplink-toast-policy-v131.js
```

Remove tests that assert those files must exist. Replace them with canonical-owner and no-double-owner contracts.

# 3. PLAYER PICKER — CURRENT OWNERS

## 3.1 Legitimate UI owner

`games/game-invites.js` is the legitimate owner of:

- invite setup;
- `openPlayerPicker(context)`;
- initial loading sheet;
- opponent request;
- list/empty/error rendering;
- direct invite action.

This owner currently performs one ordinary `postJson(OPPONENTS_URL, {})` call and then calls `openSheet()` again with the final result.

## 3.2 Global transport/cache owners

The single request is currently transformed by four global layers.

### A. `first-interaction-readiness-v103.js`

- replaces `window.fetch`;
- stores a warmed opponents response;
- returns the cached response immediately for any later opponents request;
- refreshes the network asynchronously in the background;
- treats click on invite/player-picker as a prefetch trigger.

This is the first stale non-empty source.

### B. `opponents-empty-cache-guard-v115.js`

- replaces `window.fetch` again;
- only bypasses cache if returned items are empty;
- any non-empty stale list is accepted.

### C. `opponents-authoritative-confirm-v122.js`

- replaces `window.fetch` again;
- retries empty responses;
- immediately accepts any non-empty response, regardless of age/source.

### D. `opponents-fresh-user-action-v128.js`

- replaces `window.fetch` again;
- retries only while the response is empty;
- immediately accepts any non-empty stale boot response.

### E. `opponents-native-fetch-v115.js`

- stores the original browser fetch in a global escape hatch used by the wrappers.

## 3.3 Root cause of missing real phone account

A desktop boot can warm a list containing only the two staging test identities. Later, the real phone account becomes present. When the user opens the picker, `first-interaction-readiness-v103.js` returns the old non-empty list immediately.

All three subsequent guards treat “non-empty” as sufficient and stop. Therefore no fresh user-action response reaches the picker. The test passes because it checks its own controlled identity and/or arranges an empty response, while the actual stale non-empty path is not rejected.

## 3.4 Root cause of visual flash

`game-invites.js` calls `openSheet()` for loading and calls `openSheet()` again for loaded/empty/error. `openSheet()` replaces the entire `sheet.innerHTML` each time. Combined with previous sheet content and asynchronous cached/network results, this produces visible intermediate frames.

Previous E2E frame collection began only after `sheetOverlay.active`; it could not prove absence of the frame before/while the overlay became active.

# 4. PLAYER PICKER — TARGET OWNER

`games/game-invites.js` remains the sole player-picker owner. No new global transport module is introduced.

State machine:

```text
idle
→ loading
→ loaded(items)
→ confirmed-empty
→ error
```

Rules:

- manual open starts one direct authoritative no-store request;
- warmed opponent data is not returned as the response to a manual action;
- prefetch may exist only as a background hint and may never replace the manual request;
- no `window.fetch` replacement for opponents;
- one request generation/AbortController prevents stale completion;
- the sheet is opened once with a stable shell;
- only a dedicated body region is replaced on state transition;
- empty is rendered only after successful authoritative response with `items=[]`;
- no retry loop is used to hide stale architecture;
- server `invite-opponents.php` remains the authoritative current-state reader;
- presence must be current before the request, but UI transport must not cache a non-empty boot snapshot.

## 4.1 Player-picker retirement list

Remove from active graph and delete:

```text
app/assets/js/opponents-native-fetch-v115.js
app/assets/js/opponents-empty-cache-guard-v115.js
app/assets/js/opponents-authoritative-confirm-v122.js
app/assets/js/opponents-fresh-user-action-v128.js
```

Remove opponent interception from:

```text
app/assets/js/first-interaction-readiness-v103.js
```

The readiness module may warm unrelated profile/history/orders/notification data, but it must stop replacing `window.fetch` and stop owning opponent responses.

# 5. SERVER AUTHORITY

`bot/invite-opponents.php` reads:

- current authenticated account;
- live account IDs from `PresenceService`;
- current staging DB-primary users and finished human games;
- activity/online/busy status;
- a maximum of ten sorted candidates.

It returns:

```text
authoritative=true
storage_driver=database
```

No client cache or wrapper is allowed to relabel an older snapshot as the result of a later manual action.

# 6. TEST SCOPE

## 6.1 Automation can prove

- old hotfix assets are absent from active graph;
- there is one notification owner;
- there is no notification global pointerup/click compatibility owner;
- there is no opponent `window.fetch` replacement;
- manual picker sends one no-store request;
- loading/loaded/empty/error transitions inside an already-open Chromium sheet;
- empty does not render before authoritative success;
- normal notification may display toast;
- `announce:false` deep-link notification never displays duplicate toast;
- test identities can be read from current staging DB response.

## 6.2 Automation cannot prove

- Telegram Desktop’s real generated click sequence;
- Telegram mobile WebView touch behavior;
- real-account presence lifecycle across the user’s two devices;
- complete absence of a 0–500 ms flash on the user’s hardware;
- long-open Telegram client cache and resume behavior.

## 6.3 Development test policy

```text
FOCUSED STATIC/UNIT TESTS DURING IMPLEMENTATION: YES
FULL REPOSITORY CI DURING EACH EDIT: NO
FULL REPOSITORY CI AT INTEGRATION GATE: YES, ONCE
FULL STAGING E2E: ONLY RELEVANT SCENARIOS, ONCE
REAL-DEVICE ACCEPTANCE: MANDATORY
```

# 7. MANUAL ACCEPTANCE GATE

Notifications:

1. Telegram Desktop: ten normal short clicks on bell; ten openings on first click.
2. Telegram mobile: ten normal taps; ten openings on first tap.
3. Close/reopen several times; no automatic close/reopen.
4. Open invitation link; decision sheet only, no duplicate blue toast.
5. Receive ordinary invitation while app is open; blue toast appears and opens notifications.

Player picker:

1. Open computer account first.
2. Open different real account on phone afterward.
3. Open player picker on computer; phone account must appear immediately after one loading state.
4. Reverse devices; computer account must appear on phone.
5. No old/empty content flashes under the loading shell on either device.
6. Confirm direct invite still sends and arrives.

# 8. IMPLEMENTATION ORDER

```text
1. Consolidate notifications into canonical notifications-screen-v99.js.
2. Add explicit invite-link lifecycle events; remove DOM policy/polling.
3. Remove notification hotfix assets from v114.php and repository.
4. Remove opponent interception from first-interaction readiness.
5. Rebuild player picker state/render/request inside game-invites.js.
6. Remove all opponent fetch wrapper assets from v114.php and repository.
7. Replace old hotfix-presence tests with architecture contracts.
8. Run focused checks.
9. Run full CI once.
10. Merge/deploy staging only.
11. Stop for real-device acceptance.
```
