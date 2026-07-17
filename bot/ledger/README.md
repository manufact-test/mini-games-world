# Ledger foundation

This module contains the inactive database foundation and write contract for balances, append-only ledger entries, reservations and idempotency.

## Current authority

The running product still uses the existing JSON balances and transactions. The ledger services are not wired into gameplay, settlement, shop, payments, bonuses or history yet.

## Assets remain separate

Legacy balances must be imported without changing their amounts:

- `match_coin` represents the current Match balance;
- `gold_coin` represents the current Gold balance.

They must not be merged until a later migration rule is explicitly approved and tested.

## Account references

Rows support both:

- `mgw:<MGW-ID>` with the linked `mgw_id` foreign key;
- `legacy:<current user ID>` with `legacy_user_id` preserved for reconciliation.

## Transactional write service

`LedgerWriteService` provides the only intended application write path:

- `postAvailableDelta()` for an idempotent credit/debit of available funds;
- `createReservation()` to atomically move available funds into reserved funds;
- `releaseReservation()` to return reserved funds;
- `consumeReservation()` to finalize reserved spending;
- `getBalance()` for a scoped balance read.

Each mutation:

1. claims a stable operation key and canonical request hash;
2. locks the relevant InnoDB balance row;
3. rejects negative available or reserved balances;
4. appends a ledger entry and reservation event where applicable;
5. updates the balance with an optimistic version guard;
6. stores the completed result for safe replay.

Reusing the same operation key with the same input returns the original result. Reusing it with different input fails closed.

## Append-only integrity

`mgw_ledger_entries` and `mgw_reservation_events` have creation timestamps but no update timestamps. Each row stores an integrity SHA-256 value. Ledger rows also point to the previous entry hash for the same account and asset.

`LedgerIntegrityVerifier` checks:

- entry and event hashes;
- previous-hash linkage;
- before/delta/after arithmetic;
- continuity between entries;
- non-negative states;
- agreement between the ledger head and current balance.

The service exposes no update/delete method for ledger history. Corrections must be represented by compensating entries. Database triggers are intentionally not required because restricted shared MySQL/MariaDB users may not have trigger privileges.

## Reservations

Reservations can finish only as released or consumed through the service. Linked ledger entries and reservation events preserve the audit relationship.

## Not included yet

- wiring current JSON economy into the ledger service;
- JSON balance/transaction import;
- dual-write or DB read cutover;
- automated reconciliation job and alerts;
- settlement changes;
- combining Match and Gold;
- production migration;
- shop, payment or UI changes.
