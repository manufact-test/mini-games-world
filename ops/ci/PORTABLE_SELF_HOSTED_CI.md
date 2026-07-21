# MVP-14.8.6o — portable self-hosted focused CI

This stacked sub-MVP provides a runner-neutral entrypoint for the complete DB-primary focused test stack. It is intended for a future self-hosted GitHub runner or another private CI system and does not depend on GitHub-hosted minutes.

## What it runs

The portable entrypoint is:

```bash
bash ops/ci/run-portable-focused-suite.sh
```

It invokes:

```text
ops/checks/db-primary-portable-self-hosted-ci-local.sh
```

The portable check runs three explicit roots in this order:

```text
1. ops/checks/db-primary-projection-outbox-local.sh
2. ops/checks/db-primary-projection-worker-local.sh
3. ops/checks/db-primary-staging-api-read-only-smoke-local.sh
```

The third root recursively runs the complete projector, rehearsal, evidence, activation, resolver, synthetic, selector, request-finalizer, API lifecycle and read-only smoke chain.

The outbox and worker roots are explicit because the inherited API chain does not call those two foundational scripts itself.

## Exact suite manifest

The exact expected script graph is stored in:

```text
ops/ci/portable-focused-suite-manifest.json
```

Contract:

```text
v1-portable-db-primary-focused-suite
```

The manifest records:

- the portable check entrypoint;
- three ordered root scripts;
- eleven recursive chain nodes;
- thirteen unique scripts total;
- each script's exact success marker;
- explicit no-side-effect safety flags.

`RuntimePrimaryPortableFocusedSuiteManifestTest.php` verifies:

- exact manifest fields;
- safe repository-relative script paths;
- every script exists and is not a symlink;
- every script uses strict Bash;
- root call order is outbox → worker → API stack;
- every recursive script calls the declared next script before its own success marker;
- all thirteen unique scripts are covered;
- every safety flag is false.

The portable runner validates the manifest before executing the suite and binds every summary to:

- manifest SHA-256;
- manifest script count.

## Runner prerequisites

The checkout must provide:

- Linux;
- Bash;
- Git;
- `date`, `find`, `cp`, `tee`, `cat`, `chmod` and `mkdir`;
- PHP 8.3.x;
- PHP extensions: `json`, `pdo`, `pdo_sqlite`, `openssl`, `mbstring`;
- optional GNU `timeout` for hard process timeout.

The runner validates these requirements before starting the suite. A non-GNU `timeout` binary is ignored instead of causing a false failure.

## Checkout boundary

The runner refuses to execute from `public_html`.

Use an isolated CI workspace such as:

```text
/opt/actions-runner/_work/mini-games-world/mini-games-world
```

or the equivalent workspace of another private CI system.

The runner requires the complete repository checkout to be clean before execution, including untracked files. It checks again after the suite and fails evidence generation if a tracked or untracked file appeared or changed.

## No infrastructure side effects

The portable suite:

- does not load private Hostinger configuration;
- does not connect to staging or production MySQL;
- does not execute the API or webhook endpoint;
- does not create or edit Cron jobs;
- does not deploy;
- does not merge branches;
- does not switch runtime storage;
- does not modify production;
- does not require repository secrets.

It runs only lint, fake/in-memory tests and static contracts already present in the repository.

## Safe artifacts

Default artifact location:

```text
${RUNNER_TEMP:-${TMPDIR:-/tmp}}/mgw-ci-focused
```

The artifact directory must be:

- absolute;
- outside the checkout;
- outside `public_html`;
- not a symbolic link;
- empty before the run.

The runner uses `umask 077`, protects the directory with mode `0700` when possible and protects the evidence files with mode `0600` when possible.

It creates exactly:

```text
focused-suite.log
focused-suite-summary.json
focused-suite-manifest.json
```

The copied manifest SHA-256 must match the source manifest SHA-256 before the suite starts.

A different private temporary directory may be supplied:

```bash
MGW_CI_OUTPUT_DIR=/private/runner-temp/mgw-ci \
  bash ops/ci/run-portable-focused-suite.sh
```

The JSON summary contains only:

- success/failure;
- exact suite manifest SHA-256;
- suite manifest script count;
- checkout commit SHA;
- PHP version;
- start/finish/duration;
- process exit code;
- log SHA-256;
- worktree unchanged flag;
- explicit false flags for DB contact, private config, deployment, Cron and production changes.

It does not contain DB host, DB name, usernames, passwords, private config paths or runner registration tokens.

## Timeout

Default focused-suite timeout is 2400 seconds. A bounded override is available:

```bash
MGW_CI_TIMEOUT_SECONDS=3600 \
  bash ops/ci/run-portable-focused-suite.sh
```

Accepted range: 60–7200 seconds.

Hard timeout is used only when GNU coreutils `timeout` is available. Other environments still run the same suite without an incompatible timeout wrapper.

## GitHub self-hosted workflow

The repository includes:

```text
.github/workflows/portable-self-hosted-focused-suite.yml
```

It is `workflow_dispatch` only and requires these runner labels:

```text
self-hosted
linux
x64
mgw-ci
```

It never uses `ubuntu-latest`. It does not run automatically on push or pull request. No action is queued until someone manually dispatches it after a matching runner exists.

For each workflow attempt, the evidence path and artifact name include both `github.run_id` and `github.run_attempt`. This prevents a persistent self-hosted runner or a retried workflow from reusing a previous bundle.

The workflow:

1. checks out the exact revision without persisted credentials;
2. runs the portable entrypoint;
3. prints the safe JSON summary;
4. uploads the safe log, summary and exact manifest for seven days.

The next stacked verifier PR additionally verifies that exact three-file bundle before upload.

## Other private CI systems

GitLab CI, Gitea Actions, Forgejo Actions, Jenkins or a plain build agent can call the same command:

```bash
bash ops/ci/run-portable-focused-suite.sh
```

No GitHub-specific environment variables are required by the runner script itself.

## Out of scope

This sub-MVP does not:

- install or register a self-hosted runner;
- generate runner registration tokens;
- configure a new Git server;
- configure network access;
- execute real staging smoke;
- unblock mutating smoke;
- touch Hostinger.

Runner installation and secret provisioning belong to a separate infrastructure action when the target platform is selected.

## Current safety status

- code only;
- manual workflow only;
- no workflow dispatch performed;
- no runner registered;
- no Hostinger action;
- no staging/production DB contact;
- no deployment;
- no merge.
