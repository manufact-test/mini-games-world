# MVP-14.8.6h — evidence-bound staging DB-primary activation guard

This stacked sub-MVP does **not** switch the application. It adds a read-only readiness gate that proves whether one exact staging checkout, one exact staging database and one fresh private evidence file are still safe candidates for a later DB-primary entrypoint rehearsal.

## Safety boundary

- staging only;
- API and webhook entrypoints remain JSON-first;
- no runtime routing changes;
- no Cron changes;
- no schema installation;
- no JSON or DB mutation;
- no production access or production cutover;
- no automatic activation;
- the inspector accepts no arguments and returns only non-sensitive fingerprints/status.

## Evidence v2

Activation accepts only `v2-staging-db-primary-evidence`. V2 preserves every v1 rehearsal requirement and additionally binds the manifest to the exact non-sensitive `DatabaseConfig::identityFingerprint()`.

A v1 manifest cannot authorize staging activation.

## Read-only all-module audit

`RuntimePrimaryAllModuleProjector::auditOnly()`:

- calls no module `project()` method;
- audits all nine modules exactly once;
- requires exact state revision and state SHA;
- requires every audit to identify itself as read-only;
- requires matching source/database fingerprints and zero blockers;
- returns one deterministic all-module fingerprint.

## Activation approval

Add only to the external private staging config after a fresh v2 evidence file exists:

```php
'runtime_primary_projection_outbox' => [
    'enabled' => true,
],

'staging_db_primary_activation' => [
    'enabled' => true,
    'expected_database_identity_fingerprint' => '<exact staging DB fingerprint>',
    'expected_repository_commit' => '<exact 40-character commit>',
    'evidence_file' => '/absolute/private/path/staging-db-primary-evidence-v2.json',
    'expected_evidence_fingerprint' => '<exact canonical v2 evidence fingerprint>',
    'approval_expires_at_utc' => '<ISO-8601 timestamp no more than 30 minutes ahead>',
],
```

The evidence file must:

- be in the same verified external private directory as `config.php`;
- be a regular non-symlink file;
- have exact `0600` permissions;
- be no larger than 512 KiB;
- contain a JSON object;
- pass the current-checkout v2 gate.

Disable the activation approval immediately after inspection or any failed attempt.

## Live readiness checks

The guard verifies all of the following without mutation:

1. environment is exactly staging;
2. active config is external to the checkout;
3. projection outbox is explicitly boolean `true`;
4. short-lived approval matches exact DB identity and commit;
5. evidence file fingerprint matches approval;
6. v2 manifest matches current checkout and current API/webhook sources;
7. evidence is no older than six hours;
8. current JSON SHA and inventory match evidence;
9. state/outbox schemas match exact evidence fingerprints and use InnoDB;
10. current DB-primary state revision/SHA match the evidenced target;
11. target outbox event is cleanly completed, has no lease/error and uses the exact projection version;
12. outbox is a contiguous completed chain from revision 1 to target with no pending/processing/failed events;
13. read-only parity audit passes for all nine normalized modules;
14. JSON, state, target event and queue remain unchanged after the audit.

## Read-only inspector

```bash
php ops/runtime/inspect-staging-db-primary-activation.php
```

Possible successful result:

- `action: staging_activation_ready`;
- `activation_allowed: true`;
- exact commit/DB/evidence/state fingerprints;
- nine projected modules;
- `read_only_audit: true`;
- `drift_check_passed: true`;
- `application_entrypoints_changed: false`;
- `cron_changed: false`;
- `production_changed: false`.

A failed check returns `staging_activation_blocked`; it does not switch or repair anything.

## Focused verification

```bash
bash ops/checks/db-primary-staging-activation-local.sh
```

The focused suite includes every prior collector/verifier test plus evidence v2, database identity binding, audit-only behavior, exact live schema fingerprints, strict activation approval, private evidence loading, live state/outbox/queue checks, drift detection and CLI non-mutation contracts.

## Next prerequisite

Only after a real v2 evidence file and a zero-blocker readiness report may a later sub-MVP add a **staging-only** storage resolver for a controlled entrypoint rehearsal. This PR deliberately does not modify `bot/api.php` or `WebhookHandler.php`.
