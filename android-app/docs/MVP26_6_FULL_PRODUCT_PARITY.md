# MVP-26.6 — Android full gameplay / product parity

Status: candidate implementation.

## Authoritative target

The Android application must expose the already-accepted Russian MGW product
without making Google/commercial providers a prerequisite.

Shared product ownership remains in the canonical web runtime. Android adds only
the native capabilities that a standalone WebView container otherwise lacks.

Parity surface:

- all 8 games;
- timers;
- sound/media behavior;
- vibration;
- reconnect;
- matchmaking;
- friends;
- invites;
- Store;
- Profile;
- Arena;
- tournaments;
- in-app notifications;
- Support/account flows.

## Frozen game/product ownership

This slice does not fork or rewrite any game engine, timer, rating, tournament,
economy, Store, Profile or matchmaking owner.

The same shared runtime remains authoritative on Telegram and Android. The
active version manifest and accepted shared Telegram/client JS owners are kept
byte-for-byte frozen; Android-only capability gaps are filled in the native
container after page initialization.

The eight accepted game renderers remain present for:

1. Tic-Tac-Toe
2. Checkers
3. Chess
4. Reversi
5. Go
6. Four in a Row
7. Domino
8. Battleship

## Android parity gaps closed here

### 1. Vibration

Telegram uses Telegram WebApp HapticFeedback.

Standalone Android has no Telegram bridge. The native container therefore
installs one small, idempotent post-init hook for trusted user click events and
maps them to a short standards-based `navigator.vibrate()` pulse.

The accepted Telegram/web JavaScript and Telegram HapticFeedback ownership remain
unchanged; Android does not pretend to be a Telegram WebApp.

The Android manifest declares `android.permission.VIBRATE`.

No privileged Java object or `addJavascriptInterface` bridge is introduced.

### 2. Invite links

Public canonical links remain:

`https://<MGW host>/invite/<24-hex-token>`

Android registers that exact HTTPS path as a verified App Link.

`.well-known/assetlinks.json` binds the accepted Android package/signing
certificate to the host.

Cold and warm invite opens preserve the token through the existing
server-owned Android authentication POST. The authenticated launch still uses
`WebAppLaunchUrl::invitation()`, so the canonical invite client owns binding
and UI after auth.

Unsafe origins, malformed tokens, query injection and non-HTTPS links remain
rejected.

### 3. Invite sharing from standalone Android

The Android container has no Telegram WebApp share API.

The native post-init compatibility hook intercepts only `https://t.me/...`
popup attempts and converts them to top-level navigation. The existing native
navigation policy then hands the HTTPS URL to Telegram/the browser.

The accepted shared invite client and copy-link fallback remain unchanged.

### 4. Support file upload

Support already accepts images, PDF and TXT in the shared product.

Android now owns a native `ACTION_OPEN_DOCUMENT` chooser for the existing
`<input type=file>` controls, including multiple selection.

Allowed MIME types remain identical to the Support service contract.

### 5. Support attachment download

Images keep the existing inline preview.

The native post-init hook leaves image attachments on the accepted inline
preview path. Non-image Support attachment clicks are routed to an authenticated
same-origin download endpoint and Android DownloadManager. Cookies and user
agent are forwarded only to the same MGW origin.

API 26-28 retains the existing runtime storage-permission boundary.

## Sound/media

No native audio owner is added. Android continues to render the canonical shared
runtime and does not mute or replace web media. Existing WebView policy keeps
media playback user-gesture-gated.

## Provider boundary

The MVP-26.5 adapter registry remains actively installed and disabled.

Billing, Ads, Push, Integrity, Analytics and provider DeepLink adapters remain
no-op/disabled, and none owns gameplay/product state.

## Candidate

- versionCode: `2612`
- versionName: `0.26.6.1-android-product-parity`
- applicationId: unchanged
- signing identity: unchanged

## Automated acceptance

Required:

1. product-parity static verifier passes;
2. all 8 canonical game renderers remain present and active;
3. no game/economy/rating/tournament engine changes in this slice;
4. Android unit/lint/build passes;
5. provider adapter regression passes;
6. Android account/auth/reauth regressions pass;
7. invite landing/auth regressions pass;
8. Support service regression passes;
9. PHP/JS syntax passes;
10. package/signing identity remains accepted;
11. post-merge staging E2E remains green.

## Manual acceptance

Because this slice adds visible/native Android behavior, real-device acceptance
is required before MVP-26.6 is frozen.

Minimum phone matrix:

- open all 8 games and start at least one normal match;
- verify game timer advances normally;
- verify haptic feedback on shared actions;
- background/foreground an active match and confirm reconnect;
- start/cancel matchmaking;
- open Friends and create/cancel an invite;
- open a public invite link from outside the app and confirm Android opens the
  authenticated invite flow;
- share an invite from standalone Android;
- open Store, Profile, Arena and tournaments;
- open in-app notifications;
- create a Support ticket with an image/PDF/TXT attachment;
- open an image attachment and download a PDF/TXT attachment;
- open account/data controls.

Detailed device/lifecycle stress testing remains MVP-26.7.
