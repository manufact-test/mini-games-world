# MVP-14.8.6r — read-only staging API smoke evidence verifier v2

This independent stacked sub-MVP verifies the JSON report produced by the current MVP-14.8.6n read-only staging API smoke.

It does not execute the smoke, connect to MySQL, load application bootstrap or enable DB-primary routing. It decides whether an already produced report is acceptable evidence for the exact staging commit, database identity and lifecycle evidence manifest.

## Input report

The report must be:

- an absolute canonical real file;
- outside `public_html`;
- not a symbolic link;
- no larger than 128 KiB;
- not world-writable;
- stored in a directory that is not world-writable;
- a JSON object with exactly 43 expected fields and no extras.

The verifier performs read-only local file access and never modifies the report.

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
max report age:            3600 seconds
expected bootstrap hooks:     5
expected bootstrap filters:   2
```

Optional overrides:

```text
--max-age-seconds=60..86400
--expected-bootstrap-hooks=1..32
--expected-bootstrap-filters=0..32
```

Every option may be provided at most once. Required identity values are validated before the verifier class is loaded.

The CLI and class do not trim or lowercase identities. Uppercase, whitespace-padded, duplicated or malformed values are rejected.

## Exact identity contract

Accepted reports require:

```text
report_type:                  mvp-14.8.6n-staging-api-read-only-smoke
action:                       staging_api_read_only_smoke_passed
evidence_manifest_version:   v4-staging-db-primary-api-lifecycle-evidence
projection_contract_version: v1-normalized-all-modules
```

The following values must match the explicit expected values exactly:

- repository commit;
- staging database identity fingerprint;
- lifecycle evidence fingerprint.

## State and outbox contract

Acceptance requires:

- positive integer state revision;
- positive integer outbox event count exactly equal to the revision;
- valid exact lowercase state and outbox SHA-256 values;
- positive integer top-level state count no greater than 256;
- positive integer TTL between 60 and 600 seconds;
- current all-module projector contract;
- completed events lease-free;
- zero worker ticks;
- exact bootstrap hook and filter counts.

Stringified integers are rejected.

## API lifecycle contract

The report must prove with exact boolean `true` values:

- JSON remains the persistent default and rollback source;
- rollback data directory is external and canonical;
- real API bootstrap hooks and filters were preserved;
- lifecycle evidence v4 was verified;
- immutable request context matched current state;
- legacy JSON bridges stayed suppressed;
- completed events were lease-free;
- request finalizer completed;
- state, snapshot, outbox and sentinel data filters remained unchanged;
- selector, request session and activation existed in process memory only;
- operation was API-only.

It must prove with exact boolean `false` values:

- persistent config changed;
- HTTP route added;
- webhook allowed;
- Cron changed;
- production changed;
- sensitive identifiers exposed.

The current smoke producer independently validates these source reports and copies the validated values into the 43-field evidence report; it no longer invents safe output flags after the operation.

## Freshness contract

`generated_at_utc` must use exact UTC `+00:00` format, represent a valid calendar timestamp, be no more than 30 seconds in the future and remain within the configured maximum age.

## Successful verifier output

A successful result includes only non-sensitive evidence:

- report SHA-256;
- exact commit and fingerprints;
- state revision/SHA;
- outbox count/fingerprint;
- contract versions;
- bootstrap counts;
- zero worker proof;
- report age;
- unchanged and safety flags;
- verification timestamp.

It does not include database host/name/user/password, private paths, JSON payloads, user IDs, payments or state contents.

## Focused verification

```bash
bash ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh
```

The focused command first runs the complete inherited read-only smoke contract, then PHP lint and regressions covering:

- exact valid report acceptance;
- different commit, DB identity and lifecycle evidence rejection;
- uppercase and whitespace-padded identity rejection;
- stringified revision, TTL and bootstrap-count rejection;
- revision/outbox mismatch;
- excessive top-level state count;
- non-zero worker rejection;
- false required proof and true safety flag rejection;
- stale, future, whitespace-padded and impossible calendar timestamps;
- extra field rejection;
- world-writable file and directory rejection;
- CLI duplicate, missing, malformed and bounded-option contracts;
- synchronization of all 43 smoke/verifier fields;
- proof that the smoke producer validates source safety flags before publishing evidence.

GitHub-hosted Actions remain unavailable because included minutes are exhausted. A complete PHP 8.3 run and real staging report verification are not claimed until an external runner produces evidence.

## Safety boundary

- code only;
- offline read-only verification;
- no DB or private config load;
- no application bootstrap;
- no API or webhook execution;
- no network request;
- no SSH, Cron or deploy;
- no Hostinger action;
- no production change;
- no merge.

Successful verification proves only that the supplied report satisfies the current read-only staging smoke acceptance contract. It does not authorize mutating smoke, merge, webhook routing or production cutover.
