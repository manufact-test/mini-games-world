#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
SUITE_SCRIPT="ops/checks/db-primary-portable-self-hosted-ci-local.sh"
MANIFEST_FILE="ops/ci/portable-focused-suite-manifest.json"
OUTPUT_DIR="${MGW_CI_OUTPUT_DIR:-${RUNNER_TEMP:-${TMPDIR:-/tmp}}/mgw-ci-focused}"
TIMEOUT_SECONDS="${MGW_CI_TIMEOUT_SECONDS:-2400}"

fail() {
  printf 'portable-ci preflight failed: %s\n' "$1" >&2
  exit 2
}

case "$PROJECT_ROOT" in
  */public_html|*/public_html/*)
    fail 'CI checkout must not be inside public_html.'
    ;;
esac

[[ "$OUTPUT_DIR" = /* ]] || fail 'MGW_CI_OUTPUT_DIR must be an absolute path.'
[[ ! -L "$OUTPUT_DIR" ]] || fail 'MGW_CI_OUTPUT_DIR must not be a symbolic link.'
[[ "$TIMEOUT_SECONDS" =~ ^[0-9]+$ ]] || fail 'MGW_CI_TIMEOUT_SECONDS must be an integer.'
(( TIMEOUT_SECONDS >= 60 && TIMEOUT_SECONDS <= 7200 )) \
  || fail 'MGW_CI_TIMEOUT_SECONDS must be between 60 and 7200.'

for command_name in bash git "$PHP_BIN"; do
  command -v "$command_name" >/dev/null 2>&1 \
    || fail "required command is unavailable: $command_name"
done

cd "$PROJECT_ROOT"
[[ -f "$SUITE_SCRIPT" ]] || fail "focused suite is unavailable: $SUITE_SCRIPT"
[[ ! -L "$SUITE_SCRIPT" ]] || fail 'focused suite must not be a symbolic link.'
[[ -f "$MANIFEST_FILE" ]] || fail "focused suite manifest is unavailable: $MANIFEST_FILE"
[[ ! -L "$MANIFEST_FILE" ]] || fail 'focused suite manifest must not be a symbolic link.'

PHP_VERSION_ID="$($PHP_BIN -r 'echo PHP_VERSION_ID;')"
[[ "$PHP_VERSION_ID" =~ ^[0-9]+$ ]] || fail 'PHP_VERSION_ID is invalid.'
(( PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 )) \
  || fail 'portable CI requires PHP 8.3.x.'

for extension_name in json pdo pdo_sqlite openssl mbstring; do
  "$PHP_BIN" -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension_name" \
    || fail "required PHP extension is unavailable: $extension_name"
done

MANIFEST_META="$($PHP_BIN -r '
$path = $argv[1];
$data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data)
    || ($data["contract_version"] ?? "") !== "v1-portable-db-primary-focused-suite"
    || (int)($data["expected_unique_script_count"] ?? 0) < 1) {
    exit(2);
}
echo hash_file("sha256", $path), ":", (int)$data["expected_unique_script_count"];
' "$MANIFEST_FILE")" || fail 'focused suite manifest is invalid.'
MANIFEST_SHA256="${MANIFEST_META%%:*}"
MANIFEST_SCRIPT_COUNT="${MANIFEST_META##*:}"
[[ "$MANIFEST_SHA256" =~ ^[a-f0-9]{64}$ ]] || fail 'focused suite manifest SHA-256 is invalid.'
[[ "$MANIFEST_SCRIPT_COUNT" =~ ^[0-9]+$ ]] || fail 'focused suite manifest script count is invalid.'

TRACKED_BEFORE="$(git status --porcelain=v1 --untracked-files=no)"
[[ -z "$TRACKED_BEFORE" ]] || fail 'tracked checkout changes are present before the suite.'

COMMIT_SHA="$(git rev-parse --verify HEAD)"
[[ "$COMMIT_SHA" =~ ^[a-f0-9]{40}$ ]] || fail 'checkout commit SHA is invalid.'

mkdir -p "$OUTPUT_DIR"
CANONICAL_OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd -P)"
case "$CANONICAL_OUTPUT_DIR" in
  "$PROJECT_ROOT"|"$PROJECT_ROOT"/*)
    fail 'portable CI artifacts must stay outside the repository checkout.'
    ;;
  */public_html|*/public_html/*)
    fail 'portable CI artifacts must not be stored inside public_html.'
    ;;
esac
chmod 0700 "$CANONICAL_OUTPUT_DIR" 2>/dev/null || true
LOG_FILE="$CANONICAL_OUTPUT_DIR/focused-suite.log"
SUMMARY_FILE="$CANONICAL_OUTPUT_DIR/focused-suite-summary.json"
[[ ! -L "$LOG_FILE" && ! -L "$SUMMARY_FILE" ]] \
  || fail 'portable CI artifact files must not be symbolic links.'
: > "$LOG_FILE"
chmod 0600 "$LOG_FILE" 2>/dev/null || true

STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
START_EPOCH="$(date +%s)"

set +e
if command -v timeout >/dev/null 2>&1 \
  && timeout --version 2>/dev/null | grep -q 'GNU coreutils'; then
  timeout --signal=TERM --kill-after=15 "${TIMEOUT_SECONDS}s" \
    bash "$SUITE_SCRIPT" 2>&1 | tee "$LOG_FILE"
  SUITE_EXIT_CODE=${PIPESTATUS[0]}
else
  bash "$SUITE_SCRIPT" 2>&1 | tee "$LOG_FILE"
  SUITE_EXIT_CODE=${PIPESTATUS[0]}
fi
set -e

TRACKED_AFTER="$(git status --porcelain=v1 --untracked-files=no)"
WORKTREE_UNCHANGED=true
if [[ -n "$TRACKED_AFTER" ]]; then
  WORKTREE_UNCHANGED=false
  if (( SUITE_EXIT_CODE == 0 )); then
    SUITE_EXIT_CODE=3
  fi
  printf 'portable-ci blocker: focused suite changed tracked files.\n' | tee -a "$LOG_FILE" >&2
fi

FINISHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
FINISH_EPOCH="$(date +%s)"
DURATION_SECONDS=$(( FINISH_EPOCH - START_EPOCH ))
LOG_SHA256="$($PHP_BIN -r 'echo hash_file("sha256", $argv[1]);' "$LOG_FILE")"
PHP_VERSION="$($PHP_BIN -r 'echo PHP_VERSION;')"

export MGW_CI_SUMMARY_FILE="$SUMMARY_FILE"
export MGW_CI_COMMIT_SHA="$COMMIT_SHA"
export MGW_CI_PHP_VERSION="$PHP_VERSION"
export MGW_CI_STARTED_AT="$STARTED_AT"
export MGW_CI_FINISHED_AT="$FINISHED_AT"
export MGW_CI_DURATION_SECONDS="$DURATION_SECONDS"
export MGW_CI_EXIT_CODE="$SUITE_EXIT_CODE"
export MGW_CI_LOG_SHA256="$LOG_SHA256"
export MGW_CI_WORKTREE_UNCHANGED="$WORKTREE_UNCHANGED"
export MGW_CI_MANIFEST_SHA256="$MANIFEST_SHA256"
export MGW_CI_MANIFEST_SCRIPT_COUNT="$MANIFEST_SCRIPT_COUNT"

"$PHP_BIN" -r '
$summary = [
    "ok" => (int)getenv("MGW_CI_EXIT_CODE") === 0,
    "report_type" => "mvp-14.8.6o-portable-self-hosted-focused-suite",
    "suite" => "db-primary-portable-self-hosted-ci-local",
    "suite_manifest_sha256" => getenv("MGW_CI_MANIFEST_SHA256"),
    "suite_manifest_script_count" => (int)getenv("MGW_CI_MANIFEST_SCRIPT_COUNT"),
    "repository_commit" => getenv("MGW_CI_COMMIT_SHA"),
    "php_version" => getenv("MGW_CI_PHP_VERSION"),
    "started_at_utc" => getenv("MGW_CI_STARTED_AT"),
    "finished_at_utc" => getenv("MGW_CI_FINISHED_AT"),
    "duration_seconds" => (int)getenv("MGW_CI_DURATION_SECONDS"),
    "exit_code" => (int)getenv("MGW_CI_EXIT_CODE"),
    "log_sha256" => getenv("MGW_CI_LOG_SHA256"),
    "tracked_worktree_unchanged" => getenv("MGW_CI_WORKTREE_UNCHANGED") === "true",
    "live_database_contacted" => false,
    "private_config_required" => false,
    "application_entrypoints_changed" => false,
    "cron_changed" => false,
    "deployment_performed" => false,
    "production_changed" => false,
    "sensitive_identifiers_exposed" => false,
];
file_put_contents(
    getenv("MGW_CI_SUMMARY_FILE"),
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    LOCK_EX
);
' || fail 'could not write portable CI summary.'
chmod 0600 "$SUMMARY_FILE" 2>/dev/null || true

printf '\nPortable focused-suite summary:\n'
cat "$SUMMARY_FILE"
printf 'Portable CI artifacts prepared.\n'

exit "$SUITE_EXIT_CODE"
