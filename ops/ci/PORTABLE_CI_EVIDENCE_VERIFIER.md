# MVP-14.8.6p — portable CI evidence verifier

This stacked sub-MVP adds an offline verifier for the evidence bundle produced by MVP-14.8.6o.

It does not run the focused suite. It verifies that an already produced result is internally consistent, belongs to the expected repository commit and contains proof that every manifest-bound check completed successfully.

## Input bundle

The evidence directory must be an absolute canonical path outside `public_html` and contain exactly:

```text
focused-suite.log
focused-suite-summary.json
focused-suite-manifest.json
```

Extra files, subdirectories, symbolic links or non-canonical paths are rejected.

Maximum accepted sizes:

```text
summary:   64 KiB
manifest: 256 KiB
log:       20 MiB
```

## Verification command

```bash
php ops/ci/verify-portable-focused-suite-evidence.php \
  --evidence-dir=/absolute/private/path/mgw-ci-focused \
  --expected-commit=<exact-40-character-commit-sha>
```

Both arguments are mandatory for the CLI.

The command prints a safe JSON report to stdout and does not write into the evidence directory.

## Summary contract

The verifier requires an exact successful summary:

- report type `mvp-14.8.6o-portable-self-hosted-focused-suite`;
- suite `db-primary-portable-self-hosted-ci-local`;
- `ok = true`;
- `exit_code = 0`;
- exact 40-character repository commit;
- exact PHP `8.3.x` version;
- tracked worktree unchanged;
- exact manifest SHA-256;
- exact log SHA-256;
- exact manifest script count;
- exact UTC `Z` timestamps;
- duration equal to the timestamp difference and not greater than 7200 seconds;
- every infrastructure safety flag equal to `false`.

Unknown or missing summary fields are rejected.

## Manifest contract

The verifier independently checks the copied manifest:

- contract `v1-portable-db-primary-focused-suite`;
- exact portable check entrypoint;
- exactly three ordered roots;
- exactly eleven recursive nodes;
- exactly thirteen unique check scripts;
- exact foundational root order:
  1. transactional outbox;
  2. leased worker;
  3. full API/read-only stack;
- valid recursive `next_script` links;
- unique bounded success markers;
- every manifest safety flag equal to `false`.

The copied manifest SHA must match the SHA recorded in the summary.

## Log proof

The verifier does not accept a summary alone.

It requires every success marker to appear exactly once in the log and in actual execution order:

1. transactional outbox root;
2. leased worker root;
3. recursive DB-primary chain in completion order, from the deepest all-module projector check back through read-only API smoke;
4. final portable-suite marker.

A missing, duplicated or reordered marker blocks verification even when hashes were recomputed after tampering.

## Workflow integration

The manual self-hosted workflow now performs these stages:

1. run the portable focused suite;
2. produce the three-file bundle;
3. verify the bundle against `$GITHUB_SHA`;
4. save `mgw-ci-focused-verification.json` outside the input bundle;
5. upload the original three evidence files plus the verification report.

The verification report is kept outside the input directory so the original bundle remains exactly three files and can be verified again later.

## Offline use

The verifier can run after downloading and extracting the artifact on any machine with PHP 8.3.

It does not require:

- GitHub API access;
- a registered runner;
- repository secrets;
- private Hostinger config;
- staging or production MySQL;
- HTTP access;
- SSH;
- Cron;
- deployment permissions.

## Safety boundary

The verifier class and CLI:

- perform read-only local file access;
- do not use `StorageFactory`;
- do not create database connections;
- do not execute API or webhook entrypoints;
- do not modify evidence files;
- do not write config;
- do not deploy;
- do not merge;
- do not touch Hostinger;
- do not contact staging or production.

## Focused verification

```bash
bash ops/checks/db-primary-portable-ci-evidence-verifier-local.sh
```

This runs the complete inherited manifest-backed portable CI contract first, then verifies:

- valid evidence bundle success;
- different commit rejection;
- log hash tampering rejection;
- duplicate success-marker rejection;
- unsafe summary rejection;
- timestamp/duration mismatch rejection;
- manifest topology tampering rejection;
- extra-file rejection;
- CLI ordering and no-write contract;
- self-hosted workflow verifier-before-upload ordering.

## Current status

- code only;
- no self-hosted workflow dispatch;
- no artifact verified from a live runner yet;
- no runner registered;
- no Hostinger action;
- no database contact;
- no deployment;
- no merge.
