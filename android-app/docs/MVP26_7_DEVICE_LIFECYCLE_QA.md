# MVP-26.7 — Android device / lifecycle QA

Status: candidate QA slice.

## Goal

Close the provider-independent Android device/lifecycle QA stage after the
manually accepted MVP-26.6 full product parity slice.

No game, economy, rating, tournament, Store, Profile or account owner is changed
by this slice unless a reproducible lifecycle defect proves that a product
corrective is required.

## Current accepted baseline

- package: `com.minigamesworld.app.acceptance`;
- accepted signing identity unchanged;
- minSdk 26 / targetSdk 36 / compileSdk 36;
- ordinary orientation changes preserve the Activity/WebView;
- IME uses `adjustResize`;
- API 30+ uses system-bar + display-cutout insets;
- API 26–29 keeps legacy system-window inset fallback;
- WebView state save/restore remains the process/activity recovery fallback;
- renderer-process loss is handled by destroying the dead WebView and exposing
  the native retry/error path;
- accepted MVP-26.6 product parity remains frozen.

## Versioning correction

The previous CI artifact was named v2614 while the repository still carried
versionCode 2613. MVP-26.7 corrects the release path with a genuinely monotonic
candidate:

- versionCode: **2615**
- versionName: `0.26.7.0-device-lifecycle-qa`

This is not a product behavior change.

## Automated device matrix

The lifecycle gate runs the same accepted APK on:

1. API 26 — compact phone — 360×640 — density 320;
2. API 30 — modern phone — 412×915 — density 420;
3. API 36 — wide/tablet-style surface — 1280×800 — density 240.

For every connected emulator the smoke proves:

- exact API level;
- APK installs and launches;
- target screen size / density is applied;
- no native AndroidRuntime crash;
- Home background → foreground keeps the running process;
- orientation change keeps the accepted Activity owner;
- RUNNING_CRITICAL trim-memory pressure does not crash the app;
- airplane-mode network loss/recovery is exercised where the shell API supports
  it, without process death;
- background process eviction / restart returns to a running app;
- explicit force-stop cold restart returns to a running app;
- final screenshot, UI dump, activity dump, meminfo and logcat are retained as
  evidence.

Static contracts additionally protect:

- configChanges ownership;
- WebView onPause/onResume;
- saveState/restoreState;
- render-process-gone recovery;
- safe-area ownership;
- API 33+ and legacy Back ownership;
- no privileged `addJavascriptInterface`;
- cleartext and backup remain disabled.

## Manual real-device matrix

Automated emulator coverage does not replace the final phone stress pass.

After all gates are green, install v2615 over the accepted app and verify:

1. cold-open the app three times — account/profile/balance are immediately sane;
2. open a real match, background the app for about 30–60 seconds, return — match
   reconnects and remains usable;
3. rotate portrait ↔ landscape during normal product use — no full startup replay;
4. open a lower text field in a sheet/form, show the keyboard, rotate once, and
   confirm the field/actions remain reachable;
5. turn network off for about 10–15 seconds while the app is open, turn it back
   on, and confirm the product recovers without reinstall/relogin;
6. press Home, open several other apps, then return from recents — same session;
7. force-close MGW and reopen — canonical account/profile/balance return;
8. lock/unlock the phone with MGW in the foreground/background — no broken shell;
9. verify Android system Back still closes sheets/routes and backgrounds from Home;
10. spot-check one game plus Store/Profile/Arena after the stress sequence.

## Closure

If automated matrix + real-device matrix PASS:

**MVP-26.7 CLOSED / MANUALLY ACCEPTED / FROZEN.**

Then proceed to **MVP-26.8 Android Product RC**.
