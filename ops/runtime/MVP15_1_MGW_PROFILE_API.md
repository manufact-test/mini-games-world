# MVP-15.1 — Canonical MGW profile API / provider-neutral auth core

Status: **candidate implementation**

## Goal

Expose the internal MGW account as the provider-neutral public profile identity and make the runtime account-resolution core provider-neutral without replacing the account/identity/session foundation completed in MVP-14.3.

Telegram remains the currently active authentication provider. It is an adapter, not the account key or the generic account resolver.

## Canonical owners

### Provider credential verification

Provider adapters own verification of their own credentials. Today that is Telegram through the existing `AuthService` signed-initData path.

MVP-15.1 does not add Google credential verification or a second login/session store.

### Verified provider identity → MGW account

`bot/accounts/AccountIdentityService.php` now exposes the canonical provider-neutral method:

`resolveProviderIdentity(provider, subject, platform, profile, sessionId)`

It owns the shared rules for:

- provider/subject normalization;
- finding or creating the MGW account;
- refreshing public profile metadata;
- registering devices and sessions;
- preserving provider separation when two providers use the same subject text.

`resolveTelegramUser()` remains as a compatibility/current-provider adapter and delegates to `resolveProviderIdentity()` rather than implementing a parallel account-resolution path.

Existing runtime owners remain:

- `bot/services/AuthService.php` — current Telegram credential verification;
- `bot/accounts/RuntimeAccountIdentityResolver.php` — current request adapter and account-ownership binding;
- `bot/accounts/AccountIdentityService.php` — provider-neutral identity/account/session core;
- `bot/accounts/RuntimeAccountOwnershipService.php` — immutable legacy-account ownership anchor used by the current migrated Telegram/development runtime;
- `mgw_users`, `mgw_identities`, `mgw_devices`, `mgw_sessions`.

`RuntimeAccountOwnershipService` is intentionally **not** generalized into a provider account resolver: its `legacy_user_id` table is the migration ownership anchor. Future linked providers must resolve through `mgw_identities` to the MGW account rather than becoming competing legacy ownership records.

### Public profile projection

Single owner:

- `bot/accounts/MgwProfileService.php`

The service reads `mgw_users` by authenticated `mgw_id`. Linked identities are exposed only as provider names plus link timestamps; provider subjects and provider usernames stay private.

### Canonical profile endpoint

- `POST /bot/profile.php`

Request authentication goes through `AuthService::getUserFromRequest()` first. The endpoint then consumes only the resolved internal `mgw_id` as the account key.

Response contract:

```text
ok
profile.mgw_id
profile.status
profile.display_name
profile.username
profile.avatar.*
profile.identities[].provider
profile.identities[].linked_at
profile.created_at
profile.updated_at
profile.last_seen_at
auth.provider
auth.provider_neutral=true
```

Not exposed:

- Telegram user id as canonical account id;
- any `provider_subject`;
- provider-specific username/email from linked identities;
- session hashes/device hashes;
- private account ownership source refs;
- balance/economy state (owned by later MVP-15 stages).

## Backward compatibility

The historical `action=profile` path inside `bot/api.php` is left intact for the current v110 UI during MVP-15.1. It is compatibility-only; new profile work must target `/bot/profile.php`.

MVP-15.2 moves the visible profile/avatar consumer onto this contract before legacy profile fields are considered for removal. No compatibility path is deleted before that migration is accepted.

The existing Telegram login path is behavior-compatible because `resolveTelegramUser()` still accepts the same Telegram payload and returns the same MGW identity/session result; only its account-resolution implementation is now delegated to the generic core.

## Provider-neutral rule

A future Google/Android adapter must first verify Google credentials, then pass the verified provider subject/profile to `resolveProviderIdentity()`. If that Google identity has already been linked to an MGW account, the shared `mgw_identities` mapping resolves that same `mgw_id`; the public profile API does not change.

Account linking itself is a separate explicit flow and must never merge providers merely because subject text or email happens to match.

## Safety / non-actions

- no balance or ledger semantics changed;
- no Match/Gold behavior changed;
- no game/matchmaking/invite behavior changed;
- no Telegram initData validator replaced;
- no Google credential verification/login enabled yet;
- no legacy ownership schema repurposed;
- no main/production release;
- no Cron/webhook change.

## Acceptance

MVP-15.1 closes when:

1. focused profile API contract/integration test is green;
2. focused provider-neutral identity-core test is green;
3. exact staging fingerprint includes the profile endpoint/service and provider-neutral account core;
4. staging deploy proves exact runtime graph;
5. existing Telegram v110 regression remains green or any failure is proven unrelated.

No separate manual user acceptance is required for 15.1 because visible profile UI migration is intentionally deferred to MVP-15.2.
