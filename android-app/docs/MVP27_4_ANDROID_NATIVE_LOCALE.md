# MVP-27.4 — Android native shell RU/EN

**Scope:** Only native-owned loading/Retry/network/HTTPS error screens, Android device-lock reauth prompts, Account Data ZIP downloads and ordinary file downloads, system notifications, and Android OS app-language setting. The shared Web Home/Profile/Settings, Store, games, backend identity and Telegram localization are not native owners.

## Native resource contract

- Default `res/values/strings.xml` remains accepted Russian, `res/values-en/strings.xml` carries exactly the same keys in English.
- `AndroidManifest.xml` advertises RU and EN through `res/xml/locales_config.xml` for Android 13+ system App Languages. Older Android versions use the ordinary system locale resource resolver.
- Android language changes recreate the Activity (locale is not swallowed by `configChanges`); ordinary orientation and process-state preservation remain unchanged.
- An unsupported device locale uses RU default resources, not raw error codes or empty copy.
- General support-attachment/file downloads must use generic native notifications; only account-data archives say ZIP.
- Android OS/app-language and explicit Mini App language may differ until MVP-27.5 account-level preference. Never invent a second preference database/Java owner.

## Acceptance build

- Branch off current staging; increment v2615 to **v2616** for in-place update, preserve package `com.minigamesworld.app.acceptance` and accepted signing certificate.
- Focused source/resource contract; accepted Android lifecycle/security/provider verifiers; JUnit + lint + signed staging APK.
- Isolated **diagnostic** APK intentionally has an invalid configuration, allowing emulator UI assertion of actual Android resource resolution in EN, RU and unsupported FR fallback. This probe must NEVER be distributed as an acceptance build.
- Only the correctly configured `MGW_BASE_URL=https://seashell-okapi-889488.hostingersite.com/` signed v2616 APK artifact is for real-device acceptance.
- Existing 26.x version-pin tests now preserve the exact historical v2615 identity while allowing a monotonic localized successor. No legacy Android branch merges.

## Real Android manual gate — pending

Install signed staging APK **over** the existing app (no uninstall). Check Android app language English then Russian; native loading/network error/Retry and device-lock reauth; Account Data ZIP and ordinary attachment notifications; resume/Back/keyboard/rotation; linked MGW account/wallet preserved. The test owner must confirm PASS before MVP-27.4 is closed. Android provider adapters remain disabled through MVP-29.

## Real-device manual acceptance — confirmed 2026-10-08

Test owner confirmed successful in-place restoration, preserved existing
app state, working application and language switching on Android.
MVP-27.4 is CLOSED. ZIP/reauth edge cases were not separately reported.
Staging merge PR #2158: 90367a86ac3ad9421595830fcc71ad7538566e75.
