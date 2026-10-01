# MVP-26.3 — Native shell / settings closure

## Authority

This document closes the **master-roadmap MVP-26.3 Native shell / settings** milestone.

Historical PR/workflow names that used MVP-26.3 for the Android ↔ Telegram
account-link workstream are not renamed. That account-link work is already
accepted and belongs architecturally to master MVP-26.4 provider-neutral
account architecture.

## Master requirement

The authoritative master requires:
- navigation;
- Profile/settings shell where native ownership is needed;
- Android Back behavior;
- external links;
- keyboard/safe-area;
- orientation policy;
- accessibility basics.

## Audit result

### Navigation — accepted
The Android container keeps the canonical MGW Web product as the single UI
owner. Same-origin HTTPS stays inside the hardened WebView. Unsafe schemes are
blocked. Ordinary external http/https, mail, phone and Telegram targets leave
the container through Android intent handling.

### Profile/settings native ownership — no duplicate native screen
A second native Profile/settings product is not required and must not be
created.

Canonical Profile/settings remains the shared MGW Web product. Native ownership
exists only where the platform must own it:
- Android Keystore device credential;
- system device-lock confirmation for sensitive reauth;
- system DownloadManager for Account Data ZIP delivery;
- Android lifecycle/system-bar/back behavior.

This keeps one product/account/settings owner rather than duplicating Profile in
Java.

### Android Back — closure corrective
Before this slice, native Back only inspected WebView browser history. MGW is a
SPA, so an open sheet or Profile/Store/Arena route could have no browser-history
entry and Back could background/destroy the Activity unexpectedly.

Accepted ownership after this slice:
1. native sends one fixed, cancelable `mgw:android-back-request` event to the
   current same-origin WebView;
2. active MGW sheet closes first, including nested sheet history;
3. persistent shell routes Profile / Store / Arena return to Home;
4. active game and matchmaking/search are intentionally not silently abandoned;
5. if web does not consume Back, real browser history may go back;
6. at the root, Android backgrounds the task with `moveTaskToBack(true)` rather
   than destroying the accepted session/WebView.

No `addJavascriptInterface` or general-purpose privileged bridge is added.

### Keyboard / safe-area — closure corrective
- MainActivity explicitly uses `android:windowSoftInputMode="adjustResize"`.
- API 30+ safe-area padding uses system bars + display cutout insets.
- API 26–29 keep the legacy system-window inset fallback.
- IME space is owned by Android window resizing rather than being mixed into
  the persistent system-bar padding.

### Orientation — already accepted and preserved
Ordinary orientation/window changes remain owned by the same Activity/WebView:
- no reauth;
- no cold reload;
- no WebView replacement;
- saved-state recovery remains available for real Activity/process recreation.

The historical verifier is changed from “exactly versionCode 2604” to
“versionCode >= 2604” so the accepted orientation invariant survives later APK
versions.

### Accessibility basics — closure corrective
Native-only surfaces now explicitly define:
- decorative Shield King raster excluded from accessibility focus;
- duplicate spinner excluded from accessibility focus;
- loading text as a polite live region;
- blocking native error title as an assertive live region;
- blocking native errors announced after presentation;
- WebView marked as the accessible product surface;
- native Retry button keeps ordinary Android button semantics.

This is the native-shell baseline only. Full product/content accessibility QA is
still part of later Android product/device QA.

## Candidate

- versionCode: **2610**
- versionName: `0.26.3.20-native-shell-closure`
- applicationId unchanged: `com.minigamesworld.app.acceptance`
- accepted signing identity unchanged.

## Automated acceptance

Required:
- native shell/settings verifier;
- accepted foundation verifier;
- orientation lifecycle verifier;
- JS/PHP syntax;
- Android unit tests;
- Android lint;
- APK assemble;
- accepted signing identity;
- tracked-secret scan;
- scope/frozen-owner guard;
- exact staging deployment and two-context staging E2E after merge.

## Real-device acceptance

After automated gates are green:
1. install v2610 over accepted v2609 without uninstall;
2. open Profile and press Android system Back → Home must remain visible;
3. open `⋯ → Данные и аккаунт`, press Android Back → the sheet must close
   without leaving the app;
4. from Home press Android Back → app should background; reopen from recents and
   confirm the same session/product state remains;
5. open a real text input near the lower part of a sheet/form and show the
   keyboard → focused field/actions must remain reachable instead of being
   covered by the IME;
6. rotate portrait ↔ landscape once → no full startup/preloader replay.

Previously accepted external-link and orientation behavior need not be reworked
unless the above smoke exposes a regression.

## Closure / next

If the real-device matrix passes:
**master MVP-26.3 Native shell/settings = CLOSED / MANUALLY ACCEPTED / FROZEN.**

Next:
**MVP-26.5 — Disabled/no-op platform adapters**
(Billing, Ads, Push, Integrity, Analytics, DeepLink), all provider-independent
and disabled/no-op before MVP-29.
