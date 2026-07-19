# MVP-14.8.4c — sealed final delta reconciliation

This staging-only step catches the database up to one sealed JSON snapshot without changing the global runtime driver.

## Safety contract

- staging environment only;
- global and rollback driver remain `json`;
- the private JSON write barrier is required for every database delta;
- one shared private `cutover-rehearsal.lock` protects the complete one-shot sequence;
- completed and failed one-shot states do not execute again on the next Cron tick;
- unexpected failures attempt an emergency release automatically;
- production routing and production data are not changed.

## Recommended one-shot rehearsal

```bash
/usr/bin/php ops/deploy/final-delta-one-shot.php --run
```

One short command performs:

1. freeze and queue cleanup;
2. drain verification;
3. sealed JSON write barrier;
4. exact realtime and economy shadow catch-up;
5. append-only legacy financial archive catch-up;
6. immutable economy balance delta entries through `LedgerWriteService`;
7. final JSON-to-database reconciliation and count parity verification;
8. primary/external frozen backup verification;
9. isolated exact restore rehearsal and cleanup;
10. safe release and final control consistency check.

When an active game is still running, the command persists `waiting_for_drain` and the next five-minute Cron tick resumes the same rehearsal. After `completed`, later ticks return an idempotent no-op instead of starting another freeze. After `failed`, later ticks also remain no-op until an explicit `--retry`.

## Low-level preview

```bash
/usr/bin/php ops/deploy/final-delta-reconciliation.php --preview
```

The preview reads current JSON and database state but does not write either storage. It fails readiness when archive or economy safety preconditions are not satisfied.

## Low-level run

```bash
/usr/bin/php ops/deploy/final-delta-reconciliation.php --run
```

Use the low-level command only inside an already frozen, drained and sealed staging snapshot.

A completed financial archive may advance to a new source fingerprint only when every previously archived source position remains hash-identical, every new row is append-only, and no unmanaged or conflicting row exists.

Economy balances are never overwritten directly. Each difference from the frozen JSON balance becomes a separate immutable `legacy_cutover_delta` ledger entry. The operation key includes the current ledger balance version, so retries are idempotent while later returns to an earlier balance state remain valid new transitions.

## Failure handling

The one-shot command automatically attempts emergency release, records the failed step privately and blocks automatic retries. Use `--retry` only after the reported failure has been inspected. The low-level commands remain available for targeted diagnostics.
