# Legacy economy shadow sync

This command copies the current JSON economy source into the existing server-only shadow table without changing live balances or runtime reads/writes.

It stores two exact, hashed entity groups:

- `economy_user_balance` — legacy user ID, separate Match/Gold balances and source-record hash;
- `economy_transaction` — canonical JSON for every legacy transaction.

The command also reports identity mapping and compares current JSON balances with `mgw_balances`. A mismatch is expected until a later controlled ledger import step.

## Commands

```bash
php ops/ledger/economy-shadow-sync.php --status
php ops/ledger/economy-shadow-sync.php --dry-run
php ops/ledger/economy-shadow-sync.php --run
```

`--status` and `--dry-run` never write. `--run` updates only economy entity types inside `mgw_legacy_realtime_shadow`; realtime shadow entities are not touched.

## Production guard

Production requires both:

1. private config `legacy_economy_shadow_allow_production => true`;
2. CLI flag `--allow-production`.

Do not enable production during MVP-14.6.3. The first run is for the isolated test environment only.

## Safety

- JSON remains authoritative;
- no ledger entry or balance is created;
- Match and Gold stay separate;
- negative or malformed source balances fail closed;
- payload/hash corruption is detected and repaired from the locked JSON snapshot;
- removed source records are pruned only from their shadow copy;
- one private lock prevents overlapping runs.
