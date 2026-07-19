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
6. Confirm release:
   `php ops/deploy/sealed-snapshot-control.php --status`

All three rehearsal CLIs use the same private `cutover-rehearsal.lock`, so freeze, seal, snapshot and release commands cannot overlap.

## Required evidence

- matching primary/external backup ID and SHA-256;
- matching staging environment and build;
- matching primary/external/restored JSON fingerprint;
- temporary restore target removed, including after a failed fingerprint check;
- clean final reconciliation with no blockers or migration gaps;
- `control_consistency.ok: true`;
- production switch not performed;
- production not changed.

A DB switch remains blocked while `economy`, `history`, `shop`, `payments` and `weekly_bonus` runtime modules are missing.

## Recovery

After any failure during the sealed stage, run the normal release command before continuing.

If the control file is invalid or the normal release cannot clear a stale `.cutover-write-block`, run:

`php ops/deploy/sealed-snapshot-control.php --emergency-release --reason="recover interrupted staging rehearsal"`

Emergency release removes the JSON write barrier first, then repairs the private control state as `released`. It is staging-only and never changes production. The legacy command `freeze-drain-rehearsal.php --release` is also routed through this safe release path when a sealed marker is present.
