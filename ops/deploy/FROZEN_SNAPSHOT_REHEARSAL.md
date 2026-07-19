# MVP-14.8.4b — frozen snapshot rehearsal

Staging only. Production is unchanged and JSON remains the global and rollback driver.

## Order

1. Freeze new matches and invitations:
   `php ops/deploy/freeze-drain-rehearsal.php --freeze`
2. Confirm active games, queue, invitations and searching users are zero.
3. Seal JSON writes:
   `php ops/deploy/sealed-snapshot-control.php --seal`
4. Create and verify primary plus external backup, run final reconciliation and restore into an isolated private target:
   `php ops/deploy/frozen-snapshot-rehearsal.php --prepare`
5. Release normal staging writes:
   `php ops/deploy/sealed-snapshot-control.php --release --reason="staging rehearsal completed"`

## Required evidence

- matching primary/external backup ID and SHA-256;
- matching staging environment and build;
- matching primary/external/restored JSON fingerprint;
- temporary restore target removed;
- clean final reconciliation with no blockers or migration gaps;
- production switch not performed;
- production not changed.

A DB switch remains blocked while `economy`, `history`, `shop`, `payments` and `weekly_bonus` runtime modules are missing.

After any failure during the sealed stage, run the release command before continuing.
