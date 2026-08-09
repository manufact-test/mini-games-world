# Mini Games World — Android Shell Foundation

Status: isolated parallel workstream.

## Ownership boundary

This branch owns only:

```text
android-app/**
```

No file outside this directory may be changed by the Android Shell Foundation workstream.

## Parent snapshot at fork

- Repository: `manufact-test/mini-games-world`
- Base branch: `main`
- Exact base SHA: `e11bb4909d549c1c5262de6eaf18338388e7bcdb`
- Android branch: `agent/android-shell-foundation`
- Parent staging observed at A0: `881116d6b43c75e4e72e123711dd94706ba65ce5`
- Parent staging work remains independent and may continue moving.

## Foundation scope

This module is a native Android container for the existing MGW web product. It does not create a second home screen, profile, store, matchmaking UI or game implementation.

Current foundation owners:

- Android build skeleton;
- native Activity/application shell;
- configurable HTTPS MGW origin;
- hardened WebView container;
- Android Back + lifecycle handling;
- system inset handling;
- native startup/network/security failure surface;
- safe top-level navigation policy;
- local foundation verification.

## Toolchain contract

- Android Gradle Plugin: 9.3.0
- Gradle: 9.5+ (9.5.0 is the AGP 9.3 minimum/default documented baseline)
- Java: 17 bytecode target
- compileSdk: 36
- targetSdk: 36
- minSdk: 26

No Gradle wrapper binary is committed in this isolated foundation because repository tooling available during this parallel workstream cannot safely add/verify the binary wrapper artifact. Use Android Studio or an installed Gradle 9.5+ build host. A wrapper may be generated later entirely inside `android-app/**` without changing parent MGW files.

## Configure the MGW URL

The source intentionally contains no production endpoint.

Supply the approved HTTPS origin at build time:

```bash
cd android-app
MGW_BASE_URL="https://<approved-mgw-host>/" gradle :app:assembleDebug
```

## Verify foundation contracts

```bash
python3 tools/verify-foundation.py
```

On an Android build host:

```bash
MGW_BASE_URL="https://<approved-mgw-host>/" gradle clean test lint assembleDebug
```

## Forbidden ownership

This Android workstream must not modify or become an owner for:

- Mini Games World backend/API/storage/database;
- Telegram Mini App frontend/runtime;
- Phase B or Phase C runtime;
- authentication or provider-neutral identity;
- economy/ledger/settlement;
- matchmaking, bots, invites, rematches or game lifecycle;
- shared GitHub Actions workflows;
- staging or production deployment configuration.

If a future Android task appears to require a change outside `android-app/**`, stop that task and record it as a future integration dependency instead of changing the parent project.

## Deferred deliberately

- provider-neutral account/auth;
- Firebase/push backend;
- Google Play Billing;
- purchases/economy/settlement;
- production deep-link/App-Link registration;
- backend data export/delete;
- final legal/privacy copy;
- Play Store release;
- native rewrites of existing MGW product screens.

## Integration status

```text
COMPLETED FOUNDATION WORK != INTEGRATED PRODUCT WORK
```

The branch must remain isolated and must not be merged into staging or main by this workstream. A future explicit integration gate will reconcile this foundation with the then-current global MGW architecture.
