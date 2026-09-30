# Mini Games World — Android MVP-26

Status: Android product workstream integrated on the manually accepted MVP-25 Telegram product.

## Current checkpoint

- MVP-26.1 foundation: accepted on staging.
- MVP-26.2: provider-neutral Android device authentication for internal staging validation.
- Android branch history from the old prototype was not merged; only isolated technical assets were transplanted.

## Architecture

```text
Android Activity
  -> Android Keystore protected device credential
  -> HTTPS POST /bot/android-auth.php
  -> existing MGW provider-neutral AccountIdentityService
  -> HttpOnly MGW Android session
  -> canonical WebAppLaunchUrl
  -> current v110 + version-manifest/import-map runtime
```

The Android shell is a platform container, not a second implementation of Home, Profile, Store, matchmaking or the eight accepted games.

## Toolchain

- Android Gradle Plugin: 9.3.0
- Gradle: 9.5.0+
- Java: 17
- compileSdk / targetSdk: 36
- minSdk: 26

## Build

```bash
cd android-app
MGW_BASE_URL="https://staging.example.invalid/" gradle --no-daemon clean test lint assembleDebug
```

`MGW_BASE_URL` supplies only the trusted HTTPS origin. The app derives `/bot/android-auth.php`; the server owns the exact current MGW entry URL.

## Security

- cleartext disabled;
- file/content access disabled;
- mixed content disabled;
- SSL errors fail closed;
- third-party WebView cookies disabled;
- WebView debugging only in debug builds;
- no privileged JavaScript interface;
- device root credential is 256-bit random data encrypted by Android Keystore;
- auth session is HttpOnly and server-side revocable;
- blocked top-level schemes include file/content/javascript/data/intent.

## Current limitations

MVP-26.2 uses a staging-only internal Android identity. It does not yet link an existing Telegram account automatically, and destructive account actions retain the existing Telegram reauth contract until a later Android reauth slice.
