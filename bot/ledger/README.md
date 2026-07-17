# Ledger foundation

This module is the inactive schema foundation for balances, append-only ledger entries, reservations and idempotency.

## Current authority

The running product still uses the existing JSON balances and transactions. None of the tables created by migration `0005` are connected to gameplay, settlement, shop, payments, bonuses or history yet.

## Assets remain separate

Legacy balances must be imported without changing their amounts:

- `match_coin` represents the current Match balance;
- `gold_coin` represents the current Gold balance.

They must not be merged until a later migration rule is explicitly approved and tested.

## Account references

Rows support both:

- `mgw:<MGW-ID>` with optional `mgw_id` foreign key;
- `legacy:<current user ID>` with `legacy_user_id` preserved for reconciliation.

## Append-only contract

`mgw_ledger_entries` and `mgw_reservation_events` intentionally have creation timestamps but no update timestamps. Each row stores an integrity SHA-256 value; ledger rows can also point to the previous integrity hash for later chain verification.

The later ledger write service will be the only application path allowed to insert these rows and will expose no update/delete methods. Corrections must be represented by compensating entries, never by editing history. This schema step does not install database triggers because shared MySQL/MariaDB environments with binary logging commonly reject trigger creation for non-privileged application users.

Reservations may change status. Once they have linked ledger/events, foreign-key restrictions preserve their audit relationship; the future service must finish them as released or consumed rather than deleting them.

## Idempotency

Every future money-changing operation must use a stable operation key and request hash. Reusing the same key with different input must fail closed. Runtime services and reconciliation are separate later steps.

## Not included yet

- JSON balance import;
- dual-write or DB read path;
- ledger write service and hash-chain verification;
- settlement changes;
- combining Match and Gold;
- production migration;
- shop, payment or UI changes.
