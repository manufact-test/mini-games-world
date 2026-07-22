#!/usr/bin/env bash
set -eEuo pipefail
umask 077

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PRIVATE_DIR="$(cd -- "$PROJECT_ROOT/.." && pwd -P)/_private_mgw"
INCIDENT_COMMIT="ad2a409d8f979a4b79b568efd6b81c5947659aaa"

fail() {
  local reason="$1"
  printf 'MGW_STAGING_API_INCIDENT_RECOVERY=BLOCKED\n'
  printf 'REASON=%s\n' "$reason"
  printf 'PERSISTENT_CONFIG_CHANGED=false\n'
  printf 'WEBHOOK_ALLOWED=false\n'
  printf 'CRON_CHANGED=false\n'
  printf 'PRODUCTION_CHANGED=false\n'
  exit 1
}

[[ -d "$PROJECT_ROOT/.git" && ! -L "$PROJECT_ROOT/.git" ]] \
  || fail 'deployed checkout metadata is unavailable'
[[ -d "$PRIVATE_DIR" && ! -L "$PRIVATE_DIR" ]] \
  || fail 'private staging directory is unavailable'

CURRENT_COMMIT="$(git -C "$PROJECT_ROOT" rev-parse --verify HEAD 2>/dev/null || true)"
[[ "$CURRENT_COMMIT" =~ ^[a-f0-9]{40}$ ]] \
  || fail 'deployed repository commit is invalid'
[[ -z "$(git -C "$PROJECT_ROOT" status --porcelain=v1 --untracked-files=all 2>/dev/null)" ]] \
  || fail 'deployed checkout is not clean'

PHP_BIN=''
declare -a PHP_CANDIDATES=(
  php php8.3 php83 lsphp83 /usr/bin/php8.3 /usr/local/bin/php8.3
  /usr/local/lsws/lsphp83/bin/php /usr/local/lsws/lsphp83/bin/lsphp
  /opt/alt/php83/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php
  /opt/php83/bin/php /opt/hostinger/php83/bin/php
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
  if "$resolved" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);' \
      >/dev/null 2>&1; then
    PHP_BIN="$resolved"
    break
  fi
done
[[ -n "$PHP_BIN" ]] || fail 'PHP 8.3 CLI binary was not found'
PHP_VERSION_SAFE="$($PHP_BIN -r 'echo PHP_VERSION;')"

RECEIPT=''
while IFS= read -r candidate; do
  [[ -n "$candidate" && -f "$candidate" && ! -L "$candidate" ]] || continue
  if "$PHP_BIN" -r '
    try {
        $d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        exit(is_array($d)
            && ($d["ok"] ?? null) === true
            && ($d["action"] ?? "") === "staging_read_only_checkpoint_receipt_written"
            && ($d["repository_commit"] ?? "") === $argv[2]
            && ($d["state_revision"] ?? null) === 1
            && ($d["mutating_smoke_authorized"] ?? null) === false
            && ($d["persistent_config_changed"] ?? null) === false
            && ($d["webhook_allowed"] ?? null) === false
            && ($d["cron_changed"] ?? null) === false
            && ($d["production_changed"] ?? null) === false ? 0 : 1);
    } catch (Throwable) {
        exit(1);
    }
  ' "$candidate" "$INCIDENT_COMMIT"; then
    RECEIPT="$candidate"
    break
  fi
done < <(
  find "$PRIVATE_DIR" -maxdepth 1 -type f \
    -name 'staging-read-only-checkpoint-receipt-*.json' \
    -printf '%T@ %p\n' 2>/dev/null \
  | sort -nr \
  | cut -d' ' -f2-
)
[[ -n "$RECEIPT" ]] || fail 'exact incident read-only receipt was not found'

RUN_ID="$(date -u '+%Y%m%dT%H%M%SZ')-$$-${RANDOM}"
REPORT="$PRIVATE_DIR/staging-api-economy-incident-recovery-$RUN_ID.json"
RUN_STATUS="$PRIVATE_DIR/staging-api-economy-incident-recovery-status-$RUN_ID.json"
for path in "$REPORT" "$RUN_STATUS"; do
  [[ ! -e "$path" && ! -L "$path" ]] \
    || fail 'fresh private incident recovery output path already exists'
done

if ! "$PHP_BIN" \
    "$PROJECT_ROOT/ops/runtime/recover-staging-api-mutating-smoke-economy-incident.php" \
    --receipt="$RECEIPT" \
    --output="$REPORT" \
    --expected-commit="$CURRENT_COMMIT" \
    > "$RUN_STATUS"; then
  chmod 0600 "$RUN_STATUS" 2>/dev/null || true
  cat "$RUN_STATUS" 2>/dev/null || true
  fail 'exact staging API economy incident recovery failed'
fi
chmod 0600 "$RUN_STATUS"

"$PHP_BIN" -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($d)
    || ($d["ok"] ?? null) !== true
    || ($d["action"] ?? "") !== "staging_api_economy_incident_recovery_passed"
    || ($d["repository_commit"] ?? "") !== $argv[2]
    || ($d["incident_repository_commit"] ?? "") !== $argv[3]
    || ($d["state_revision"] ?? null) !== 3
    || ($d["failed_event_recovered"] ?? null) !== true
    || ($d["economy_orphan_rows_removed"] ?? null) !== true
    || ($d["all_module_parity_verified"] ?? null) !== true
    || ($d["synthetic_identity_cleanup_verified"] ?? null) !== true
    || ($d["persistent_config_changed"] ?? null) !== false
    || ($d["webhook_allowed"] ?? null) !== false
    || ($d["cron_changed"] ?? null) !== false
    || ($d["production_changed"] ?? null) !== false) {
    exit(1);
}
$sha=$d["evidence_file_sha256"] ?? null;
if (!is_string($sha) || preg_match("/\\A[a-f0-9]{64}\\z/", $sha) !== 1) exit(1);
echo "MGW_STAGING_API_INCIDENT_RECOVERY=PASSED", PHP_EOL;
echo "PHP_VERSION=", $argv[4], PHP_EOL;
echo "REPOSITORY_COMMIT=", $d["repository_commit"], PHP_EOL;
echo "INCIDENT_REPOSITORY_COMMIT=", $d["incident_repository_commit"], PHP_EOL;
echo "STATE_REVISION=3", PHP_EOL;
echo "FAILED_EVENT_RECOVERED=true", PHP_EOL;
echo "ECONOMY_ORPHAN_ROWS_REMOVED=true", PHP_EOL;
echo "ALL_MODULE_PARITY=VERIFIED", PHP_EOL;
echo "SYNTHETIC_IDENTITY_CLEANUP_VERIFIED=true", PHP_EOL;
echo "PERSISTENT_CONFIG_CHANGED=false", PHP_EOL;
echo "WEBHOOK_ALLOWED=false", PHP_EOL;
echo "CRON_CHANGED=false", PHP_EOL;
echo "PRODUCTION_CHANGED=false", PHP_EOL;
echo "EVIDENCE_SAVED_PRIVATE=true", PHP_EOL;
' "$RUN_STATUS" "$CURRENT_COMMIT" "$INCIDENT_COMMIT" "$PHP_VERSION_SAFE" \
  || fail 'exact staging API economy incident recovery proof is invalid'
