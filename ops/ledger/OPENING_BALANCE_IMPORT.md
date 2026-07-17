# Controlled legacy opening balance import

This step converts the already verified `economy_user_balance` shadow rows into inactive database balances and opening ledger entries.

It does **not** switch runtime reads or writes away from JSON.

## Result

For every legacy user:

- `balance_match` becomes a separate `match_coin` balance;
- `balance_gold` becomes a separate `gold_coin` balance;
- non-zero amounts receive one immutable `legacy_opening_balance` ledger entry;
- zero amounts receive an explicit zero balance row without a fake ledger movement;
- Telegram identities use `mgw:<MGW-ID>` when already mapped;
- otherwise the balance remains under `legacy:<legacy-user-id>` for later identity reconciliation.

## Commands

```bash
php ops/ledger/opening-balance-import.php --status
php ops/ledger/opening-balance-import.php --dry-run
php ops/ledger/opening-balance-import.php --run
```

`--status` and `--dry-run` only inspect the plan.

## Fail-closed rules

The import is blocked when:

- the economy shadow is missing or has a bad hash;
- a completed import has a different source fingerprint;
- an existing balance or opening entry differs from the source;
- balances or ledger entries exist outside the opening import plan;
- source data changes while the import is running;
- final totals or ledger integrity do not match.

Stable operation keys make a repeated identical run a no-op/replay. A changed amount cannot silently create a second opening entry.

## Production guard

Production requires both:

1. private config `legacy_opening_balance_import_allow_production => true`;
2. CLI flag `--allow-production`.

Do not enable production in MVP-14.6.4. First verify dry-run, run and repeat-run in the isolated test environment.

## Not included

- runtime DB balance reads;
- dual-write from live settlement;
- merging Match and Gold;
- importing legacy transaction rows as new economic movements;
- changing shop, payments, bonuses, `/app` or `/site`.

Legacy transactions remain preserved in the exact hashed shadow archive and are not replayed, because replaying historical movements on top of an opening balance would double-count the economy.
