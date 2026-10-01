# MVP-26.4 — Android Account Reauthentication

## Goal

Allow sensitive Account Data actions to be confirmed safely inside the Android app without weakening the existing Telegram fresh-`initData` rule.

Protected actions remain:
- create data export;
- download export;
- schedule account deletion;
- cancel account deletion.

## Security model

Ordinary Android session-cookie authentication is not sufficient reauthentication.

The accepted Android provider keeps its existing boundaries:
- the 256-bit device credential remains encrypted by Android Keystore;
- the raw credential never enters WebView/JavaScript;
- no general-purpose `addJavascriptInterface` is introduced;
- MGW account/session ownership remains canonical on the server;
- production remains fail-closed while the internal Android provider is staging-only.

## Flow

1. A sensitive request reaches `AccountReauthGuard`.
2. Fresh Telegram `initData` continues to work exactly as before.
3. An Android session without a recent native grant receives `android_reauth_required`.
4. WebView asks `/bot/android-reauth.php` for one short-lived public challenge.
5. JavaScript receives only:
   - challenge id;
   - `mgw://android-reauth?challenge=...`.
6. `NavigationPolicy` intercepts only that exact native route.
7. Android opens the system device-credential confirmation UI.
8. After successful device unlock, native code reads the Keystore credential and POSTs it directly to `/bot/android-reauth.php`.
9. The server verifies:
   - credential → Android identity;
   - MGW account;
   - exact existing Android session hash;
   - challenge ownership and expiry.
10. The server creates a short reauth grant for that exact session.
11. Native code dispatches only a fixed success/cancel/failure event to the internal WebView.
12. The original sensitive action is retried exactly once.

## Lifetimes

- challenge: 120 seconds;
- reauth grant: 300 seconds;
- challenge creation: bounded to 10 per MGW account per hour.

A grant is bound to:
- MGW id;
- Android provider subject;
- exact Android session hash.

The same device/account under another session does not inherit the grant.

## Persistence

Migration:

`20261001_0071_create_android_reauth_challenges`

Stored:
- challenge id;
- MGW id;
- Android one-way provider subject;
- one-way session hash;
- state/timestamps.

Never stored:
- raw device credential;
- raw Android session token;
- PIN/password/biometric material.

## Native ownership

No privileged JS bridge is added.

The only WebView → native handoff is an exact custom navigation route:

`mgw://android-reauth?challenge=ar_<24 lowercase hex>`

The only native → WebView signals are fixed event names:
- `mgw:android-reauth-success`;
- `mgw:android-reauth-cancelled`;
- `mgw:android-reauth-failed`.

No secret or token is included in those events.


## Real-device install corrective

The first reauth candidate used versionCode 2605, but real-device acceptance had already advanced through later 2606/2607 Android candidates. Huawei/EMUI therefore rejected 2605 as a downgrade. The installable reauth acceptance candidate is versionCode 2608 with the same package and accepted signing identity.

## Acceptance gates

Automated:
- PHP syntax;
- JavaScript syntax;
- SQLite reauth regression;
- existing Android auth regression;
- existing Android ↔ Telegram account-link regression;
- existing Account Data lifecycle regression;
- provider-neutral identity/security contracts;
- Android unit tests;
- Android lint;
- Android debug APK build;
- accepted signing identity verification;
- MySQL 8.4 migration idempotency;
- tracked-secret scan;
- scope/frozen-owner guard.

Manual, only after automated gates are green:
1. install the candidate over the accepted Android app;
2. open **Данные и аккаунт**;
3. start one protected action;
4. verify Android system unlock prompt appears;
5. confirm it;
6. verify the original action completes;
7. retry another protected action within five minutes and confirm no second prompt is required;
8. after the grant expires, verify a new protected action requires confirmation again;
9. cancel one prompt and verify the protected action does not execute.

## Boundaries

No change to:
- game mechanics;
- wallet/ledger semantics;
- Store ownership;
- tournaments/rating;
- Telegram reauth semantics;
- main/production runtime;
- production DB/Cron.
