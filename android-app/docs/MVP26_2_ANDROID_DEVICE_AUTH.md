# MVP-26.2 — Android device authentication

Parent: accepted MVP-26.1 staging `bd21c801801af713f61e297b16422b7e2969f66d`.

## Goal

Make the internal Android build enter the real staging MGW product without forging Telegram `initData` and without creating a second account/session owner.

## Credential flow

1. Android creates 32 random bytes with `SecureRandom`.
2. The Base64URL credential is encrypted at rest with an AES-256-GCM key held by Android Keystore.
3. The native WebView sends the credential only in the HTTPS POST body to `/bot/android-auth.php`.
4. The staging server derives `sha256(raw credential)` as provider subject. The raw device credential is never written to the database.
5. Existing `AccountIdentityService` resolves provider `android_device` / platform `android`.
6. Existing `mgw_sessions` owns an opaque `ada_...` session. Only its one-way SHA-256 is persisted.
7. The endpoint sets that session as `Secure; HttpOnly; SameSite=Strict` and redirects through canonical `WebAppLaunchUrl`.
8. Existing WebView API requests continue to send empty Telegram `initData`; the shared `AuthService` resolves the HttpOnly Android session cookie instead.

There is no JavaScript auth bridge and no fake `window.Telegram`.

## Scope boundary

This slice is intentionally staging-only. Production returns no Android device-auth surface.

It does not implement:
- Google Sign-In / Play Games identity;
- account-link UI between Telegram and Android identities;
- Android destructive-action reauthentication;
- Play Integrity;
- billing, ads, push or analytics.

Those remain later MVP-26/MVP-29 work.

## Abuse boundary

The staging bootstrap is bounded by database-backed network and credential-subject windows. Actor keys are HMAC hashes; raw IP addresses and raw device credentials are not stored.

## Product ownership

All visible account/profile/economy/game state remains owned by the existing MGW account model. Android only supplies a provider identity adapter.
