# MVP-27.5 — Account-level RU/EN language
## Authority and scope
- Existing mgw_users.preferred_locale is the only durable MGW language owner.
- Authenticated provider-neutral profile-v2.php writes it for the current MGW ID,
  validated as ru/en by MgwIdentityPolicy. No new DB or identity service.
- Authenticated profile.php read before initial home paint hydrates shared UI;
  a pending write invalidates stale first-boot preference reads.
- Canonical account > legacy local override > platform > ru fallback.
  Unset canonical language preserves legacy device-local preference until chosen.
- Settings select waits for successful server confirmation; network failures leave
  sheet open and do not claim saved. Telegram channel preference is a downstream
  projection when linked Telegram Mini App is opened.
- Native Android OS language is separate for native-owned errors and download UI.
- No changes to account links, game ownership, economy, shop, tournaments or prod.
## Manual acceptance (pending)
Set EN on Android -> linked Telegram Mini App should load EN. Set RU there ->
restart Android and verify RU. Test a distinct unlinked account remains isolated.
Check native Android OS language remains controlled by Android settings.
