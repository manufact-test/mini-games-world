#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
IMAGE_NAME="${MGW_CI_CONTAINER_IMAGE:-mgw-mini-games-world-ci:php83}"
OUTPUT_DIR="${MGW_CI_CONTAINER_OUTPUT_DIR:-$(dirname "$PROJECT_ROOT")/mgw-ci-artifacts}"
TIMEOUT_SECONDS="${MGW_CI_TIMEOUT_SECONDS:-2400}"
REBUILD_IMAGE="${MGW_CI_CONTAINER_REBUILD:-0}"
DOCKERFILE="ops/ci/Dockerfile.portable-focused"

fail() {
  printf 'containerized-ci preflight failed: %s\n' "$1" >&2
  exit 2
}

case "$PROJECT_ROOT" in
  */public_html|*/public_html/*)
    fail 'CI checkout must not be inside public_html.'
    ;;
esac

command -v docker >/dev/null 2>&1 || fail 'Docker CLI is unavailable.'
docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable.'
[[ -f "$PROJECT_ROOT/$DOCKERFILE" ]] || fail 'Portable CI Dockerfile is unavailable.'
[[ ! -L "$PROJECT_ROOT/$DOCKERFILE" ]] || fail 'Portable CI Dockerfile must not be a symbolic link.'
[[ "$TIMEOUT_SECONDS" =~ ^[0-9]+$ ]] || fail 'MGW_CI_TIMEOUT_SECONDS must be an integer.'
(( TIMEOUT_SECONDS >= 60 && TIMEOUT_SECONDS <= 7200 )) \
  || fail 'MGW_CI_TIMEOUT_SECONDS must be between 60 and 7200.'
[[ "$REBUILD_IMAGE" == '0' || "$REBUILD_IMAGE" == '1' ]] \
  || fail 'MGW_CI_CONTAINER_REBUILD must be 0 or 1.'

cd "$PROJECT_ROOT"
[[ -z "$(git status --porcelain=v1 --untracked-files=no)" ]] \
  || fail 'tracked checkout changes are present.'

[[ "$OUTPUT_DIR" = /* ]] || fail 'MGW_CI_CONTAINER_OUTPUT_DIR must be an absolute path.'
[[ ! -L "$OUTPUT_DIR" ]] || fail 'MGW_CI_CONTAINER_OUTPUT_DIR must not be a symbolic link.'
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
chmod 0700 "$OUTPUT_DIR" 2>/dev/null || true

if [[ "$REBUILD_IMAGE" == '1' ]] \
  || ! docker image inspect "$IMAGE_NAME" >/dev/null 2>&1; then
  docker build \
    --tag "$IMAGE_NAME" \
    - < "$DOCKERFILE"
fi

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
[[ "$HOST_UID" =~ ^[0-9]+$ && "$HOST_GID" =~ ^[0-9]+$ ]] \
  || fail 'host UID or GID is invalid.'

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
  --env MGW_CI_OUTPUT_DIR=/artifacts \
  --env MGW_CI_TIMEOUT_SECONDS="$TIMEOUT_SECONDS" \
  --mount type=bind,src="$PROJECT_ROOT",dst=/workspace,readonly \
  --mount type=bind,src="$OUTPUT_DIR",dst=/artifacts \
  --tmpfs /tmp:rw,noexec,nosuid,nodev,size=268435456 \
  "$IMAGE_NAME"
