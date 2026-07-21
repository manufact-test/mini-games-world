#!/usr/bin/env bash
set -euo pipefail
umask 077

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"
RUNNER_SCRIPT="ops/ci/run-current-portable-focused-suite.sh"
VERIFIER_SCRIPT="ops/ci/verify-current-portable-focused-suite-evidence.php"
MAX_AGE_SECONDS="${MGW_CI_VERIFY_MAX_AGE_SECONDS:-3600}"
BASE_TEMP="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
SESSION_ROOT="${MGW_CI_SESSION_ROOT:-}"

fail() {
  printf 'current-portable-ci session failed: %s\n' "$1" >&2
  exit 2
}

case "$PROJECT_ROOT" in
  */public_html|*/public_html/*)
    fail 'repository checkout must not be inside public_html.'
    ;;
esac

for command_name in bash git date mkdir chmod find "$PHP_BIN"; do
  command -v "$command_name" >/dev/null 2>&1 \
    || fail "required command is unavailable: $command_name"
done

[[ "$MAX_AGE_SECONDS" =~ ^[0-9]+$ ]] \
  || fail 'MGW_CI_VERIFY_MAX_AGE_SECONDS must be an integer.'
(( MAX_AGE_SECONDS >= 60 && MAX_AGE_SECONDS <= 604800 )) \
  || fail 'MGW_CI_VERIFY_MAX_AGE_SECONDS must be between 60 and 604800.'

cd "$PROJECT_ROOT"
[[ -f "$RUNNER_SCRIPT" && ! -L "$RUNNER_SCRIPT" ]] \
  || fail 'current portable runner is unavailable or unsafe.'
[[ -f "$VERIFIER_SCRIPT" && ! -L "$VERIFIER_SCRIPT" ]] \
  || fail 'current portable evidence verifier is unavailable or unsafe.'

CHECKOUT_STATUS="$(git status --porcelain=v1 --untracked-files=all)"
[[ -z "$CHECKOUT_STATUS" ]] || fail 'checkout must be clean before creating a CI session.'
COMMIT_SHA="$(git rev-parse --verify HEAD)"
[[ "$COMMIT_SHA" =~ ^[a-f0-9]{40}$ ]] || fail 'checkout commit SHA is invalid.'

if [[ -z "$SESSION_ROOT" ]]; then
  [[ "$BASE_TEMP" = /* ]] || fail 'temporary base directory must be absolute.'
  [[ -d "$BASE_TEMP" && ! -L "$BASE_TEMP" ]] \
    || fail 'temporary base directory is unavailable or unsafe.'
  SESSION_ROOT="$BASE_TEMP/mgw-current-ci-session-$(date -u +%Y%m%dT%H%M%SZ)-${BASHPID}"
fi
SESSION_ROOT="${SESSION_ROOT%/}"
[[ "$SESSION_ROOT" = /* ]] || fail 'MGW_CI_SESSION_ROOT must be an absolute path.'
[[ ! -e "$SESSION_ROOT" && ! -L "$SESSION_ROOT" ]] \
  || fail 'CI session root must not already exist.'
case "$SESSION_ROOT" in
  "$PROJECT_ROOT"|"$PROJECT_ROOT"/*)
    fail 'CI session root must stay outside the repository checkout.'
    ;;
  */public_html|*/public_html/*)
    fail 'CI session root must stay outside public_html.'
    ;;
esac

mkdir "$SESSION_ROOT" || fail 'CI session root could not be created.'
chmod 0700 "$SESSION_ROOT" || fail 'CI session root permissions could not be secured.'
SESSION_ROOT="$(cd "$SESSION_ROOT" && pwd -P)"
EVIDENCE_DIR="$SESSION_ROOT/evidence"
VERIFICATION_FILE="$SESSION_ROOT/current-focused-suite-verification.json"

export MGW_CI_OUTPUT_DIR="$EVIDENCE_DIR"

bash "$RUNNER_SCRIPT"

[[ -d "$EVIDENCE_DIR" && ! -L "$EVIDENCE_DIR" ]] \
  || fail 'current portable evidence directory is unavailable or unsafe.'
[[ ! -e "$VERIFICATION_FILE" && ! -L "$VERIFICATION_FILE" ]] \
  || fail 'verification output path is already occupied or unsafe.'

"$PHP_BIN" "$VERIFIER_SCRIPT" \
  --evidence-dir="$EVIDENCE_DIR" \
  --expected-commit="$COMMIT_SHA" \
  --max-age-seconds="$MAX_AGE_SECONDS" \
  > "$VERIFICATION_FILE"
chmod 0600 "$VERIFICATION_FILE" \
  || fail 'verification report permissions could not be secured.'

VERIFICATION_MODE="$("$PHP_BIN" -r '
$mode = fileperms($argv[1]);
if (!is_int($mode)) exit(2);
printf("%03o", $mode & 0777);
' "$VERIFICATION_FILE")" || fail 'verification report permissions could not be read.'
[[ "$VERIFICATION_MODE" == '600' ]] \
  || fail 'verification report must have exact mode 0600.'

SESSION_ENTRIES="$(find "$SESSION_ROOT" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)"
EXPECTED_ENTRIES=$'current-focused-suite-verification.json\nevidence'
[[ "$SESSION_ENTRIES" == "$EXPECTED_ENTRIES" ]] \
  || fail 'CI session root must contain only evidence and its verification report.'

printf '\nCurrent portable verification report:\n'
cat "$VERIFICATION_FILE"
printf 'Current portable PHP 8.3 session verified.\n'
printf 'Session root: %s\n' "$SESSION_ROOT"
