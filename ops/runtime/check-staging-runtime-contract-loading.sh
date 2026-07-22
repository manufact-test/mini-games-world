#!/usr/bin/env bash
set -euo pipefail
umask 077

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PRIVATE_DIR="$(cd -- "$PROJECT_ROOT/.." && pwd -P)/_private_mgw"
BASE_CONFIG="$PRIVATE_DIR/config.php"
DIAGNOSTIC="$PROJECT_ROOT/ops/runtime/check-staging-runtime-contract-loading.php"

PHP_BIN=''
declare -a PHP_CANDIDATES=(
  php
  php8.3
  php83
  lsphp83
  /usr/bin/php8.3
  /usr/local/bin/php8.3
  /usr/local/lsws/lsphp83/bin/php
  /usr/local/lsws/lsphp83/bin/lsphp
  /opt/alt/php83/usr/bin/php
  /opt/cpanel/ea-php83/root/usr/bin/php
  /opt/php83/bin/php
  /opt/hostinger/php83/bin/php
)
for candidate in "${PHP_CANDIDATES[@]}"; do
  resolved=''
  if [[ "$candidate" == */* ]]; then
    [[ -x "$candidate" ]] || continue
    resolved="$candidate"
  else
    resolved="$(command -v "$candidate" 2>/dev/null || true)"
    [[ -n "$resolved" ]] || continue
  fi
  if "$resolved" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);' >/dev/null 2>&1; then
    PHP_BIN="$resolved"
    break
  fi
done

if [[ -z "$PHP_BIN" ]]; then
  printf 'RUNTIME_CONTRACT_LOADING=BLOCKED\n'
  printf 'CONTRACT=php83_runtime\n'
  printf 'DETAIL=Exact PHP 8.3 CLI runtime is unavailable.\n'
  exit 1
fi
if [[ ! -f "$BASE_CONFIG" || -L "$BASE_CONFIG" || ! -f "$DIAGNOSTIC" || -L "$DIAGNOSTIC" ]]; then
  printf 'RUNTIME_CONTRACT_LOADING=BLOCKED\n'
  printf 'CONTRACT=diagnostic_prerequisites\n'
  printf 'DETAIL=Runtime contract diagnostic prerequisites are unavailable.\n'
  exit 1
fi

RUN_ID="$(date -u '+%Y%m%dT%H%M%SZ')-$$-${RANDOM}"
REPORT_FILE="$PRIVATE_DIR/staging-runtime-contract-loading-$RUN_ID.json"
cleanup() {
  [[ -f "$REPORT_FILE" && ! -L "$REPORT_FILE" ]] && rm -f -- "$REPORT_FILE" || true
}
trap cleanup EXIT HUP INT TERM

unset MGW_DATABASE_CONFIG_FILE MGW_REHEARSAL_COMMIT_SHA
if MGW_CONFIG_FILE="$BASE_CONFIG" "$PHP_BIN" "$DIAGNOSTIC" > "$REPORT_FILE"; then
  diagnostic_exit=0
else
  diagnostic_exit=$?
fi
chmod 0600 "$REPORT_FILE"

"$PHP_BIN" -r '
try {
    $raw = file_get_contents($argv[1]);
    $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($data)
        || ($data["path_exposed"] ?? null) !== false
        || ($data["database_contacted"] ?? null) !== false
        || ($data["application_entrypoints_changed"] ?? null) !== false
        || ($data["cron_changed"] ?? null) !== false
        || ($data["production_changed"] ?? null) !== false
        || ($data["sensitive_identifiers_exposed"] ?? null) !== false) {
        exit(2);
    }
    if (($data["ok"] ?? null) === true
        && ($data["action"] ?? "") === "staging_runtime_contract_loading_verified") {
        echo "RUNTIME_CONTRACT_LOADING=PASSED", PHP_EOL;
        exit(0);
    }
    if (($data["ok"] ?? null) !== false
        || ($data["action"] ?? "") !== "staging_runtime_contract_loading_blocked_or_failed") {
        exit(2);
    }
    $contract = $data["failure_contract"] ?? null;
    $message = $data["error_message"] ?? null;
    if (!is_string($contract)
        || preg_match("/\\A[a-z0-9_]{1,80}\\z/", $contract) !== 1
        || !is_string($message)
        || $message === ""
        || strlen($message) > 300) {
        exit(2);
    }
    echo "RUNTIME_CONTRACT_LOADING=BLOCKED", PHP_EOL;
    echo "CONTRACT=", $contract, PHP_EOL;
    echo "DETAIL=", $message, PHP_EOL;
    exit(1);
} catch (Throwable) {
    exit(2);
}
' "$REPORT_FILE"
parse_exit=$?

if [[ "$parse_exit" -eq 2 ]]; then
  printf 'RUNTIME_CONTRACT_LOADING=BLOCKED\n'
  printf 'CONTRACT=diagnostic_report\n'
  printf 'DETAIL=Runtime contract diagnostic report is invalid.\n'
  exit 1
fi
exit "$diagnostic_exit"
