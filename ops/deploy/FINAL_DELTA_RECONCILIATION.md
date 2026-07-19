# MVP-14.8.4c — sealed final delta reconciliation

This staging-only step catches the database up to one sealed JSON snapshot without changing the global runtime driver.

## Safety contract

- staging environment only;
- global and rollback driver remain `json`;
- freeze must be active, drained and sealed;
- the private JSON write-block marker must be active and consistent;
- the shared private `cutover-rehearsal.lock` prevents overlapping rehearsal commands;
- production routing and production data are not changed.

## Preview

```bash
/usr/bin/php ops/deploy/final-delta-reconciliation.php --preview
```

The preview reads current JSON and database state but does not write either storage.

## Run

```bash
/usr/bin/php ops/deploy/final-delta-reconciliation.php --run
```

The run performs, in order:

1. exact realtime shadow catch-up;
2. exact economy shadow catch-up;
3. append-only legacy financial archive catch-up;
4. immutable economy balance delta entries through `LedgerWriteService`;
5. final JSON-to-database reconciliation and count parity verification.

A completed financial archive may advance to a new source fingerprint only when all previously archived source positions remain hash-identical, every new row is append-only, and no unmanaged/conflicting rows exist.

Economy balances are never overwritten directly. Each difference from the frozen JSON balance becomes a separate idempotent `legacy_cutover_delta` ledger entry. Repeated execution does not duplicate archive rows or ledger entries.

## Failure handling

Do not release the sealed snapshot until the failure is inspected. The command fails closed on changed historical archive rows, ownership mismatch, active reservations, invalid hashes, unmanaged balances, ledger integrity errors, migration gaps or incomplete final parity.

To end an interrupted rehearsal after inspection:

```bash
/usr/bin/php ops/deploy/sealed-snapshot-control.php --emergency-release --reason="recover interrupted final delta rehearsal"
```
