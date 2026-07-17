# Legacy financial archive

This module preserves the outgoing manual payment and certificate/prize-order history before the new economy replaces those flows.

## Current authority

The running product still uses `payments.json`, `shop_orders.json` and `transactions.json`. Migration `0006` only creates inactive archive tables. It does not switch reads or writes and does not disable any legacy flow yet.

## Tables

- `mgw_legacy_payments` — exact payment request snapshots plus normalized status fields for admin history and disputes;
- `mgw_legacy_shop_orders` — exact certificate/prize-order snapshots, including the embedded prize snapshot;
- `mgw_legacy_financial_transactions` — only transactions related to legacy payments and shop orders.

Every row keeps:

- the untouched source snapshot;
- SHA-256 of that snapshot;
- source file and source index;
- archive batch fingerprint;
- raw legacy status and a separate normalized status;
- Match and Gold asset identity without merging balances.

## Safety

- archive tables are append-only by design and have no mutable `updated_at_utc` field;
- exact source positions are unique, so a repeated import cannot silently duplicate rows;
- unknown legacy statuses remain preserved as raw text and normalize to `unknown` later;
- source links are preserved even when an old transaction is orphaned;
- runtime payment, shop, settlement and balance code remains unchanged;
- no production import is part of this schema step;
- later admin access must be read-only: no edit or delete endpoint for archive rows.

## Rollback

Before any archive import, rollback is deploying the previous release; the unused expand-only tables may remain. After an archive import, rows must not be edited or deleted. A retry uses a clean test database or a restored database backup.

## Next step

A separate importer will read the three JSON files, normalize statuses, store only related financial transactions, compare counts and hashes, and support dry-run/repeat-run verification in the isolated test environment.
