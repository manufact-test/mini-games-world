# Staging JSON to DB reconciliation

This is the read-only baseline report for MVP-14.7.

It proves that the already completed shadow, opening-balance and legacy-financial imports still match the current JSON source before normalized account/realtime import begins.

## Command

```bash
php ops/migration/staging-json-db-reconciliation.php
```

The command is CLI-only and is blocked in production.

## Reported areas

- legacy users and provider-to-MGW identity mapping;
- separate Match and Gold opening balances;
- games, queue, invites and notifications;
- payment/order archive and related financial transactions;
- current normalized DB row counts;
- source fingerprints, shadow drift, conflicts and unmanaged rows.

## Meaning of the main fields

- `ready_for_next_import_step=true` — current shadows/imports are consistent and the normalized importer can be developed/tested;
- `count_parity_complete=false` — expected before users/realtime entities are imported into their final normalized tables;
- `blocking_reasons` — source/shadow/archive conflicts that must be fixed before continuing;
- `migration_gaps` — expected source-vs-normalized-table gaps that the next MVP-14.7 substeps must close.

## Safety

- no JSON file is written;
- no database row is inserted, updated or deleted;
- no runtime storage adapter is switched;
- no production execution;
- no change to Match/Gold rules, games, payments, shop, `/app` or `/site`.

A repeated report on an unchanged test environment must return the same `report_fingerprint`.
