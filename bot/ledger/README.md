# Ledger foundation

This module is the inactive schema foundation for balances, immutable ledger entries, reservations and idempotency.

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

## Immutability

`mgw_ledger_entries` and `mgw_reservation_events` are append-only. Database triggers reject updates and deletes. Corrections must be represented by compensating entries, never by editing history.

Reservations may change status, but they cannot be deleted. They must finish as released or consumed so the audit trail remains complete.

## Idempotency

Every future money-changing operation must use a stable operation key and request hash. Reusing the same key with different input must fail closed. Runtime services and reconciliation are separate later steps.

## Not included yet

- JSON balance import;
- dual-write or DB read path;
- settlement changes;
- combining Match and Gold;
- production migration;
- shop, payment or UI changes.
