# Android Shell Foundation — architecture

## Owner boundary

This workstream owns only `android-app/**` and intentionally has no authority over the MGW backend, Telegram Mini App, game lifecycle, DB, identity, economy, matchmaking, settlement, or shared CI.

## Runtime shape

```text
Android Activity
  -> platform insets / lifecycle / Back ownership
  -> hardened WebView container
  -> configured HTTPS MGW origin
  -> existing MGW web product remains UI and product owner
```

The Android shell does not recreate the MGW home, profile, store, matchmaking, or game screens.

## URL configuration

The shell has no production URL baked into source. `MGW_BASE_URL` must be supplied as a Gradle property or environment variable at build time.

The configured URL must be absolute HTTPS and must not contain embedded credentials.

Top-level navigation rules:

- same HTTPS origin: stays inside the WebView;
- ordinary external HTTPS/HTTP, `mailto:`, `tel:`, `tg:`: may leave to an Android handler;
- `file:`, `content:`, `javascript:`, `data:`, `intent:`: blocked;
- incoming intents are accepted only when they resolve to the configured MGW HTTPS origin.

Actual Android App Links registration is intentionally deferred until the final production domain and account/navigation contracts are stable.

## State ownership

The shell may save/restore WebView container state across Android recreation. It does not persist or synthesize authoritative match/session state. The existing MGW client/backend remain authoritative for reconnect and gameplay restoration.

## Current integration limitation

The present MGW product is still Telegram/account-contract dependent. This foundation deliberately does not emulate Telegram `initData`, does not inject a fake Telegram JavaScript bridge, and does not create a parallel login owner. Full authenticated Android product use therefore waits for the future provider-neutral MGW identity/integration gate.
