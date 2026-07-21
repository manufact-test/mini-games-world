# MVP-14.8.6p — portable CI evidence verifier v2

This independent stacked sub-MVP verifies the evidence bundle produced by the portable PHP 8.3 focused suite.

It does not run tests, connect to a database or contact GitHub. It accepts only a complete, internally consistent bundle produced for the exact expected repository commit.

## Input bundle

The evidence directory must be an absolute canonical path outside `public_html`. It must not be a symbolic link or world-writable and must contain exactly:

```text
focused-suite.log
focused-suite-summary.json
focused-suite-manifest.json
```

Every file must be a real non-symlink file and must not be world-writable.

Maximum accepted sizes:

```text
summary:   64 KiB
manifest: 256 KiB
log:       20 MiB
```

Extra files, subdirectories, stale evidence from a previous run and unsafe permissions are rejected.

## Verification command

```bash
php ops/ci/verify-portable-focused-suite-evidence.php \
  --evidence-dir=/absolute/private/path/mgw-ci-focused \
  --expected-commit=<exact-40-character-lowercase-commit>
```

Both options are required and may be supplied exactly once.

The CLI does not lowercase or trim the expected commit. Uppercase, leading/trailing whitespace and duplicate options are rejected before the verifier class is loaded.

## Summary contract

The verifier requires an exact JSON-object schema with:

- report type `mvp-14.8.6o-portable-self-hosted-focused-suite`;
- suite `db-primary-portable-self-hosted-ci-local`;
- boolean `ok = true`;
- integer `exit_code = 0`;
- exact lowercase repository commit;
- exact PHP `8.3.x` version;
- integer manifest script count;
- integer duration;
- tracked worktree unchanged;
- exact lowercase manifest and log SHA-256 values;
- exact UTC `Z` timestamps;
- every infrastructure safety flag equal to boolean `false`.

Stringified numeric fields, unknown fields, malformed values and normalized lookalikes are rejected.

## Exact manifest graph

The verifier does not trust only counts or `next_script` links. It contains the exact expected graph and compares the manifest byte-decoded structure against it:

- three exact ordered roots;
- eleven exact recursive nodes;
- thirteen unique scripts;
- every exact script path;
- every exact `next_script` value;
- every exact success marker;
- exact safety object.

A recomputed manifest SHA does not help an altered script graph pass verification.

## Log proof

Every required success marker must appear:

- as one complete log line;
- exactly once;
- in real execution order.

A marker embedded inside another line, duplicated, missing or reordered is rejected even when the summary log hash was recomputed after tampering.

## Timeline proof

`started_at_utc` and `finished_at_utc` must use exact `YYYY-MM-DDTHH:MM:SSZ` format and represent valid calendar dates.

`duration_seconds` must be an integer, equal to the timestamp difference and no greater than 7200 seconds.

## Workflow integration

The manual self-hosted workflow uses paths unique to both `github.run_id` and `github.run_attempt`:

```text
mgw-ci-focused-<run-id>-<attempt>
mgw-ci-focused-verification-<run-id>-<attempt>.json
```

The portable runner also requires the evidence directory to be empty before execution. This prevents a persistent self-hosted runner or retried workflow from uploading an older bundle.

Workflow order:

1. clean checkout without persisted credentials;
2. run the portable focused suite;
3. verify the exact bundle against `$GITHUB_SHA`;
4. write the verification report outside the exact three-file input bundle;
5. upload attempt-specific evidence.

## Successful output

The verifier prints only non-sensitive evidence:

- exact commit and PHP version;
- manifest and log SHA-256;
- script and success-marker counts;
- duration;
- unchanged and safety flags;
- verification timestamp.

It does not include database host/name/user/password, private paths, application state, user IDs or payment data.

## Focused verification

```bash
bash ops/checks/db-primary-portable-ci-evidence-verifier-local.sh
```

The focused check runs the complete inherited 13-script portable contract, PHP lint and regressions for:

- valid bundle acceptance;
- different, uppercase and whitespace-padded commit rejection;
- uppercase hash rejection;
- stringified numeric rejection;
- strict PHP version rejection;
- log hash tampering;
- duplicate and embedded marker rejection;
- invalid calendar timestamp rejection;
- exact manifest graph tampering rejection;
- extra-file rejection;
- world-writable file/directory rejection;
- CLI duplicate/missing/exact-byte contracts;
- attempt-isolated workflow ordering.

## Safety boundary

- offline local file verification only;
- no private config;
- no database connection;
- no API or webhook execution;
- no network request;
- no SSH, Cron or deploy;
- no Hostinger action;
- no production change;
- no merge.

A verified portable CI bundle is test evidence only. It does not authorize mutating staging smoke or production cutover.
