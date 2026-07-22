#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "$0")" && pwd -P)"
RUNNER="$PROJECT_ROOT/ops/runtime/run-staging-read-only-checkpoint.sh"
CONTRACT_DIAGNOSTIC="$PROJECT_ROOT/ops/runtime/check-staging-runtime-contract-loading.sh"

if bash "$RUNNER"; then
  exit 0
fi

PRIVATE_DIR="$(cd -- "$PROJECT_ROOT/.." && pwd -P)/_private_mgw"
LATEST_REPORT=''
if [[ -d "$PRIVATE_DIR" && ! -L "$PRIVATE_DIR" ]]; then
  LATEST_REPORT="$(
    find "$PRIVATE_DIR" -maxdepth 1 -type f \
      \( -name 'staging-read-only-preflight-*.json' -o -name 'staging-lifecycle-collector-*.json' \) \
      -printf '%T@ %p\n' 2>/dev/null \
      | sort -nr \
      | head -n 1 \
      | cut -d' ' -f2-
  )"
fi

if [[ -n "$LATEST_REPORT" && -f "$LATEST_REPORT" && ! -L "$LATEST_REPORT" ]]; then
  php -r '
  try {
      $raw = file_get_contents($argv[1]);
      $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
      if (!is_array($data)
          || ($data["ok"] ?? null) !== false
          || ($data["path_exposed"] ?? null) !== false
          || ($data["production_changed"] ?? null) !== false
          || ($data["sensitive_identifiers_exposed"] ?? null) !== false) {
          exit(0);
      }

      $action = $data["action"] ?? null;
      if ($action === "api_lifecycle_evidence_v4_blocked_or_failed") {
          if (($data["session_enabled_by_evidence"] ?? null) !== false
              || ($data["finalizer_registered_by_evidence"] ?? null) !== false
              || ($data["application_entrypoints_changed"] ?? null) !== false
              || ($data["cron_changed"] ?? null) !== false) {
              exit(0);
          }
      } elseif ($action !== "staging_read_only_prerequisites_blocked_or_failed") {
          exit(0);
      }

      $message = $data["error_message"] ?? null;
      if (!is_string($message) || $message === "" || strlen($message) > 500) exit(0);
      echo "DETAIL=", $message, PHP_EOL;
  } catch (Throwable) {
      exit(0);
  }
  ' "$LATEST_REPORT" 2>/dev/null || true

  if [[ -f "$CONTRACT_DIAGNOSTIC" && ! -L "$CONTRACT_DIAGNOSTIC" ]]; then
    if php -r '
    try {
        $raw = file_get_contents($argv[1]);
        $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        exit(is_array($data)
            && ($data["action"] ?? "") === "api_lifecycle_evidence_v4_blocked_or_failed"
            && ($data["failure_stage"] ?? "") === "runtime_contract_loading"
            ? 0
            : 1);
    } catch (Throwable) {
        exit(1);
    }
    ' "$LATEST_REPORT" >/dev/null 2>&1; then
      bash "$CONTRACT_DIAGNOSTIC" || true
    fi
  fi
fi

exit 1
