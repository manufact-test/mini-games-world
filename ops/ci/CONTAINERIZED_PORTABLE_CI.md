# MVP-14.8.6q — containerized portable CI

This stacked sub-MVP packages the manifest-backed portable CI stack into a reusable PHP 8.3 container environment.

It allows the same focused suite to run on a developer machine, a private build server or a future self-hosted runner without installing PHP and extensions directly on the host.

## Files

```text
ops/ci/Dockerfile.portable-focused
ops/ci/run-portable-focused-suite-container.sh
```

## Image contents

The image is based on:

```text
php:8.3-cli-bookworm
```

It installs only the tools required by the portable suite:

- Bash;
- Git;
- GNU coreutils;
- CA certificates;
- PHP extensions `mbstring` and `pdo_sqlite`;
- PHP built-ins already provided by the official image, including JSON, PDO and OpenSSL.

The image uses a fixed non-root user and the exact portable entrypoint:

```text
bash ops/ci/run-portable-focused-suite.sh
```

The Dockerfile contains no `COPY` or `ADD`. Application code is never embedded in the image.

## Zero-context image build

The wrapper builds the Dockerfile through stdin:

```bash
docker build --tag mgw-mini-games-world-ci:php83 - < ops/ci/Dockerfile.portable-focused
```

This uses an empty Docker build context. The repository, `.git` directory and local files are not sent to the Docker daemon.

The image is built only when it does not already exist, unless explicitly requested:

```bash
MGW_CI_CONTAINER_REBUILD=1 \
  bash ops/ci/run-portable-focused-suite-container.sh
```

Accepted rebuild values are exactly `0` or `1`.

## Run command

```bash
bash ops/ci/run-portable-focused-suite-container.sh
```

Optional image name:

```bash
MGW_CI_CONTAINER_IMAGE=private-registry/mgw-ci:php83 \
  bash ops/ci/run-portable-focused-suite-container.sh
```

Optional external artifact directory:

```bash
MGW_CI_CONTAINER_OUTPUT_DIR=/absolute/private/path/mgw-ci-artifacts \
  bash ops/ci/run-portable-focused-suite-container.sh
```

Optional bounded suite timeout:

```bash
MGW_CI_TIMEOUT_SECONDS=3600 \
  bash ops/ci/run-portable-focused-suite-container.sh
```

Accepted timeout range is 60–7200 seconds.

## Host preflight

Before build or run, the wrapper requires:

- Docker CLI;
- reachable Docker daemon;
- clean tracked Git worktree;
- checkout outside `public_html`;
- real non-symlink Dockerfile;
- absolute non-symlink artifact directory outside the checkout and outside `public_html`;
- valid host UID/GID.

## Runtime sandbox

The focused suite container runs with:

```text
--network=none
--read-only
--cap-drop=ALL
--security-opt=no-new-privileges
--pids-limit=256
--memory=1g
--cpus=2
```

Additional boundaries:

- repository mounted read-only at `/workspace`;
- artifacts mounted read-write at `/artifacts`;
- temporary storage is a 256 MiB `noexec,nosuid,nodev` tmpfs;
- process runs as the current host UID/GID;
- Git safe-directory is supplied through process environment, not a persistent config file;
- Docker socket is not mounted;
- host network is not used;
- privileged mode is forbidden;
- no secrets or database variables are supplied.

## Network boundary

Image build may require network access to obtain the base PHP image and Debian packages.

The actual focused-suite container has `--network=none`. Tests cannot contact:

- GitHub;
- Hostinger;
- staging MySQL;
- production MySQL;
- external APIs;
- package registries.

## Output

The container writes the existing portable evidence bundle to the host artifact directory:

```text
focused-suite.log
focused-suite-summary.json
focused-suite-manifest.json
```

The bundle can then be verified offline:

```bash
php ops/ci/verify-portable-focused-suite-evidence.php \
  --evidence-dir=/absolute/private/path/mgw-ci-artifacts \
  --expected-commit=<exact-40-character-commit-sha>
```

## Docker daemon boundary

Access to a Docker daemon is security-sensitive. This sub-MVP does not:

- install Docker;
- configure daemon permissions;
- mount `/var/run/docker.sock` into the test container;
- use Docker-in-Docker;
- create privileged containers;
- register a self-hosted runner;
- provision registry credentials.

Host-level Docker installation and access policy remain a separate infrastructure decision.

## Safety boundary

The containerized suite:

- runs only lint, fake/in-memory tests and static contracts;
- does not load private Hostinger config;
- does not contact staging or production databases;
- does not execute API or webhook endpoints;
- does not deploy;
- does not create Cron jobs;
- does not merge branches;
- does not switch runtime storage;
- does not modify production.

## Focused verification

```bash
bash ops/checks/db-primary-containerized-portable-ci-local.sh
```

This command does not build or run Docker. It runs the complete inherited portable CI/evidence-verifier stack, lints the wrapper and verifies the container safety contract statically.

A real image build and container run remain a separate execution checkpoint on a machine with Docker.

## Current status

- Dockerfile and wrapper prepared;
- no image built;
- no container started;
- no network request made by this PR work;
- no runner registered;
- no workflow dispatched;
- no Hostinger action;
- no database contact;
- no deployment;
- no merge.
