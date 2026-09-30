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
