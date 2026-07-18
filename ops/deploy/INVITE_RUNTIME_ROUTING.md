# MVP-14.8.2d — staged invite DB routing

JSON remains the active product and rollback source while invitation persistence is mirrored into MySQL/MariaDB.

## d1 foundation

`RuntimeInviteRepository` maps current JSON invitation rows into normalized `mgw_invites` rows. The repository:

- resolves inviter, invitee and cancelling actor through active account ownership;
- protects immutable invite identity and token;
- permits expected status and timestamp transitions only when DB is not ahead of JSON;
- fails closed on count or fingerprint drift;
- supports CLI-only read-only parity checks;
- requires `accounts`, `notifications` and `invites` together.

The inactive d1 deployment was verified in staging with only `accounts` and `notifications` enabled.

## d2 endpoint wiring

The existing `bot/invites.php` endpoint keeps all product mutations in JSON. When the staging-only `invites` route is enabled:

1. the JSON transaction commits first;
2. the endpoint reloads the committed JSON snapshot;
3. `RuntimeInviteRepository` mirrors that snapshot into DB;
4. parity must pass before external share preparation or a successful API response.

The user-facing invite interface and API response contract are unchanged.

## Safe staging activation

1. Deploy build `v93-mvp14-db-invite-routing` with `invites` still disabled.
2. Confirm health is `ok`, global storage is `json`, migrations are current and only `accounts` plus `notifications` are enabled.
3. Add `invites => true` to staging `_private_mgw/runtime.php` only.
4. Confirm health lists `accounts`, `invites` and `notifications`; production routing must remain false.
5. Exercise one existing link-invite flow in the test application and leave the invite in a terminal state.
6. Run one temporary read-only Cron:

```bash
/usr/bin/php /home/u235811320/domains/seashell-okapi-889488.hostingersite.com/public_html/ops/deploy/invite-runtime-audit.php --expected-invites=1
```

7. Require `ok=true`, equal JSON/DB counts and fingerprints, `parity=true`, and `blockers=[]`.
8. Delete only the temporary invite audit Cron.

## Rollback

Set only `invites => false` or remove that module from staging `runtime.php`. Keep `accounts` and `notifications` enabled. Do not delete JSON data, DB rows, migrations or permanent Cron jobs.
