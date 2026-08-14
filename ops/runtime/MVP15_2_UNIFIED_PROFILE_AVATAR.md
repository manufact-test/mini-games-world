# MVP-15.2 — Unified visible profile and avatar

Status: **candidate implementation**

## Goal

Move the visible v110 identity/avatar consumer onto the canonical provider-neutral MGW profile contract created in MVP-15.1, without pulling balance/economy migration forward from MVP-15.3.

## Canonical ownership

### Visible identity

Canonical source:

- `POST /bot/profile.php`
- `bot/accounts/MgwProfileService.php`
- `mgw_users` addressed by authenticated `mgw_id`

Visible client projection:

- `app/assets/js/profile/mgw-profile-model.js`

It owns:

- `mgw_id`;
- display name / username;
- avatar public reference;
- registration date;
- the marker that canonical MGW profile data has been loaded.

### Avatar

When the canonical profile is loaded, the app uses only `profile.avatar.external_ref` for the current visible avatar. Direct `Telegram.WebApp.initDataUnsafe.user.photo_url` lookup is disabled for that canonical state.

The old direct Telegram photo lookup remains only as pre-MVP-15 compatibility behavior when no canonical profile has been loaded. It is not the accepted owner after MVP-15.2 boot completes.

### Legacy profile compatibility

`action=profile` remains temporarily in use for:

- historical profile statistics;
- current Match/Gold balances;
- shop/order compatibility;
- session compatibility.

Its `user` identity fields are merged only as runtime compatibility data and then overwritten by the canonical MGW identity projection before rendering.

Balance/economy ownership is intentionally unchanged until MVP-15.3.

## Boot graph

Accepted v110 boot order:

1. existing `api.bootstrap()` loads runtime/game/economy compatibility state;
2. `api.mgwProfile()` loads canonical MGW identity/avatar;
3. `applyCanonicalMgwProfile()` overlays canonical identity onto the compatibility runtime user;
4. all top/profile/search visible user renderers receive that canonical identity.

The two calls are sequential on purpose so account/session resolution is not raced by two simultaneous authentication requests.

## Profile refresh graph

Opening the profile:

1. immediately renders current accepted state;
2. refreshes `/bot/profile.php`;
3. refreshes legacy stats/orders compatibility;
4. overlays canonical identity last;
5. renders name/avatar/date/stats/balances.

Thus legacy profile refresh cannot replace the canonical identity owner.

## Safety / non-actions

- no balance or ledger semantics changed;
- no Gold/Match room behavior changed;
- no game/matchmaking/invite logic changed;
- no new authentication provider enabled;
- no provider subject or linked-provider username/email exposed;
- no Telegram initData validator duplicated;
- no production/main/Cron/webhook change;
- no visual redesign of the profile screen.

## Acceptance

MVP-15.2 closes when:

1. focused unified-profile client ownership contract is green;
2. exact staging fingerprint includes all changed visible-profile runtime files;
3. exact Hostinger staging deployment is proven;
4. existing two-context staging E2E remains green or any failure is proven unrelated;
5. one manual Telegram check confirms the same user name/avatar/date render correctly on Home and Profile and the profile opens normally.

Manual acceptance is required because MVP-15.2 changes the visible identity/avatar owner.
