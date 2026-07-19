# Final delta rehearsal checklist

1. Deploy the reviewed staging commit.
2. Create one temporary five-minute Cron for `final-delta-one-shot.php --run`.
3. Inspect one output:
   - `state=completed` and `complete=true` means the full sequence passed and the write barrier was released;
   - `state=waiting_for_drain` means leave the same Cron until the next tick;
   - `state=failed` means the command attempted emergency release and will not retry automatically.
4. Delete the temporary Cron after `completed` or `failed`.
5. Permanent backup and managed-migration Cron jobs are not changed.
