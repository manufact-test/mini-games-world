# MVP-14.8.2d — staged invite DB routing

JSON remains the active product and rollback source while invitation persistence is prepared for MySQL/MariaDB.

## d1 foundation

The first substep adds `RuntimeInviteRepository` but does not connect the live invite endpoint and does not enable `database_runtime.invites` in staging.

The repository:

- maps inviter and invitee identities through active account ownership;
- creates normalized `mgw_invites` rows from the JSON source;
- protects immutable invite identity;
- allows expected status and timestamp transitions only when JSON is not older than DB;
- fails closed on count or fingerprint drift;
- supports read-only parity checks;
- requires `accounts`, `notifications` and `invites` together.

## d1 staging check

Deploy without changing staging `_private_mgw/runtime.php`.

Health must show:

- `ok: true`;
- environment `staging`;
- global `storage_driver: json`;
- enabled modules `accounts` and `notifications` only;
- production routing false.

No Cron is needed for d1.

## d2 endpoint wiring

A separate substep will connect the invite endpoint, deploy with `invites` disabled, enable it only in staging, exercise one invite flow and run one temporary read-only parity audit.

## Rollback

Before d2 activation, revert only the inactive code. No data, config, migration or Cron change is required.
