# MVP-15.1 — Canonical MGW profile API

Status: **candidate implementation**

## Goal

Expose the internal MGW account as the provider-neutral public profile identity without replacing the account/identity/session foundation completed in MVP-14.3.

Telegram remains an authentication provider. It is not the account key exposed by the canonical profile contract.

## Canonical owners

### Authentication / provider → MGW identity

Existing owners remain unchanged:

- `bot/services/AuthService.php`
- `bot/accounts/RuntimeAccountIdentityResolver.php`
- `bot/accounts/AccountIdentityService.php`
- `bot/accounts/RuntimeAccountOwnershipService.php`
- `mgw_users`, `mgw_identities`, `mgw_devices`, `mgw_sessions`

MVP-15.1 does **not** add a second login/session store and does not duplicate Telegram initData validation.

### Public profile projection

New single owner:

- `bot/accounts/MgwProfileService.php`

The service reads `mgw_users` by authenticated `mgw_id` and exposes linked providers only as metadata. Provider subjects are deliberately not returned.

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
profile.identities[].username
profile.identities[].linked_at
profile.identities[].last_authenticated_at
profile.created_at
profile.updated_at
profile.last_seen_at
auth.provider
auth.provider_neutral=true
```

Not exposed:

- Telegram user id as canonical account id;
- any `provider_subject`;
- session hashes/device hashes;
- private account ownership source refs;
- balance/economy state (owned by later MVP-15 stages).

## Backward compatibility

The historical `action=profile` path inside `bot/api.php` is left intact for the current v110 UI during MVP-15.1. It is compatibility-only; new profile work must target `/bot/profile.php`.

MVP-15.2 will move the visible profile/avatar consumer onto this contract before legacy profile fields are considered for removal. No compatibility path is deleted before that migration is accepted.

## Provider-neutral rule

Future Google/Android authentication must resolve its provider identity to the same internal `mgw_id` before reaching this profile service. The profile endpoint itself contains no Google-specific behavior and therefore does not need to change when another provider is added.

## Safety / non-actions

- no balance or ledger semantics changed;
- no Match/Gold behavior changed;
- no game/matchmaking/invite behavior changed;
- no existing Telegram authentication owner replaced;
- no Google auth enabled yet;
- no main/production release;
- no Cron/webhook change.

## Acceptance

MVP-15.1 closes when:

1. focused profile API contract/integration test is green;
2. exact staging fingerprint includes the new endpoint/service;
3. staging deploy proves exact runtime graph;
4. existing Telegram v110 regression remains green.

No separate manual user acceptance is required for 15.1 because visible profile UI migration is intentionally deferred to MVP-15.2.
