# MGW account ownership schema

MVP-14.7.3 adds `mgw_account_ownership`, a normalized ownership map between the stable economy account reference and the provider-neutral MGW user.

## Why this table exists

Opening Match/Gold balances and immutable ledger entries were already imported under `legacy:<legacy_user_id>` account references. Those references must not be rewritten merely because an MGW-ID and Telegram identity are attached later.

The ownership map allows the backend to resolve:

- stable `account_ref` → MGW-ID;
- legacy user → MGW-ID;
- Telegram/development provider subject → the same MGW-ID;
- active ownership status and verification time.

## Safety properties

- one canonical account reference per MGW user;
- one ownership row per legacy user;
- one ownership row per provider identity;
- foreign key to `mgw_users`;
- no write to JSON;
- no update to balances or immutable ledger entries;
- no authentication or storage cutover;
- no change to Match/Gold rules, games, payments, shop, `/app` or `/site`.

The schema is expand-only. The next controlled substep will populate it and attach the real provider identity atomically in the test environment.
