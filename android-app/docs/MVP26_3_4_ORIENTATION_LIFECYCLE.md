# MVP-26.3.4 — Android orientation/lifecycle corrective

Base staging: `b3b4fcf2fd987dbd38edc7cc14b4dac3109a55bb`.

## Real-device defect

Product-owner smoke found that rotating the phone portrait ↔ landscape replayed the full Android/WebView startup path.

Root cause was native, not web:
- `MainActivity` did not declare ownership of orientation/screen-size configuration changes;
- Android therefore destroyed the Activity on rotation;
- `onDestroy()` correctly destroyed the WebView;
- the replacement Activity created a fresh WebView and ran `beginAndroidAuthentication()`;
- native handoff + shared app preloader became visible again.

## Corrective

`MainActivity` now owns ordinary:
- orientation;
- screenSize;
- smallestScreenSize;
- screenLayout;
- keyboardHidden

configuration changes.

`onConfigurationChanged()`:
- calls `super`;
- refreshes system insets/layout;
- keeps the exact same WebView instance;
- does not authenticate;
- does not navigate;
- does not show loading;
- does not destroy/recreate WebView.

## Recovery boundary

True Activity/process destruction remains separate:
- `onSaveInstanceState()` still saves WebView state;
- `restoreState()` remains the recovery fallback;
- `onDestroy()` still disposes the WebView.

The fix does not lock the app to portrait or landscape.

## Frozen Android identity

Unchanged:
- package `com.minigamesworld.app.acceptance`;
- stable acceptance signing;
- accepted Shield King branding;
- Android Keystore auth;
- WebView security policy.

VersionCode is bumped to 2604 so the corrective APK updates the accepted installation.

No main / production runtime / production DB / production Cron changes.


---

## Follow-up — MVP-26.3.13 restored WebView handoff

A later real-device wallet trace proved the current v1168 web document never renders a zero balance: bootstrap, profile and selected first-paint balance are already non-zero, and the unified balance DOM becomes non-zero before the shared web preloader can reveal the page.

The remaining visible zero therefore exists before the current document owns presentation. The native recovery branch was the only accepted path that could expose a restored WebView immediately: after `restoreState()` it called `showLoading(false)` and returned.

The follow-up keeps the native Shield King handoff panel visible after successful `restoreState()`. The restored WebView remains covered until the existing `WebViewClient` finishes the current main frame and releases the native loading owner. Rotation behavior remains unchanged because ordinary orientation changes still keep the same Activity/WebView instance.

No wallet/economy/account state is rewritten by this corrective.
