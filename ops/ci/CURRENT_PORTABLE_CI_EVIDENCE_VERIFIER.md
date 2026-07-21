# MVP-14.8.6t — current portable CI evidence verifier

This sub-MVP verifies the exact evidence bundle produced by `run-current-portable-focused-suite.sh`. It is offline, read-only and bound to the current fourteen-script DB-primary smoke stack.

## Input bundle

The evidence directory must contain exactly these three real files and nothing else:

```text
current-focused-suite.log
current-focused-suite-summary.json
current-focused-suite-manifest.json
```

The verification report is intentionally written outside this directory.

## CLI

```bash
php ops/ci/verify-current-portable-focused-suite-evidence.php \
  --evidence-dir=/absolute/private/path/mgw-current-ci \
  --expected-commit=<exact-40-character-lowercase-commit> \
  --max-age-seconds=3600
```

`--max-age-seconds` is optional. Its default and maximum value are seven days (`604800`), matching artifact retention. Every option may appear only once. Values are not trimmed or lowercased.

## Required summary contract

The verifier accepts only the exact twenty-field success summary produced by MVP-14.8.6s:

- exact report type and suite;
- `ok = true` and `exit_code = 0`;
- exact expected repository commit;
- PHP 8.3.x;
- exact manifest and log SHA-256 values;
- integer manifest script count equal to fourteen;
- exact UTC `Z` timestamps and matching integer duration;
- evidence age within the configured bound;
- unchanged repository checkout;
- every infrastructure and production safety flag set to exact boolean `false`.

Stringified integers, normalized identities, additional fields and missing fields are rejected.

## Exact manifest graph

The manifest is not trusted merely because its hash matches the summary. The verifier independently requires the hard-coded graph:

1. transactional projection outbox check;
2. leased projection worker check;
3. current read-only smoke evidence verifier root;
4. read-only smoke;
5. API session integration;
6. request finalizer;
7. entrypoint selector;
8. synthetic suite;
9. storage resolver;
10. staging activation;
11. evidence collector;
12. lifecycle evidence;
13. staging rehearsal;
14. all-module projector.

All manifest safety fields must be exact boolean `false`.

## Log proof

A successful log contains fifteen exact complete-line markers:

- outbox root;
- worker root;
- the twelve recursive markers in real return order, from all-module projector back to the current read-only smoke verifier;
- the final current portable validation marker.

Each marker must appear exactly once and after the previous marker. Substring matches, duplicates, omissions and reordered markers fail verification.

## Filesystem safety

The verifier requires:

- an absolute canonical evidence directory;
- no symbolic links;
- no path inside `public_html`;
- a non-world-writable directory and files;
- exact three-file membership;
- bounded file sizes;
- exact reads matching the observed file sizes.

The producer in MVP-14.8.6s additionally requires exact `0700` directory and `0600` file permissions before the bundle is created.

## Workflow integration

The manual self-hosted workflow:

1. checks out the exact revision without persistent credentials;
2. runs the current portable PHP 8.3 suite;
3. verifies the exact bundle against `$GITHUB_SHA`;
4. writes the verification JSON outside the three-file input directory;
5. uploads the original bundle plus the verification report.

Paths and artifact names include both `run_id` and `run_attempt`, preventing stale bundle reuse on persistent runners.

## Focused verification

```bash
bash ops/checks/db-primary-current-portable-ci-evidence-verifier-local.sh
```

This first executes the complete inherited current portable validation stack, then lints and runs verifier tamper/static contracts.

## Safety boundary

- offline local file reads only;
- no private application config;
- no database connection;
- no API or webhook execution;
- no SSH, Hostinger, Cron or deployment action;
- no production change;
- no automatic merge.

A verified portable bundle proves only that the current focused PHP 8.3 stack passed for the exact commit. It does not authorize a mutating staging smoke or production cutover.
