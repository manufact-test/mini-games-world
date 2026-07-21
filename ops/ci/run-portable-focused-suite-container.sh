#!/usr/bin/env bash
set -euo pipefail
umask 077

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
IMAGE_NAME="${MGW_CI_CONTAINER_IMAGE:-mgw-mini-games-world-ci:php83}"
OUTPUT_DIR="${MGW_CI_CONTAINER_OUTPUT_DIR:-$(dirname "$PROJECT_ROOT")/mgw-ci-artifacts}"
TIMEOUT_SECONDS="${MGW_CI_TIMEOUT_SECONDS:-2400}"
REBUILD_IMAGE="${MGW_CI_CONTAINER_REBUILD:-0}"
DOCKERFILE="ops/ci/Dockerfile.portable-focused"
IMAGE_LABEL_KEY="org.mgw.portable-ci.dockerfile-blob-sha"

fail() {
  printf 'containerized-ci preflight failed: %s\n' "$1" >&2
  exit 2
}

case "$PROJECT_ROOT" in
  */public_html|*/public_html/*)
    fail 'CI checkout must not be inside public_html.'
    ;;
  *','*|*$'\n'*|*$'\r'*)
    fail 'CI checkout path contains unsupported delimiter characters.'
    ;;
esac

[[ -n "$IMAGE_NAME" && "$IMAGE_NAME" != *[[:space:]]* ]] \
  || fail 'MGW_CI_CONTAINER_IMAGE is invalid.'

for command_name in docker find git id mkdir chmod; do
  command -v "$command_name" >/dev/null 2>&1 \
    || fail "required host command is unavailable: $command_name"
done

docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable.'
[[ -f "$PROJECT_ROOT/$DOCKERFILE" ]] || fail 'Portable CI Dockerfile is unavailable.'
[[ ! -L "$PROJECT_ROOT/$DOCKERFILE" ]] || fail 'Portable CI Dockerfile must not be a symbolic link.'
[[ "$TIMEOUT_SECONDS" =~ ^[0-9]+$ ]] || fail 'MGW_CI_TIMEOUT_SECONDS must be an integer.'
(( TIMEOUT_SECONDS >= 60 && TIMEOUT_SECONDS <= 7200 )) \
  || fail 'MGW_CI_TIMEOUT_SECONDS must be between 60 and 7200.'
[[ "$REBUILD_IMAGE" == '0' || "$REBUILD_IMAGE" == '1' ]] \
  || fail 'MGW_CI_CONTAINER_REBUILD must be 0 or 1.'

cd "$PROJECT_ROOT"
[[ -z "$(git status --porcelain=v1 --untracked-files=all)" ]] \
  || fail 'checkout changes are present.'
git ls-files --error-unmatch "$DOCKERFILE" >/dev/null 2>&1 \
  || fail 'Portable CI Dockerfile must be tracked.'
DOCKERFILE_BLOB_SHA="$(git hash-object "$DOCKERFILE")"
[[ "$DOCKERFILE_BLOB_SHA" =~ ^[a-f0-9]{40}$ ]] \
  || fail 'Portable CI Dockerfile blob SHA is invalid.'

[[ "$OUTPUT_DIR" = /* ]] || fail 'MGW_CI_CONTAINER_OUTPUT_DIR must be an absolute path.'
[[ ! -L "$OUTPUT_DIR" ]] || fail 'MGW_CI_CONTAINER_OUTPUT_DIR must not be a symbolic link.'
case "$OUTPUT_DIR" in
  *','*|*$'\n'*|*$'\r'*)
    fail 'container artifact path contains unsupported delimiter characters.'
    ;;
esac
mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd -P)"
case "$OUTPUT_DIR" in
  "$PROJECT_ROOT"|"$PROJECT_ROOT"/*)
    fail 'container artifacts must stay outside the repository checkout.'
    ;;
  */public_html|*/public_html/*)
    fail 'container artifacts must not be stored inside public_html.'
    ;;
esac
[[ -z "$(find "$OUTPUT_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]] \
  || fail 'container artifact directory must be empty before the run.'
chmod 0700 "$OUTPUT_DIR" 2>/dev/null || true

image_label() {
  docker image inspect \
    --format "{{ index .Config.Labels \"${IMAGE_LABEL_KEY}\" }}" \
    "$IMAGE_NAME" 2>/dev/null || true
}

CURRENT_IMAGE_LABEL="$(image_label)"
if [[ "$REBUILD_IMAGE" == '1' || "$CURRENT_IMAGE_LABEL" != "$DOCKERFILE_BLOB_SHA" ]]; then
  docker build \
    --build-arg "MGW_DOCKERFILE_BLOB_SHA=$DOCKERFILE_BLOB_SHA" \
    --tag "$IMAGE_NAME" \
    - < "$DOCKERFILE"
fi

CURRENT_IMAGE_LABEL="$(image_label)"
[[ "$CURRENT_IMAGE_LABEL" == "$DOCKERFILE_BLOB_SHA" ]] \
  || fail 'Portable CI image is not bound to the current Dockerfile blob.'
IMAGE_ID="$(docker image inspect --format '{{.Id}}' "$IMAGE_NAME")"
[[ "$IMAGE_ID" =~ ^sha256:[a-f0-9]{64}$ ]] \
  || fail 'Portable CI image ID is invalid.'

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
[[ "$HOST_UID" =~ ^[0-9]+$ && "$HOST_GID" =~ ^[0-9]+$ ]] \
  || fail 'host UID or GID is invalid.'
(( HOST_UID > 0 && HOST_GID > 0 )) \
  || fail 'containerized CI must not run with root host identity.'

exec docker run \
  --rm \
  --network=none \
  --read-only \
  --cap-drop=ALL \
  --security-opt=no-new-privileges \
  --pids-limit=256 \
  --memory=1g \
  --cpus=2 \
  --user "$HOST_UID:$HOST_GID" \
  --env HOME=/tmp \
  --env GIT_CONFIG_COUNT=1 \
  --env GIT_CONFIG_KEY_0=safe.directory \
  --env GIT_CONFIG_VALUE_0=/workspace \
  --env GIT_OPTIONAL_LOCKS=0 \
  --env MGW_CI_OUTPUT_DIR=/artifacts \
  --env MGW_CI_TIMEOUT_SECONDS="$TIMEOUT_SECONDS" \
  --mount "type=bind,src=$PROJECT_ROOT,dst=/workspace,readonly" \
  --mount "type=bind,src=$OUTPUT_DIR,dst=/artifacts" \
  --tmpfs /tmp:rw,noexec,nosuid,nodev,size=268435456 \
  "$IMAGE_ID"
