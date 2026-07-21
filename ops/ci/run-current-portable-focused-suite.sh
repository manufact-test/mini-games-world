#!/usr/bin/env bash
set -euo pipefail
umask 077

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"
SUITE_SCRIPT="ops/checks/db-primary-current-portable-validation-local.sh"
MANIFEST_FILE="ops/ci/current-portable-suite-manifest.json"
OUTPUT_DIR="${MGW_CI_OUTPUT_DIR:-${RUNNER_TEMP:-${TMPDIR:-/tmp}}/mgw-current-ci-focused}"
TIMEOUT_SECONDS="${MGW_CI_TIMEOUT_SECONDS:-2700}"

fail() {
  printf 'current-portable-ci preflight failed: %s\n' "$1" >&2
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

for command_name in bash git date find cp tee cat chmod mkdir grep "$PHP_BIN"; do
  command -v "$command_name" >/dev/null 2>&1 \
    || fail "required command is unavailable: $command_name"
done

cd "$PROJECT_ROOT"
[[ -f "$SUITE_SCRIPT" ]] || fail "focused suite is unavailable: $SUITE_SCRIPT"
[[ ! -L "$SUITE_SCRIPT" ]] || fail 'focused suite must not be a symbolic link.'
[[ -f "$MANIFEST_FILE" ]] || fail "focused suite manifest is unavailable: $MANIFEST_FILE"
[[ ! -L "$MANIFEST_FILE" ]] || fail 'focused suite manifest must not be a symbolic link.'

PHP_VERSION_ID="$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')"
[[ "$PHP_VERSION_ID" =~ ^[0-9]+$ ]] || fail 'PHP_VERSION_ID is invalid.'
(( PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 )) \
  || fail 'current portable CI requires PHP 8.3.x.'

for extension_name in json pdo pdo_sqlite openssl mbstring; do
  "$PHP_BIN" -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension_name" \
    || fail "required PHP extension is unavailable: $extension_name"
done

MANIFEST_META="$("$PHP_BIN" -r '
$path = $argv[1];
$data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data)
    || ($data["contract_version"] ?? "") !== "v2-current-db-primary-focused-suite"
    || ($data["entrypoint"] ?? "") !== "ops/checks/db-primary-current-portable-validation-local.sh"
    || ($data["expected_unique_script_count"] ?? null) !== 14) {
    exit(2);
}
echo hash_file("sha256", $path), ":", $data["expected_unique_script_count"];
' "$MANIFEST_FILE")" || fail 'focused suite manifest is invalid.'
MANIFEST_SHA256="${MANIFEST_META%%:*}"
MANIFEST_SCRIPT_COUNT="${MANIFEST_META##*:}"
[[ "$MANIFEST_SHA256" =~ ^[a-f0-9]{64}$ ]] || fail 'focused suite manifest SHA-256 is invalid.'
[[ "$MANIFEST_SCRIPT_COUNT" == '14' ]] || fail 'focused suite manifest script count is invalid.'

CHECKOUT_BEFORE="$(git status --porcelain=v1 --untracked-files=all)"
[[ -z "$CHECKOUT_BEFORE" ]] || fail 'checkout changes are present before the suite.'

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
[[ -z "$(find "$CANONICAL_OUTPUT_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]] \
  || fail 'portable CI artifact directory must be empty before the run.'
chmod 0700 "$CANONICAL_OUTPUT_DIR" 2>/dev/null || true
LOG_FILE="$CANONICAL_OUTPUT_DIR/current-focused-suite.log"
SUMMARY_FILE="$CANONICAL_OUTPUT_DIR/current-focused-suite-summary.json"
MANIFEST_ARTIFACT_FILE="$CANONICAL_OUTPUT_DIR/current-focused-suite-manifest.json"
[[ ! -L "$LOG_FILE" && ! -L "$SUMMARY_FILE" && ! -L "$MANIFEST_ARTIFACT_FILE" ]] \
  || fail 'portable CI artifact files must not be symbolic links.'
: > "$LOG_FILE"
cp "$MANIFEST_FILE" "$MANIFEST_ARTIFACT_FILE"
COPIED_MANIFEST_SHA256="$("$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$MANIFEST_ARTIFACT_FILE")"
[[ "$COPIED_MANIFEST_SHA256" == "$MANIFEST_SHA256" ]] \
  || fail 'copied focused suite manifest fingerprint does not match.'
chmod 0600 "$LOG_FILE" "$MANIFEST_ARTIFACT_FILE" 2>/dev/null || true

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

CHECKOUT_AFTER="$(git status --porcelain=v1 --untracked-files=all)"
CHECKOUT_UNCHANGED=true
if [[ -n "$CHECKOUT_AFTER" ]]; then
  CHECKOUT_UNCHANGED=false
  if (( SUITE_EXIT_CODE == 0 )); then
    SUITE_EXIT_CODE=3
  fi
  printf 'current-portable-ci blocker: focused suite changed the repository checkout.\n' \
    | tee -a "$LOG_FILE" >&2
fi

FINISHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
FINISH_EPOCH="$(date +%s)"
DURATION_SECONDS=$(( FINISH_EPOCH - START_EPOCH ))
LOG_SHA256="$("$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$LOG_FILE")"
PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;')"

export MGW_CI_SUMMARY_FILE="$SUMMARY_FILE"
export MGW_CI_COMMIT_SHA="$COMMIT_SHA"
export MGW_CI_PHP_VERSION="$PHP_VERSION"
export MGW_CI_STARTED_AT="$STARTED_AT"
export MGW_CI_FINISHED_AT="$FINISHED_AT"
export MGW_CI_DURATION_SECONDS="$DURATION_SECONDS"
export MGW_CI_EXIT_CODE="$SUITE_EXIT_CODE"
export MGW_CI_LOG_SHA256="$LOG_SHA256"
export MGW_CI_CHECKOUT_UNCHANGED="$CHECKOUT_UNCHANGED"
export MGW_CI_MANIFEST_SHA256="$MANIFEST_SHA256"
export MGW_CI_MANIFEST_SCRIPT_COUNT="$MANIFEST_SCRIPT_COUNT"

"$PHP_BIN" -r '
$summary = [
    "ok" => (int)getenv("MGW_CI_EXIT_CODE") === 0,
    "report_type" => "mvp-14.8.6s-current-portable-validation",
    "suite" => "db-primary-current-portable-validation-local",
    "suite_manifest_sha256" => getenv("MGW_CI_MANIFEST_SHA256"),
    "suite_manifest_script_count" => (int)getenv("MGW_CI_MANIFEST_SCRIPT_COUNT"),
    "repository_commit" => getenv("MGW_CI_COMMIT_SHA"),
    "php_version" => getenv("MGW_CI_PHP_VERSION"),
    "started_at_utc" => getenv("MGW_CI_STARTED_AT"),
    "finished_at_utc" => getenv("MGW_CI_FINISHED_AT"),
    "duration_seconds" => (int)getenv("MGW_CI_DURATION_SECONDS"),
    "exit_code" => (int)getenv("MGW_CI_EXIT_CODE"),
    "log_sha256" => getenv("MGW_CI_LOG_SHA256"),
    "repository_checkout_unchanged" => getenv("MGW_CI_CHECKOUT_UNCHANGED") === "true",
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

printf '\nCurrent portable focused-suite summary:\n'
cat "$SUMMARY_FILE"
printf 'Current portable CI artifacts prepared.\n'

exit "$SUITE_EXIT_CODE"
