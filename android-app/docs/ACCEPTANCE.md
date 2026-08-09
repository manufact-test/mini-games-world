# Android Shell Foundation — acceptance matrix

## Automated/static

- Android project structure exists under `android-app/**` only.
- AGP 9.3.0 / compileSdk 36 / targetSdk 36 / Java 17 contract.
- XML resources parse successfully.
- WebView hardening contract passes `tools/verify-foundation.py`.
- `NavigationPolicy` owns same-origin/external/blocked URL classification.

## Build acceptance

Expected command on an Android build host with JDK 17+, Android SDK 36 and Gradle 9.5+:

```bash
cd android-app
MGW_BASE_URL="https://<approved-mgw-host>/" gradle clean test lint assembleDebug
```

The foundation deliberately does not commit production secrets or a production URL.

## Device acceptance (future/manual gate)

1. App opens configured MGW origin.
2. Existing MGW web UI is shown unchanged as the product surface.
3. Android Back walks WebView history, then exits.
4. Minimize/reopen preserves the container without inventing match state.
5. System bars do not cover the container.
6. Main-frame network failure shows bounded native retry UI.
7. SSL errors fail closed.
8. External ordinary links leave the container; privileged/script/file schemes do not.
9. No duplicate shell instance is created by normal relaunch.

Full authenticated gameplay is intentionally not an acceptance requirement of this isolated foundation because provider-neutral MGW identity is outside this workstream.
