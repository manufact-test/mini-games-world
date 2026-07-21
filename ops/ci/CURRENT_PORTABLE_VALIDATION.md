# MVP-14.8.6s — current portable PHP 8.3 validation

This sub-MVP provides one current runner-neutral validation package built directly on the hardened read-only staging smoke and its exact evidence verifier.

It replaces older portable experiments that were based on earlier smoke commits. The goal is simple: when a PHP 8.3 or self-hosted runner becomes available, it must test the current code rather than a stale stacked branch.

## Entry command

```bash
bash ops/ci/run-current-portable-focused-suite.sh
```

The runner invokes:

```text
ops/checks/db-primary-current-portable-validation-local.sh
```

## Exact coverage

The current portable check runs these roots in order:

```text
1. ops/checks/db-primary-projection-outbox-local.sh
2. ops/checks/db-primary-projection-worker-local.sh
3. ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh
```

The third root runs the current read-only smoke stack and then its report-verifier regressions. Through recursive calls the package covers fourteen unique scripts:

- transactional projection outbox;
- leased projection worker;
- all-module projector;
- staging rehearsal;
- evidence verifier and collector;
- activation guard;
- storage resolver;
- rollback-only synthetic suite;
- entrypoint selector;
- request finalizer/session;
- API lifecycle integration;
- read-only API smoke;
- read-only smoke evidence verifier.

The exact graph is stored in:

```text
ops/ci/current-portable-suite-manifest.json
```

Contract:

```text
v2-current-db-primary-focused-suite
```

The manifest fixes every script path, recursive link, success marker and safety flag. Its regression test requires exactly fourteen unique strict-Bash scripts.

## Requirements

The runner requires:

- Linux;
- Bash;
- Git;
- PHP 8.3.x;
- PHP extensions `json`, `pdo`, `pdo_sqlite`, `openssl`, `mbstring`;
- standard commands `date`, `find`, `cp`, `tee`, `cat`, `chmod`, `mkdir`, `grep`;
- GNU coreutils `timeout`.

The runner fails before execution if a compatible GNU `timeout` is unavailable. It never silently falls back to an unbounded test run.

The checkout must be outside `public_html` and completely clean, including untracked files, before execution. It must remain clean afterward.

## Safe evidence bundle

Default location:

```text
${RUNNER_TEMP:-${TMPDIR:-/tmp}}/mgw-current-ci-focused
```

The directory must be:

- absolute;
- external to the checkout;
- outside `public_html`;
- non-symlink;
- empty before the run.

The runner uses `umask 077` and requires exact mode `0700` for the artifact directory plus exact mode `0600` for the log, manifest and summary. Permission changes are mandatory and read back for verification; a failure blocks the run.

It creates exactly:

```text
current-focused-suite.log
current-focused-suite-summary.json
current-focused-suite-manifest.json
```

The final directory must still contain exactly these three real non-symlink files. Any hidden or visible extra entry blocks the bundle.

The copied manifest SHA-256 must match the source manifest before tests start. A `tee` failure or truncated log capture changes an otherwise successful suite into a failed run. Log type and permissions are rechecked after execution.

The summary binds:

- exact repository commit;
- exact PHP version;
- manifest SHA-256 and script count;
- log SHA-256;
- timestamps and duration;
- exit code;
- full repository-checkout unchanged proof;
- explicit false flags for database contact, private config, application entrypoint changes, Cron, deployment, production and sensitive exposure.

It does not include DB credentials, private paths, user data, application state or payment data.

## Timeout

Default timeout:

```text
2700 seconds
```

Override:

```bash
MGW_CI_TIMEOUT_SECONDS=3600 \
  bash ops/ci/run-current-portable-focused-suite.sh
```

Accepted range is 60–7200 seconds. GNU `timeout` is mandatory and enforces the selected bound with TERM followed by a bounded KILL grace period.

## Manual self-hosted workflow

Workflow:

```text
.github/workflows/current-portable-focused-suite.yml
```

It is `workflow_dispatch` only and requires:

```text
self-hosted
linux
x64
mgw-ci
```

It never uses GitHub-hosted runners. Evidence paths and artifact names include both `github.run_id` and `github.run_attempt`, so retries cannot reuse a previous bundle on a persistent runner.

Workflow order in this producer PR:

1. clean checkout without persisted credentials;
2. run the current portable suite;
3. print the safe summary;
4. upload the exact three-file attempt-specific bundle for seven days.

The stacked evidence-verifier PR adds mandatory bundle verification before upload while keeping the verification JSON outside this exact three-file directory.

No workflow was dispatched while preparing this PR.

## Focused contract command

```bash
bash ops/checks/db-primary-current-portable-validation-local.sh
```

This command is the actual full suite and therefore requires PHP 8.3. It is not claimed as passed until it runs on a suitable external runner.

## Safety boundary

The package:

- does not load private Hostinger config;
- does not connect to staging or production MySQL;
- does not execute API/webhook routes;
- does not create Cron jobs;
- does not deploy;
- does not merge;
- does not switch runtime storage;
- does not modify production;
- does not require repository secrets.

A successful portable run proves the current code and static/runtime fake contracts. It does not replace fresh lifecycle evidence v4 or the real read-only staging smoke against `mgw_stage`.

## Next operational checkpoint

After a successful exact-commit PHP 8.3 run:

1. update the Hostinger-connected staging branch to the same accepted commit;
2. redeploy staging;
3. collect fresh lifecycle evidence v4 against isolated `mgw_stage`;
4. execute the CLI-only read-only smoke;
5. verify its exact 43-field report;
6. keep mutating smoke and production cutover blocked until all evidence is clean.
