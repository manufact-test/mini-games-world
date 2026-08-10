# Android Shell Foundation — checkpoint

Status: **A0-A4 CODE/CONTRACT COMPLETE; A5 BUILD/DEVICE GATE PENDING**

## Completed

### A0 — isolation & repository preflight

PASS.

- dedicated branch: `agent/android-shell-foundation`;
- base: `main@e11bb4909d549c1c5262de6eaf18338388e7bcdb`;
- isolated owner: `android-app/**`;
- no pre-existing Android implementation was found by repository search;
- parent staging was observed independently at `881116d6b43c75e4e72e123711dd94706ba65ce5` and remains outside this workstream.

### A1 — native Android build skeleton

CODE COMPLETE / BUILD HOST VERIFICATION PENDING.

- standalone Gradle settings/project/module;
- AGP 9.3.0;
- compile/target SDK 36;
- Java 17;
- min SDK 26;
- no root/shared build-file changes.

The current execution environment has JDK but no Gradle or Android SDK, and its shell network cannot resolve the Android SDK download host. The repository also has no existing Android CI run for this branch. The isolation contract forbids adding a shared `.github/workflows/**` verifier just to work around that environment boundary.

Therefore `gradle clean test lint assembleDebug` remains the honest external build-host gate; it is not replaced with a fake PASS.

### A2 — MGW Web container shell

CODE/CONTRACT PASS.

- configurable HTTPS origin;
- same-origin navigation remains in the WebView;
- ordinary external links may leave the app;
- dangerous file/content/script/data/intent top-level schemes are blocked;
- no fake Telegram identity/bridge;
- no duplicate native MGW product screens.

Pure-Java `NavigationPolicy` contract was compiled with Java 17 and passed 16 assertions in the execution environment.

### A3 — Android lifecycle & navigation

CODE/CONTRACT COMPLETE; DEVICE ACCEPTANCE PENDING.

- WebView Back history before app exit;
- Android 13+ back callback;
- `singleTask` shell owner;
- save/restore WebView container state;
- pause/resume propagation;
- render-process recovery surface;
- no Android game/session authority.

### A4 — platform safety / offline / insets

CODE/STATIC CONTRACT PASS.

- cleartext disabled at manifest + network-security level;
- mixed content blocked;
- local file/content access disabled;
- no privileged `addJavascriptInterface` bridge;
- SSL errors cancelled;
- release WebView debugging gated off;
- system insets use Android-provided values rather than device-specific offsets;
- bounded shell-level retry only; no gameplay/API retry owner.

## A5 remaining gate

Required before this workstream can be marked `COMPLETED / FROZEN / NOT INTEGRATED`:

1. run on an Android build host with JDK 17+, Android SDK 36 and Gradle 9.5+;
2. execute `MGW_BASE_URL=https://<approved-mgw-host>/ gradle clean test lint assembleDebug` from `android-app/`;
3. record build/test/lint PASS;
4. install produced debug APK on a real Android device;
5. perform the bounded shell acceptance matrix in `docs/ACCEPTANCE.md`;
6. then generate the final `MGW_ANDROID_PARALLEL_HANDOFF_V1_<DATE>_<SHA>.md` and freeze the branch.

## Isolation remains intact

No workaround for the build-host limitation is allowed to modify parent MGW files, shared CI, staging, main or production.
