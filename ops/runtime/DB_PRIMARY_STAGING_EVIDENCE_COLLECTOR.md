# MVP-14.8.6g/h — automated private staging evidence v2 collection

This stacked collector automates the complete staging evidence package and, on the activation-guard branch, emits `v2-staging-db-primary-evidence`. V2 preserves the original rehearsal contract and additionally binds the manifest to the exact non-sensitive staging database identity fingerprint.

## Hard safety boundary

- collector is CLI-only;
- environment must be exactly `staging`;
- active `config.php` must be in an external private directory outside the checkout;
- output path is validated before application bootstrap or DB connection;
- evidence file must be outside the deployed project;
- existing files are never overwritten;
- final publication uses an atomic no-clobber hard link with permissions `0600`;
- the same private rehearsal lock blocks concurrent manual rehearsal and collection;
- exact short-lived evidence approval is checked before lock or DB connection;
- approval is bound to exact staging DB identity and exact commit;
- JSON rollback storage is read-only;
- two independent MySQL connections are used only for lease evidence;
- concurrency probes use a dedicated probe table and temporary row, never real outbox events;
- temporary probe-row cleanup must delete exactly one row or the collection fails;
- API, webhook, private runtime routing and Cron are not changed;
- stdout omits the private output path and the manifest payload.

Deploying this code performs nothing.

## Read-only target inspection

```bash
php ops/runtime/inspect-staging-db-primary-evidence-target.php
```

The inspector opens no MySQL connection. It returns only the exact checkout commit, DB identity fingerprint, external-config fingerprint and safe approval summary.

## Private collection approval

Only in the external private staging config:

```php
'staging_db_primary_evidence' => [
    'enabled' => true,
    'expected_database_identity_fingerprint' => '<exact fingerprint from inspector>',
    'expected_repository_commit' => '<exact commit from inspector>',
    'approval_expires_at_utc' => '<ISO-8601 timestamp no more than 2 hours ahead>',
],
```

Disable this approval immediately after collection or any failed attempt.

## Collection command

```bash
MGW_REHEARSAL_COMMIT_SHA=<exact-40-character-commit> \
php ops/runtime/collect-staging-db-primary-evidence.php \
  --output=/absolute/private/path/staging-db-primary-evidence-v2.json \
  --max-events=20
```

`MGW_REHEARSAL_COMMIT_SHA` is optional when checkout `.git` metadata is available. `--max-events` defaults to 20 and accepts 1–100.

The output file must not already exist. Preserve every evidence file as an immutable record rather than overwriting a prior run.

## Automated sequence

1. Validate the external private output path.
2. Bootstrap the staging application configuration.
3. Reject every environment except `staging`.
4. Require external private config.
5. Validate short-lived approval against exact DB identity and checkout commit.
6. Acquire `runtime-primary-rehearsal.lock` for the complete collection.
7. Capture canonical JSON SHA and non-sensitive inventory fingerprint.
8. Run the bounded end-to-end DB-primary rehearsal.
9. Capture JSON evidence again and prove no change.
10. Run the same rehearsal a second time.
11. Capture JSON evidence a third time and prove the same SHA and inventory.
12. Require the first run to prove all nine projected modules.
13. Require the second run to target the same completed revision with zero worker ticks.
14. Read live PHP, MySQL/MariaDB, exact DB identity and schema evidence.
15. Recompute current API/webhook entrypoint evidence.
16. Run isolated CLI-lock and worker-lease probes.
17. Build the exact versioned v2 manifest.
18. Verify it in memory against the current checkout.
19. Write and verify a random private temporary file with `0600`.
20. Publish through atomic no-clobber `link()` only if the final path still does not exist.
21. Remove the temporary hard link.
22. Read the stored file back and run the strict gate again.
23. Remove the output if post-write verification fails.

## Evidence v2 database binding

The manifest database section contains only:

- `driver`;
- `server_version`;
- `identity_fingerprint`;
- `state_engine`;
- `outbox_engine`.

The identity fingerprint is derived from host, port and database name through `DatabaseConfig::identityFingerprint()`. Password and username are not stored in the manifest.

A v1 evidence file cannot authorize the staging activation guard.

## JSON evidence

`RuntimePrimaryJsonEvidence` returns only:

- canonical snapshot SHA-256;
- counts for users, games, queue, invites, notifications, transactions, shop orders and payments;
- one inventory fingerprint;
- explicit non-production/non-sensitive flags.

The manifest stores only SHA and inventory fingerprint, not the counts or snapshot payload.

## Concurrency probes

### CLI lock

The probe opens two independent handles to a dedicated private lock file while the first handle holds `LOCK_EX | LOCK_NB`. The second handle must fail with exit-code evidence `2` and result `rehearsal_lock_blocked`.

The probe lock file is removed after the test; cleanup failure invalidates collection.

### Worker lease

A dedicated staging-only InnoDB table `mgw_runtime_primary_lease_probe` is used. The probe:

1. inserts a unique pending row;
2. claims it through the first MySQL connection under `FOR UPDATE`;
3. reads the active unexpired lease through the second connection;
4. proves a second `pending` conditional update affects zero rows;
5. reports `projection_busy` for the same revision;
6. deletes the temporary row in `finally`;
7. requires the DELETE to affect exactly one row.

It does not modify `mgw_runtime_primary_projection_outbox` or any application table. The reusable probe table may remain on isolated staging.

## Atomic writer

`RuntimePrimaryStagingEvidenceWriter`:

- rejects relative, non-canonical, symlink and deployment-local destinations;
- rejects missing/unwritable parent directories;
- refuses overwrite;
- limits JSON to 512 KiB;
- uses a random exclusive temporary file;
- sets `0600` before writing;
- loops until all bytes are written;
- flushes and fsyncs when available;
- verifies temporary bytes;
- publishes through no-clobber hard link, never overwrite-capable rename;
- verifies final bytes and permissions;
- removes temporary or unverified output files on failure.

## Success output

The CLI prints only non-sensitive metadata:

- repository commit;
- state revision and SHA;
- nine module names;
- manifest/evidence file fingerprints;
- byte count, `0600` permissions and `atomic_no_clobber_link` publish mode;
- explicit external-config and unchanged entrypoint/Cron/production flags.

It does not print the manifest or private output path.

## Focused verification

```bash
bash ops/checks/db-primary-staging-activation-local.sh
```

This runs every previous collector/verifier suite and adds database-bound v2 verification, read-only all-module audit, live schema fingerprint parity, strict activation approval and post-audit drift checks.

## Next prerequisite

After a real v2 evidence file passes with zero blockers, run the read-only staging activation inspector described in `DB_PRIMARY_STAGING_ACTIVATION.md`. API and webhook remain JSON-first until a later dedicated staging entrypoint resolver is separately implemented and reviewed.
