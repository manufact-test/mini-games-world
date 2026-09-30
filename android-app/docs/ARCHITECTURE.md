# MVP-26.1 Android architecture

## Runtime shape

```text
Android Activity
  -> native lifecycle / insets / Back
  -> hardened WebView shell
  -> configured HTTPS MGW entry
  -> canonical MGW web product
```

The Android shell is a platform container, not a second product implementation.

## Historical donor classification

The historical Android branches are thousands of commits behind the accepted Telegram RC. They are therefore donors only.

Reused:
- build skeleton;
- hardened WebView/navigation policy;
- native lifecycle/error handling;
- accepted launcher/splash resource family;
- Android unit/foundation verification.

Not imported as authority:
- old branch history;
- stale checkpoint documents;
- old parent SHAs;
- old staging URL assumptions;
- old statements that Android must never integrate into staging.

## Current limitation

The canonical product still enters through a Telegram-aware account/session contract. MVP-26.1 deliberately does not emulate Telegram credentials and does not inject a fake Telegram bridge.

Authenticated Android parity therefore requires a later MVP-26 identity/session adapter slice, while keeping the existing MGW account and backend as the single authoritative account owner.

## Safety

No change in this slice to:
- Telegram runtime;
- backend/API/database;
- economy/ledger;
- matchmaking/invites;
- game engines;
- Store/Profile behavior;
- Arena/tournaments;
- Support/Admin;
- production configuration.
