# MVP-14.8.6t — current portable CI evidence verifier

This sub-MVP verifies the exact evidence bundle produced by `run-current-portable-focused-suite.sh`. It is offline, read-only and bound to the current fourteen-script DB-primary smoke stack.

During MVP development the repository is temporarily public, so this exact branch also uses a GitHub-hosted Ubuntu runner with PHP 8.3. The repository must be returned to private access after development and the required private GitHub plan must be paid before release.

## One-command manual run

The preferred pre-merge command on a clean PHP 8.3 Linux checkout is:

```bash
bash ops/ci/run-and-verify-current-portable-focused-suite.sh
```

It performs the complete sequence without GitHub Actions:

1. requires PHP 8.3 and GNU coreutils `timeout` before creating artifacts;
2. binds the clean exact checkout commit;
3. creates a fresh canonical private session outside the repository and `public_html`;
4. runs the full current portable suite into an exact three-file evidence directory;
5. verifies that bundle against the same commit;
6. writes the verification JSON as a sibling of the evidence directory;
7. leaves exactly two session entries: `evidence/` and the verification report.

The default session path is unique under `${RUNNER_TEMP:-${TMPDIR:-/tmp}}`. A custom `MGW_CI_SESSION_ROOT` must be absolute, not already exist, resolve without symlink parents and remain outside the checkout and `public_html`.

Optional verification freshness override:

```bash
MGW_CI_VERIFY_MAX_AGE_SECONDS=3600 \
  bash ops/ci/run-and-verify-current-portable-focused-suite.sh
```

Accepted range is 60–604800 seconds.

## Input bundle

The evidence directory must contain exactly these three real files and nothing else:

```text
current-focused-suite.log
current-focused-suite-summary.json
current-focused-suite-manifest.json
```

The verification report is intentionally written outside this directory.

## Standalone verifier CLI

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

The producer in MVP-14.8.6s additionally requires exact `0700` directory and `0600` file permissions before the bundle is created. A compatible GNU `timeout` is mandatory; an unbounded fallback is forbidden.

The one-command session wrapper additionally requires:

- a fresh non-existing session path;
- canonical equality after creation, rejecting symlink-parent escapes;
- exact session mode `0700`;
- exact verification report mode `0600`;
- no entries other than the evidence directory and verification report.

## Workflow integration

The temporary GitHub-hosted PHP 8.3 workflow:

1. checks out the exact revision without persistent credentials;
2. installs PHP 8.3 with the exact required extensions;
3. confirms the exact PHP runtime and GNU timeout;
4. runs the current portable suite;
5. verifies the exact bundle against `$GITHUB_SHA`;
6. writes the verification JSON outside the three-file input directory;
7. uploads the original bundle plus the verification report.

Paths and artifact names include both `run_id` and `run_attempt`, preventing stale bundle reuse.

The direct one-command wrapper remains the fallback when GitHub Actions is unavailable.

## Focused verification

```bash
bash ops/checks/db-primary-current-portable-ci-evidence-verifier-local.sh
```

This first executes the complete inherited current portable validation stack, then lints and runs verifier tamper/static contracts, including the one-command session wrapper contract.

## Safety boundary

- offline local file reads only after the fake/local focused suite;
- no private application config;
- no database connection;
- no API or webhook execution;
- no SSH, Hostinger, Cron or deployment action;
- no production change;
- no automatic merge.

A verified portable bundle proves only that the current focused PHP 8.3 stack passed for the exact commit. It does not authorize a mutating staging smoke or production cutover.
