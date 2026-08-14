# MVP-15.2 — Unified visible profile and avatar

Status: **candidate implementation**

## Goal

Move the visible v110 profile identity from the legacy Telegram-keyed user projection onto the canonical provider-neutral MGW profile contract created in MVP-15.1, without mixing this migration with the later balance/economy unification.

## Single-owner rule

The visible profile identity has one owner:

- `app/assets/js/screens/profile-screen-v110.js`
- source: `POST /bot/profile.php`
- account key: `profile.mgw_id`

The shared legacy `renderUser()` no longer writes:

- `profileName`;
- `profileAvatar`;
- `profileDate`.

It continues to own Home/Search user presentation until those surfaces are migrated in their own roadmap stages.

## Visible canonical fields

The profile screen now renders from the MGW profile response:

- display name;
- public username when available;
- MGW id;
- registration date;
- avatar external reference;
- initials fallback when no usable avatar URL exists.

The avatar DOM owner is `mgw_id`, not Telegram id.

Only `http:` and `https:` external avatar URLs are accepted by the browser renderer. No provider SDK is consulted by the profile screen once the canonical profile response exists.

`avatar_storage_key` is intentionally not converted into a URL in this stage because there is no canonical first-party avatar delivery endpoint yet. If a future upload/storage-backed avatar is introduced, its resolver must become the owner rather than inventing a path in the client.

## Transitional compatibility

The existing `action=profile` call remains temporarily in the profile load bundle only for data not migrated in MVP-15.2:

- game statistics;
- Match/Gold balances;
- shop availability;
- current session compatibility.

It does **not** render visible profile name, avatar, MGW id or registration date.

This transitional dependency is removed or narrowed further by MVP-15.3+ when economy state is unified.

## Cache / deployment graph

`app/v110.php` publishes cache-addressed owners:

- API client: `v1132&profile=mgw-canonical`;
- shared UI: `v90&profile=single-owner`;
- profile screen: `v1109&profile=mgw-canonical`.

The exact staging fingerprint includes all changed profile runtime files.

## Non-actions

- no balance values or ledger entries changed;
- no starter/weekly bonus semantics changed;
- no Match payout rules changed;
- no Gold backend disabled yet;
- no game/matchmaking/invite behavior changed;
- no profile image upload introduced;
- no Google login enabled;
- no main/production release;
- no Cron/webhook change.

## Acceptance

MVP-15.2 closes when:

1. focused single-owner profile contract is green;
2. exact staging fingerprint/deploy is green;
3. existing staging A/B regression is green or any failure is proven unrelated;
4. manual Telegram acceptance confirms the profile shows the expected name/avatar (or initials fallback), MGW id and date while statistics/balances remain intact.
