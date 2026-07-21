# MVP-14.8.6s — current portable PHP 8.3 validation

This sub-MVP provides one current validation package built directly on the hardened read-only staging smoke and its exact evidence verifier.

It replaces older portable experiments based on stale smoke commits. Its purpose is to prove the current code on exact PHP 8.3 before any staging deployment or database operation.

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

Through recursive calls the package covers fourteen unique scripts:

- transactional projection outbox;
- leased projection worker;
- all-module projector;
- staging rehearsal;
- lifecycle evidence verifier and collector;
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

The manifest fixes every script path, recursive link, success marker and safety flag. Its regression requires exactly fourteen unique strict-Bash scripts.

## Runtime requirements

The direct runner requires:

- Linux;
- Bash;
- Git;
- PHP 8.3.x;
- PHP extensions `json`, `pdo`, `pdo_sqlite`, `openssl`, `mbstring`;
- standard commands `date`, `find`, `cp`, `tee`, `cat`, `chmod`, `mkdir`, `grep`;
- GNU coreutils `timeout`.

The runner fails before execution if PHP is not 8.3.x or compatible GNU `timeout` is unavailable. It never falls back to an unbounded run.

The checkout must remain outside `public_html` and completely clean, including untracked files, before and after execution.

## Safe evidence bundle

Default location:

```text
${RUNNER_TEMP:-${TMPDIR:-/tmp}}/mgw-current-ci-focused
```

The directory must be absolute, canonical, external to the checkout, outside `public_html`, non-symlink and empty before the run.

The runner requires exact mode `0700` for the artifact directory and exact mode `0600` for every evidence file. It produces exactly:

```text
current-focused-suite.log
current-focused-suite-summary.json
current-focused-suite-manifest.json
```

Any extra entry, failed permission change, unsafe file type, changed checkout, failed `tee` capture or manifest mismatch blocks success.

The summary binds:

- exact repository commit;
- exact PHP version;
- manifest SHA-256 and script count;
- log SHA-256;
- timestamps and duration;
- exit code;
- complete checkout-unchanged proof;
- explicit false flags for database contact, private config, entrypoint changes, Cron, deployment, production and sensitive exposure.

It contains no credentials, private paths, user data, application state or payment data.

## Timeout

Default:

```text
2700 seconds
```

Override:

```bash
MGW_CI_TIMEOUT_SECONDS=3600 \
  bash ops/ci/run-current-portable-focused-suite.sh
```

Accepted range is 60–7200 seconds. GNU `timeout` sends TERM and then uses a bounded KILL grace period.

## Temporary GitHub-hosted workflow

Workflow:

```text
.github/workflows/current-portable-focused-suite.yml
```

During active MVP development the repository is temporarily public. The workflow therefore uses the official GitHub-hosted `ubuntu-24.04` image and validates its built-in PHP runtime before execution:

- exact PHP 8.3.x;
- `json`, `pdo`, `pdo_sqlite`, `openssl`, `mbstring`;
- GNU coreutils `timeout`.

The workflow is limited to pull requests targeting the current portable-validation branch plus explicit manual dispatch. It has read-only repository permission, does not use repository secrets and performs no deploy, SSH, Hostinger, Cron or database operation.

Evidence paths and artifact names include both `github.run_id` and `github.run_attempt`, preventing stale bundle reuse.

Workflow order:

1. clean credential-free checkout;
2. exact PHP 8.3/runtime preflight;
3. full current portable suite;
4. exact evidence verification against `$GITHUB_SHA`;
5. safe report output;
6. attempt-isolated artifact upload for seven days.

The verifier report remains outside the exact three-file input bundle.

## Current execution status

The workflow and contracts are prepared for GitHub-hosted PHP 8.3. No successful run is claimed until GitHub creates an actual workflow run with executable steps, logs and an accepted evidence artifact for the exact head commit.

At the current checkpoint no workflow run/status has appeared for the new head. This must be resolved before staging deployment. The likely remaining administrative check is whether GitHub Actions are enabled for the repository; no assumption of success is made without a real run.

## Temporary public-repository obligation

The public visibility is development-only. Issue #92 tracks the mandatory pre-release work:

- purchase/activate the required private GitHub plan;
- return the repository to private;
- remove temporary public-only CI behavior;
- verify private Actions;
- scan public history, logs and artifacts for sensitive values and rotate anything exposed;
- review collaborators, GitHub Apps, deploy keys and branch protection.

## Focused contract command

```bash
bash ops/checks/db-primary-current-portable-validation-local.sh
```

This is the actual full focused suite and is not considered passed until it runs on PHP 8.3 with accepted evidence.

## Safety boundary

The package:

- does not load private Hostinger config;
- does not connect to staging or production MySQL;
- does not execute API/webhook routes;
- does not create Cron jobs;
- does not deploy or merge;
- does not switch runtime storage;
- does not modify production;
- does not require repository secrets.

A successful portable run proves the current code and fake/local contracts only. It does not replace fresh lifecycle evidence v4 or the real read-only staging smoke against isolated `mgw_stage`.

## Next operational checkpoint

After a successful exact-commit PHP 8.3 run:

1. update the Hostinger-connected staging branch to the same accepted commit;
2. redeploy staging;
3. collect fresh lifecycle evidence v4 against isolated `mgw_stage`;
4. execute the CLI-only read-only smoke;
5. verify its exact 43-field report;
6. keep mutating smoke and production cutover blocked until every gate is clean.
