# MVP-26.3.5 — Android Home account-link onboarding

Base staging: `b508c85fa6d4e773c24835b26c87fa99f32200c9`.

## Product decision

The permanent Profile row remains, but it is too hidden for a normal first-time Android user.

After successful app boot, an unlinked Android user receives one Home onboarding prompt:

**Уже играете в MINI GAMES WORLD в Telegram?**

Actions:
- **Привязать Telegram**
- **Позже**

## Eligibility

The onboarding appears only when all are true:
- canonical provider is `android_device`;
- current MGW id exists;
- canonical profile has no Telegram identity;
- there is no pending account-link challenge;
- this same temporary Android MGW id has not already dismissed onboarding;
- current route is `home`;
- startup preloader is already hidden;
- another sheet is not active.

It therefore never appears:
- in Telegram Mini App;
- for an already linked account;
- over an active game;
- over another sheet;
- repeatedly after the same Android profile chooses **Позже**.

## Dismissal ownership

Dismissal is stored locally against the temporary Android MGW id, not as a global boolean.

If a genuinely different unlinked Android MGW account later exists in the same WebView data, it may receive its own onboarding.

Closing by X or tapping outside is treated as **Позже**.

## Fast-start provider fallback

The account-link sheet no longer requires background `profile-v2` hydration before recognizing Android.

Provider ownership resolves from:
1. `state.profileAuth.provider` when available;
2. canonical bootstrap `state.user.mgw_identity_provider` as the fast-start fallback.

No account/security semantics change.

No main / production runtime / production DB / production Cron changes.

## MVP-26.3.16 presentation corrective

Real-device/product-owner feedback after the functional link flow was implemented:
- the first-run prompt was technically Home-triggered but visually read like an ordinary shared sheet / notification surface;
- account-link semantics themselves were already working and must not be redesigned.

Corrective presentation:
- first-run onboarding remains Home-only and one-time;
- it now uses a dedicated centered Shield King card presentation;
- the card explicitly shows Telegram → Android as one profile;
- primary action remains **Привязать Telegram**;
- secondary action remains **Позже**;
- dismissal ownership and no-nag behavior are unchanged;
- Profile fallback remains unchanged;
- after the primary action, control hands off to the existing account-link sheet/security flow unchanged.

This corrective is presentation-only. Account-link backend, target-account ownership, starter-coin retirement, authentication and confirmation semantics are frozen.

## MVP-26.3.17 temporary staging preview

The product owner's current Android account is already linked, therefore the real one-time onboarding correctly no longer appears there.

For final visual acceptance only, staging exposes a temporary non-mutating Profile row:
- visible only on the exact staging hostname;
- visible only for Android provider + already-linked Telegram identity;
- opens the exact onboarding card presentation;
- both visible onboarding actions only close the preview;
- does not unlink, relink, create challenges, write dismissal state, move balance, or change account ownership.

This preview is temporary acceptance tooling and must be removed immediately after visual manual PASS.
