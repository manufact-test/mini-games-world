# Mini Games World — Android MVP-26

Status: integrated Android product workstream based on the manually accepted MVP-25 Telegram RC.

## Integration checkpoint

- Repository: `manufact-test/mini-games-world`
- Parent staging branch: `agent/mvp-13-2-staging`
- Exact parent SHA: `60f855e4f10ed53afc03f433e056cd6e157ee88b`
- Imported donor: selected technical Android shell/branding files from the historical Android foundation work.
- Historical Android branch history is **not merged**.

## Product ownership

The Android app is a native container for the canonical MGW web product. It must not create a second implementation of Home, Profile, Store, matchmaking or the eight accepted games.

Current MVP-26.1 owners:

- Android build skeleton;
- branded launcher/platform splash resources;
- native Activity shell;
- HTTPS-only configurable MGW origin;
- hardened WebView container;
- Android Back/lifecycle handling;
- safe top-level navigation policy;
- native startup/network/security failure surface;
- local/CI foundation verification.

The accepted Telegram product remains the product behavior source of truth.

## Toolchain

- Android Gradle Plugin: 9.3.0
- Gradle: 9.5.0+
- Java: 17
- compileSdk / targetSdk: 36
- minSdk: 26

The repository intentionally does not commit a Gradle wrapper binary in this slice.

## Build

Supply a safe HTTPS URL explicitly:

```bash
cd android-app
MGW_BASE_URL="https://example.invalid/" gradle --no-daemon clean test lint assembleDebug
```

The MVP-26.1 CI artifact is a foundation build only. Full authenticated Android product acceptance comes later in MVP-26 after the provider-neutral Android account/session integration is complete.

## Security boundary

- no cleartext traffic;
- no WebView file/content access;
- no mixed content;
- SSL errors fail closed;
- WebView debugging is debug-build only;
- no privileged JavaScript interface in MVP-26.1;
- external navigation is allowlisted by scheme;
- unsafe `file:`, `content:`, `javascript:`, `data:` and `intent:` top-level URLs are blocked.

## Explicitly not done in MVP-26.1

- no fake Telegram initData;
- no parallel identity owner;
- no billing, ads, push, integrity or analytics provider;
- no production App Links;
- no backend/API/DB/economy/game/tournament changes;
- no main/production runtime/production DB/Cron changes.
