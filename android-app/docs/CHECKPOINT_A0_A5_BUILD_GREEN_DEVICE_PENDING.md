# Android Shell Foundation — checkpoint

Status: **A0-A4 PASS; A5 BUILD GREEN; REAL-DEVICE ACCEPTANCE PENDING**

## Isolation

- Repository: `manufact-test/mini-games-world`
- Android branch: `agent/android-shell-foundation`
- Base: `main@e11bb4909d549c1c5262de6eaf18338388e7bcdb`
- Owned path: `android-app/**`
- Main/staging/production: untouched by this workstream

## Completed gates

### A0 — isolation & repository preflight

PASS.

### A1 — native Android build skeleton

PASS.

Verified on a GitHub-hosted Android build verifier with:

- JDK 17;
- Gradle 9.5.0;
- Android SDK 36;
- `clean`;
- unit tests;
- Android lint;
- `assembleDebug`.

### A2 — MGW Web container shell

PASS by code/contracts and Android build verification.

### A3 — Android lifecycle & navigation

PASS by code/contracts and lint after an actual API-boundary finding was fixed.

The first real Android build exposed predictive-back lint errors. Root fix:

- API 33+ remains owned by `OnBackInvokedDispatcher`;
- API 26-32 retains the legacy back callback;
- API 33-only owner is explicitly target-API scoped;
- only the justified legacy callback receives the narrow `GestureBackNavigation` lint suppression;
- no lint baseline and no broad suppression were added.

Android branch fix commit:

`153a9fec8a39361d721f0de4555e06d502f74fd0`

### A4 — platform safety / offline / insets

PASS by static verifier + Android lint/build.

## Temporary verifier exception

Product owner explicitly authorized one temporary verifier branch because the isolated execution environment could not provide Android SDK/Gradle.

Verifier branch:

`agent/android-shell-foundation-build-verify`

Verifier PR:

`#719 — Verifier only: build Android Shell Foundation`

Rules:

- verifier workflow must never merge;
- verifier branch does not become part of Android foundation history;
- main/staging/production remain untouched;
- only build evidence and APK output are retained.

## Exact final build evidence before device test

Workflow:

`Android Shell Foundation Build Verify`

Final staging-targeted run:

`31326541505`

Conclusion:

`success`

Build target injected only at build time:

`https://seashell-okapi-889488.hostingersite.com/app/v110.php?v=1123`

Successful steps:

- JDK 17 setup;
- Gradle 9.5.0 setup;
- Android SDK 36 setup;
- `python3 tools/verify-foundation.py`;
- `gradle --no-daemon clean test lint assembleDebug`;
- debug APK upload;
- reports upload.

APK workflow artifact:

- artifact name: `mgw-android-shell-foundation-staging-debug`;
- artifact id: `9041705672`;
- artifact ZIP digest: `sha256:439736034ca273f7741b7e7a8101540e81f8f4934d4165605d5bd197f5525d85`;
- extracted APK SHA-256: `d4700d682272a906d1798217960fc39ee75e79cb256ab6794d9881f862deebbd`.

## Remaining A5 gate

Only real-device shell acceptance remains before final freeze/handoff.

Install the staging debug APK on an Android device and verify:

1. application installs and starts;
2. it attempts to open the configured MGW staging entrypoint;
3. system status/navigation bars do not cover the shell unexpectedly;
4. Android Back behaves normally;
5. minimize/reopen does not create a duplicate shell instance;
6. no crash/frozen native shell is observed;
7. if staging rejects ordinary non-Telegram authentication, record that as an expected future identity integration boundary rather than implementing auth in this workstream.

Full authenticated gameplay is not required because provider-neutral identity is deliberately outside Android Shell Foundation V1.

After real-device PASS:

- mark A5 PASS;
- exact diff audit `main..agent/android-shell-foundation`;
- record final exact Android branch SHA;
- create `MGW_ANDROID_PARALLEL_HANDOFF_V1_<DATE>_<SHA>.md`;
- freeze Android branch;
- instruct the user to transfer that handoff into the main MGW development chat;
- main chat records `Android Shell Foundation — COMPLETED / FROZEN / NOT INTEGRATED` without merging it automatically.
