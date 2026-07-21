# MVP-14.8.6q — containerized portable CI v2

This stacked sub-MVP packages the manifest-backed portable PHP 8.3 suite into a reusable container environment.

It allows the exact focused suite and evidence verifier to run on a developer machine, a private build server or a future self-hosted runner without installing PHP extensions directly on the host.

## Files

```text
ops/ci/Dockerfile.portable-focused
ops/ci/run-portable-focused-suite-container.sh
```

## Image contract

The image is based on:

```text
php:8.3-cli-bookworm
```

It installs Bash, Git, GNU coreutils and the PHP extensions `mbstring` and `pdo_sqlite`. JSON, PDO and OpenSSL are supplied by the official PHP image.

The Dockerfile contains no `COPY`, `ADD`, exposed port or downloaded bootstrap script. Application code is mounted read-only at runtime and is never embedded in the image.

The image uses the exact entrypoint:

```text
bash ops/ci/run-portable-focused-suite.sh
```

## Dockerfile fingerprint binding

A reusable image tag alone is not accepted as proof of image identity.

Before build or reuse, the wrapper calculates the tracked Git blob SHA of:

```text
ops/ci/Dockerfile.portable-focused
```

The build receives that SHA through `MGW_DOCKERFILE_BLOB_SHA` and stores it in the image label:

```text
org.mgw.portable-ci.dockerfile-blob-sha
```

An existing tagged image is reused only when this label exactly matches the current tracked Dockerfile blob. Otherwise the image is rebuilt. After build, the label is checked again.

The container is started by immutable Docker image ID (`sha256:...`), not by the mutable tag.

This binds the runtime image selection to the current Dockerfile while keeping the actual repository outside the build context.

## Zero-context build

The wrapper builds through Dockerfile stdin:

```bash
docker build \
  --build-arg MGW_DOCKERFILE_BLOB_SHA=<tracked-git-blob-sha> \
  --tag mgw-mini-games-world-ci:php83 \
  - < ops/ci/Dockerfile.portable-focused
```

The repository, `.git` directory and local files are not sent to the Docker daemon.

A forced rebuild is available:

```bash
MGW_CI_CONTAINER_REBUILD=1 \
  bash ops/ci/run-portable-focused-suite-container.sh
```

Accepted rebuild values are exactly `0` or `1`.

## Run command

```bash
bash ops/ci/run-portable-focused-suite-container.sh
```

Optional image tag:

```bash
MGW_CI_CONTAINER_IMAGE=private-registry/mgw-ci:php83 \
  bash ops/ci/run-portable-focused-suite-container.sh
```

Optional external artifact directory:

```bash
MGW_CI_CONTAINER_OUTPUT_DIR=/absolute/private/path/mgw-ci-artifacts \
  bash ops/ci/run-portable-focused-suite-container.sh
```

Optional bounded timeout:

```bash
MGW_CI_TIMEOUT_SECONDS=3600 \
  bash ops/ci/run-portable-focused-suite-container.sh
```

Accepted timeout range is 60–7200 seconds.

## Host preflight

Before build or run, the wrapper requires:

- Docker CLI and reachable daemon;
- `find`, Git, `id`, `mkdir` and `chmod`;
- checkout outside `public_html`;
- complete clean worktree including untracked files;
- tracked, real, non-symlink Dockerfile;
- valid exact Dockerfile Git blob SHA;
- absolute non-symlink artifact directory outside checkout and `public_html`;
- empty artifact directory;
- paths without commas or newline characters, because Docker `--mount` uses comma-delimited syntax;
- non-root host UID and GID.

The wrapper refuses to run the test container as root even when the host command was started by root.

## Runtime sandbox

The focused-suite container runs with:

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
- process runs as the current non-root host UID/GID;
- Git safe-directory is supplied through process environment;
- `GIT_OPTIONAL_LOCKS=0` prevents optional index writes;
- Docker socket is not mounted;
- host network is not used;
- privileged mode is forbidden;
- no secrets or database variables are supplied.

## Network boundary

Image build may use network access to obtain the base PHP image and Debian packages.

The actual focused-suite container uses `--network=none` and cannot contact GitHub, Hostinger, staging/production MySQL, external APIs or package registries.

## Evidence output

The host artifact directory must be empty before execution. The inherited portable runner then writes exactly:

```text
focused-suite.log
focused-suite-summary.json
focused-suite-manifest.json
```

The bundle can be verified offline:

```bash
php ops/ci/verify-portable-focused-suite-evidence.php \
  --evidence-dir=/absolute/private/path/mgw-ci-artifacts \
  --expected-commit=<exact-40-character-lowercase-commit>
```

The container layer does not weaken the exact bundle, manifest, log marker, commit or timeline checks from the verifier PR.

## Docker daemon boundary

Access to a Docker daemon is security-sensitive. This sub-MVP does not:

- install Docker;
- configure daemon permissions;
- mount `/var/run/docker.sock` into the test container;
- use Docker-in-Docker;
- start privileged containers;
- register a self-hosted runner;
- provision registry credentials.

A party with administrative control of the Docker host remains outside this application-level evidence boundary.

## Focused verification

```bash
bash ops/checks/db-primary-containerized-portable-ci-local.sh
```

This command does not contact Docker. It runs the inherited portable CI/evidence-verifier stack, shell-lints the wrapper and statically verifies:

- PHP 8.3 image and required extensions;
- no repository build context;
- exact Dockerfile blob label binding;
- rebuild/reuse label check;
- immutable image-ID execution;
- full tracked/untracked worktree guard;
- fresh artifact directory;
- non-root host identity;
- network/rootfs/capability/resource restrictions;
- read-only checkout and no Docker socket/secrets/DB exposure.

A real image build and container run remain a separate checkpoint on an isolated Docker-capable machine.

## Current safety status

- code only;
- no image built;
- no container started;
- no workflow dispatched;
- no runner registered;
- no Hostinger action;
- no database contact;
- no deployment;
- no production change;
- no merge.
