# MVP-14.8.6r — read-only staging API smoke evidence verifier

This independent stacked sub-MVP verifies the JSON report produced by MVP-14.8.6n.

It does not execute the smoke, connect to MySQL or enable DB-primary routing. It decides whether an already produced report is acceptable evidence for the exact staging commit, database identity and lifecycle evidence manifest.

## Why this layer exists

A field such as:

```json
{"ok": true}
```

is not sufficient evidence that the full read-only lifecycle passed.

The verifier requires the complete 43-field smoke report and independently checks all identity, version, state, outbox, bootstrap, finalizer, rollback and safety invariants.

## Input report

The report must be:

- an absolute canonical file;
- outside `public_html`;
- not a symbolic link;
- not world-writable;
- no larger than 128 KiB;
- a JSON object with exactly the expected fields and no extras.

The verifier performs read-only local file access. It never modifies the report.

## Verification command

```bash
php ops/runtime/verify-staging-db-primary-api-read-only-smoke-evidence.php \
  --report=/absolute/private/path/read-only-smoke.json \
  --expected-commit=<exact-40-character-lowercase-commit> \
  --expected-database-identity=<exact-64-character-lowercase-fingerprint> \
  --expected-evidence-fingerprint=<exact-64-character-lowercase-fingerprint>
```

Default acceptance bounds:

```text
max report age:          3600 seconds
expected bootstrap hooks:   5
expected bootstrap filters: 2
```

They can be overridden only explicitly:

```bash
--max-age-seconds=3600
--expected-bootstrap-hooks=5
--expected-bootstrap-filters=2
```

Accepted maximum age range is 60–86400 seconds. Hook and filter counts remain bounded by the verifier constructor.

## Exact identity contract

The verifier requires:

```text
report_type:                 mvp-14.8.6n-staging-api-read-only-smoke
action:                      staging_api_read_only_smoke_passed
evidence_manifest_version:  v4-staging-db-primary-api-lifecycle-evidence
projection_contract_version: v1-normalized-all-modules
```

It also requires exact matches for:

- repository commit;
- staging database identity fingerprint;
- lifecycle evidence fingerprint.

Commit and SHA-256 values must already be lowercase in the report. The verifier does not normalize a malformed report.

## State and outbox contract

Acceptance requires:

- positive state revision;
- positive outbox event count;
- outbox event count exactly equal to state revision;
- valid state SHA-256;
- valid outbox fingerprint;
- valid top-level-key fingerprint;
- positive top-level state count no greater than 256;
- current all-module projection contract;
- completed events lease-free;
- zero worker ticks.

The revision/count equality proves that the contiguous completed outbox chain covers every revision from 1 through the current state.

## API lifecycle contract

The report must prove:

- JSON remains the persistent default and rollback source;
- rollback data directory is external and canonical;
- real API bootstrap hooks and filters were preserved;
- expected bootstrap hook/filter counts match exactly;
- lifecycle evidence v4 was verified;
- request context matched current state revision/SHA;
- legacy JSON bridges stayed suppressed;
- request finalizer completed;
- sentinel data filters remained unchanged;
- selector, request session and activation existed in process memory only.

## Unchanged and safety contract

These fields must be exactly `true`:

```text
state_unchanged
snapshot_unchanged
outbox_unchanged
data_filters_unchanged
request_finalizer_completed
api_only
```

These fields must be exactly `false`:

```text
persistent_config_changed
http_route_added
webhook_allowed
cron_changed
production_changed
sensitive_identifiers_exposed
```

Unknown, missing, stringified or loosely equivalent boolean values are rejected.

## Freshness contract

The smoke CLI writes `generated_at_utc` using exact UTC `+00:00` format.

The verifier rejects:

- malformed timestamps;
- timestamps more than 30 seconds in the future;
- reports older than the configured maximum age.

Freshness is checked because this report is an operational acceptance gate, not merely an archival format.

## Successful verifier output

A successful result includes only safe evidence:

- report SHA-256;
- exact commit and non-sensitive fingerprints;
- state revision/SHA;
- outbox count/fingerprint;
- versions;
- zero worker tick proof;
- bootstrap counts;
- report age;
- unchanged and safety flags.

It does not include database host/name/user/password, private paths, JSON payloads, user IDs, payments or state contents.

## What successful verification means

Successful verification proves that the supplied report satisfies the complete read-only smoke acceptance contract for the expected commit, staging DB and lifecycle evidence.

It does **not** by itself:

- enable a mutating smoke;
- merge any Draft PR;
- enable webhook routing;
- authorize production cutover;
- change private config;
- change the staging or production database.

A separate operational decision remains required before any later mutating step.

## Focused verification

```bash
bash ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh
```

This runs the complete inherited MVP-14.8.6n read-only smoke contract and then verifies:

- exact valid report success;
- different commit rejection;
- different DB identity rejection;
- different lifecycle evidence rejection;
- revision/outbox mismatch rejection;
- non-zero worker rejection;
- bootstrap contour mismatch rejection;
- required-proof rejection;
- unsafe flag rejection;
- stale/future report rejection;
- extra-field rejection;
- uppercase SHA rejection;
- world-writable report rejection;
- CLI argument ordering and offline/no-write contract.

## Safety boundary

- code only;
- verifier is offline and read-only;
- no DB connection;
- no application bootstrap;
- no API or webhook execution;
- no private config load;
- no Cron;
- no Hostinger action;
- no deployment;
- no production change;
- no merge.
