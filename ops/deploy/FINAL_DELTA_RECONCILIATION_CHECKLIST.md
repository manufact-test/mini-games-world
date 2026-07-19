# Final delta rehearsal checklist

1. Confirm staging is frozen, drained and sealed.
2. Run `final-delta-reconciliation.php --preview`.
3. Run `final-delta-reconciliation.php --run` once.
4. Repeat `--preview`; expected shadow deltas, economy deltas, blockers and migration gaps are all zero.
5. Re-run `frozen-snapshot-rehearsal.php --prepare` to verify backup/restore fingerprints and clean final reconciliation.
6. Release the sealed snapshot and verify control consistency.
7. Delete the temporary Cron. Permanent backup and migration Cron jobs are not changed.
