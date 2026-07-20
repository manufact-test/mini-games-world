# MVP-14.8.6g — automated private staging evidence collection

This stacked sub-MVP automates the complete evidence package required by MVP-14.8.6f. It remains staging-only, explicit and inactive until an operator runs the CLI.

## Hard safety boundary

- collector is CLI-only;
- environment must be exactly `staging`;
- output path is validated before application bootstrap or DB connection;
- evidence file must be outside the deployed project;
- existing files are never overwritten;
- output is written atomically with permissions `0600`;
- the same private rehearsal lock blocks concurrent manual rehearsal and collection;
- JSON rollback storage is read-only;
- two independent MySQL connections are used only for lease evidence;
- concurrency probes use a dedicated probe table and temporary row, never real outbox events;
- API, webhook, private runtime routing and Cron are not changed;
- stdout omits the private output path and the manifest payload.

Deploying this code performs nothing.

## Collection command

```bash
MGW_REHEARSAL_COMMIT_SHA=<exact-40-character-commit> \
php ops/runtime/collect-staging-db-primary-evidence.php \
  --output=/absolute/private/path/staging-db-primary-evidence.json \
  --max-events=20
```

`MGW_REHEARSAL_COMMIT_SHA` is optional when checkout `.git` metadata is available. `--max-events` defaults to 20 and accepts 1–100.

The output file must not already exist. Preserve every evidence file as an immutable record rather than overwriting a prior run.

## Automated sequence

1. Validate the external private output path.
2. Bootstrap the staging application configuration.
3. Reject every environment except `staging`.
4. Acquire `runtime-primary-rehearsal.lock` for the complete collection.
5. Capture canonical JSON SHA and non-sensitive inventory fingerprint.
6. Run the bounded end-to-end DB-primary rehearsal.
7. Capture JSON evidence again and prove no change.
8. Run the same rehearsal a second time.
9. Capture JSON evidence a third time and prove the same SHA and inventory.
10. Require the first run to prove all nine projected modules.
11. Require the second run to target the same completed revision with zero worker ticks.
12. Read live PHP, MySQL/MariaDB and schema evidence.
13. Recompute current API/webhook entrypoint evidence.
14. Run isolated CLI-lock and worker-lease probes.
15. Build the exact versioned manifest.
16. Verify it in memory against the current checkout.
17. Write it atomically to a temporary private file with `0600`.
18. Rename it to the requested output only after byte verification.
19. Read the stored file back and run the strict gate again.
20. Remove the output if post-write verification fails.

## JSON evidence

`RuntimePrimaryJsonEvidence` returns only:

- canonical snapshot SHA-256;
- counts for users, games, queue, invites, notifications, transactions, shop orders and payments;
- one inventory fingerprint;
- explicit non-production/non-sensitive flags.

The manifest stores only SHA and inventory fingerprint, not the counts or snapshot payload.

## Concurrency probes

### CLI lock

The probe opens two independent handles to a dedicated private lock file while the first handle holds `LOCK_EX | LOCK_NB`. The second handle must fail, mapping to:

- `second_exit_code = 2`;
- `second_result = rehearsal_lock_blocked`.

The probe lock file is removed after the test.

### Worker lease

A dedicated staging-only InnoDB table `mgw_runtime_primary_lease_probe` is used. The probe:

1. inserts a unique pending row;
2. claims it through the first MySQL connection under `FOR UPDATE`;
3. reads the active unexpired lease through the second connection;
4. proves a second `pending` conditional update affects zero rows;
5. reports `projection_busy` for the same revision;
6. deletes the temporary row in `finally`.

It does not modify `mgw_runtime_primary_projection_outbox` or any application table. The reusable probe table may remain on isolated staging.

## Atomic writer

`RuntimePrimaryStagingEvidenceWriter`:

- rejects relative paths, symlinks and deployment-local destinations;
- rejects missing/unwritable parent directories;
- refuses overwrite;
- limits JSON to 512 KiB;
- uses a random exclusive temporary file;
- sets `0600` before writing;
- loops until all bytes are written;
- flushes and fsyncs when available;
- verifies temporary bytes before rename;
- verifies final bytes and permissions after rename;
- removes temporary or unverified output files on failure.

## Success output

The CLI prints only non-sensitive metadata:

- repository commit;
- state revision and SHA;
- nine module names;
- manifest/evidence file fingerprints;
- byte count and `0600` permissions;
- explicit unchanged entrypoint/Cron/production flags.

It does not print the manifest or private output path.

## Focused verification

```bash
bash ops/checks/db-primary-staging-evidence-collector-local.sh
```

This runs every previous DB-primary focused suite and then verifies:

- canonical read-only JSON evidence;
- real lock and shared-store lease behavior;
- stable JSON/inventory across two rehearsals;
- exact nine-module normalization;
- missing safety flag rejection;
- repeated-write and weak-concurrency rejection;
- atomic writer permissions/overwrite/symlink/path/size behavior;
- real staging source contract;
- collector CLI load, lock, DB connection and output-safety contract.

## Next prerequisite

Run the collector on an isolated PHP 8.3 + staging MySQL environment. This is a manual operational checkpoint because it creates staging-only state/outbox/probe tables and processes the full staged snapshot. Record the CLI output and preserve the private manifest.

After the manifest passes with zero blockers, the next code sub-MVP may add a disabled `ProductionPrimaryRuntimeCoordinator` and route only a dedicated staging test entrypoint through it. The real application API and webhook must remain JSON-first until that coordinator passes behavior, rollback and concurrency tests.
