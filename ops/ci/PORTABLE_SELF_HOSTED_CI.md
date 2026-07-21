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

That check first runs the complete inherited stack through:

```text
ops/checks/db-primary-staging-api-read-only-smoke-local.sh
```

The inherited scripts recursively include every previous DB-primary adapter, outbox, worker, projector, evidence, selector, request-finalizer, API lifecycle and read-only smoke regression.

## Runner prerequisites

The checkout must provide:

- Linux;
- Bash;
- Git;
- PHP 8.3.x;
- PHP extensions: `json`, `pdo`, `pdo_sqlite`, `openssl`, `mbstring`;
- optional GNU `timeout` for hard process timeout.

The runner validates these requirements before starting the suite.

## Checkout boundary

The runner refuses to execute from `public_html`.

Use an isolated CI workspace such as:

```text
/opt/actions-runner/_work/mini-games-world/mini-games-world
```

or the equivalent workspace of another private CI system.

The runner also requires a clean tracked worktree before execution and checks that no tracked file changed during the suite.

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

It contains:

```text
focused-suite.log
focused-suite-summary.json
```

A different private temporary directory may be supplied:

```bash
MGW_CI_OUTPUT_DIR=/private/runner-temp/mgw-ci \
  bash ops/ci/run-portable-focused-suite.sh
```

The JSON summary contains only:

- success/failure;
- checkout commit SHA;
- PHP version;
- start/finish/duration;
- process exit code;
- log SHA-256;
- tracked-worktree unchanged flag;
- explicit false flags for DB contact, private config, deployment, Cron and production changes.

It does not contain DB host, DB name, usernames, passwords, private config paths or runner registration tokens.

## Timeout

Default focused-suite timeout is 2400 seconds. A bounded override is available:

```bash
MGW_CI_TIMEOUT_SECONDS=3600 \
  bash ops/ci/run-portable-focused-suite.sh
```

Accepted range: 60–7200 seconds.

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

The workflow:

1. checks out the exact revision without persisted credentials;
2. runs the portable entrypoint;
3. prints the safe JSON summary;
4. uploads the safe log and summary for seven days.

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

It does not install or register a self-hosted runner. Runner installation and secret provisioning belong to a separate infrastructure action when the target platform is selected.

## Current safety status

- code only;
- manual workflow only;
- no workflow dispatch performed;
- no runner registered;
- no Hostinger action;
- no staging/production DB contact;
- no deployment;
- no merge.
