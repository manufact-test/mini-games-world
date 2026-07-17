# Controlled legacy financial archive import

This command copies the current JSON payment, shop-order and related financial transaction history into the inactive database archive created by migration `0006`.

It does **not** switch runtime reads or writes away from JSON and does not replay historical balance movements.

## Imported records

- every record from `payments.json`;
- every record from `shop_orders.json`, including its embedded prize snapshot;
- only records from `transactions.json` that are linked to a payment/order or use a known payment/shop category.

Unrelated match settlement, bonuses, fees and game operations remain outside this archive.

## Preserved data

Every archived row keeps:

- canonical source snapshot and SHA-256;
- original source file and array index;
- one archive batch fingerprint;
- raw status plus normalized query status;
- original payment/order/transaction ID, or a deterministic synthetic ID when the source ID is missing or unsafe;
- Telegram legacy user ID and mapped MGW-ID when available;
- separate `match_coin` and `gold_coin` asset identity.

## Commands

```bash
php ops/ledger/legacy-financial-archive-import.php --status
php ops/ledger/legacy-financial-archive-import.php --dry-run
php ops/ledger/legacy-financial-archive-import.php --run
```

`--status` and `--dry-run` only inspect the source and target.

## Required test sequence

1. Run `--dry-run`; require `ready=true`, zero conflicts and zero unmanaged rows.
2. Run `--run`; require verification `ok=true` and database counts equal expected archive counts.
3. Run `--run` again; require all created counts to be zero and all rows to be unchanged.
4. Remove the temporary Cron used for these three commands.

## Fail-closed rules

The import is blocked when:

- source records are malformed or duplicate an archive ID;
- a completed import has a different source fingerprint;
- an existing archive row differs from the source snapshot;
- archive tables contain rows outside the current plan;
- source data changes while the import is running;
- final row counts or hashes do not match.

Unknown legacy statuses are preserved and normalized to `unknown`; they are never guessed or discarded.

## Production guard

Production requires both:

1. private config `legacy_financial_archive_import_allow_production => true`;
2. CLI flag `--allow-production`.

Do not enable production during MVP-14.6.6. Test only in the isolated test environment.

## Not included

- deleting or editing live JSON history;
- runtime database reads for payments or shop orders;
- replaying old transactions into balances or ledger;
- changing payment approval, shop fulfillment, settlement or refunds;
- changing `/app`, `/site`, secrets, tokens or admin IDs.
