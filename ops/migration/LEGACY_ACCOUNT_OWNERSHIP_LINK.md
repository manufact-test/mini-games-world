# Controlled legacy account ownership and provider linking

MVP-14.7.3b atomically connects each verified `legacy:<legacy_user_id>` economy account to the provider-neutral MGW user created by the previous account import. In the same transaction it attaches the real `telegram` or `development` identity to that same MGW-ID.

The stable legacy account reference is preserved. Match/Gold balances and immutable ledger rows are verified but never rewritten.

## Commands

```bash
php ops/migration/legacy-account-ownership-link.php --status
php ops/migration/legacy-account-ownership-link.php --dry-run
php ops/migration/legacy-account-ownership-link.php --run
```

`--status` and `--dry-run` are read-only.

## Prerequisites

- all managed database migrations are applied;
- verified economy shadow is current;
- opening balance import is completed;
- provider-neutral legacy account import is completed;
- every source user has exactly two separate Match/Gold balance rows;
- every existing ledger chain passes integrity verification.

## Atomic write

For each verified source user one database transaction:

1. locks and verifies the internal `legacy_import` identity;
2. verifies the MGW user, exact Match/Gold balances and ledger chains;
3. creates `mgw_account_ownership` for `legacy:<id>` when missing;
4. creates the real `telegram` or `development` identity when missing;
5. verifies both mappings point to the same MGW-ID before commit.

A provider identity already owned by another MGW-ID, a conflicting ownership row, source drift, balance mismatch or ledger-integrity error blocks the run before target mutation.

## Verification sequence

1. Dry-run: `ready=true`, one planned ownership and provider identity per unlinked user, zero conflicts and unmanaged ownership rows.
2. First run: expected ownership/provider rows are created and final verification is `ok=true`.
3. Repeat run: creates nothing, reuses provider identities and reports every user unchanged.
4. Confirm balances and ledger rows are byte-for-byte unchanged and Match/Gold chains remain valid.
5. Confirm no device or session rows were created.
6. Delete only the temporary ownership-link Cron.

## Safety

- JSON remains authoritative and is never written;
- `legacy_import` identity remains as migration provenance;
- stable `legacy:<id>` account references remain unchanged;
- no balance, reservation, ledger, payment, shop, game, `/app` or `/site` write occurs;
- no sessions or devices are created;
- live authentication and storage adapters are not switched;
- production requires private approval plus `--allow-production`.
