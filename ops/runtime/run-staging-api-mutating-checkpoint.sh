#!/usr/bin/env bash
set -eEuo pipefail
umask 077

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PRIVATE_DIR="$(cd -- "$PROJECT_ROOT/.." && pwd -P)/_private_mgw"
CURRENT_COMMIT=''

fail() {
  local reason="$1"
  printf 'MGW_STAGING_API_MUTATING_SMOKE=BLOCKED\n'
  printf 'REASON=%s\n' "$reason"
  printf 'PERSISTENT_CONFIG_CHANGED=false\n'
  printf 'WEBHOOK_ALLOWED=false\n'
  printf 'CRON_CHANGED=false\n'
  printf 'PRODUCTION_CHANGED=false\n'
  exit 1
}

latest_file() {
  local pattern="$1"
  {
    find "$PRIVATE_DIR" -maxdepth 1 -type f -name "$pattern" -printf '%T@ %p\n' 2>/dev/null || true
  } | sort -nr | head -n 1 | cut -d' ' -f2-
}

[[ -d "$PROJECT_ROOT/.git" && ! -L "$PROJECT_ROOT/.git" ]] || fail 'deployed checkout metadata is unavailable'
[[ -d "$PRIVATE_DIR" && ! -L "$PRIVATE_DIR" ]] || fail 'private staging directory is unavailable'
CURRENT_COMMIT="$(git -C "$PROJECT_ROOT" rev-parse --verify HEAD 2>/dev/null || true)"
[[ "$CURRENT_COMMIT" =~ ^[a-f0-9]{40}$ ]] || fail 'deployed repository commit is invalid'
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
  if "$resolved" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);' >/dev/null 2>&1; then
    PHP_BIN="$resolved"
    break
  fi
done
[[ -n "$PHP_BIN" ]] || fail 'PHP 8.3 CLI binary was not found'
PHP_VERSION_SAFE="$($PHP_BIN -r 'echo PHP_VERSION;')"

RECEIPT="$(latest_file 'staging-read-only-checkpoint-receipt-*.json')"
ROLLBACK_REPORT="$(latest_file 'staging-bounded-mutating-smoke-20*.json')"
LIFECYCLE_EVIDENCE="$(latest_file 'staging-lifecycle-evidence-v4-*.json')"
[[ -n "$RECEIPT" && -f "$RECEIPT" && ! -L "$RECEIPT" ]] \
  || fail 'fresh read-only checkpoint receipt was not found'
[[ -n "$ROLLBACK_REPORT" && -f "$ROLLBACK_REPORT" && ! -L "$ROLLBACK_REPORT" ]] \
  || fail 'fresh rollback-only mutating smoke report was not found'
[[ -n "$LIFECYCLE_EVIDENCE" && -f "$LIFECYCLE_EVIDENCE" && ! -L "$LIFECYCLE_EVIDENCE" ]] \
  || fail 'fresh lifecycle evidence v4 was not found'

RUN_ID="$(date -u '+%Y%m%dT%H%M%SZ')-$$-${RANDOM}"
REPORT="$PRIVATE_DIR/staging-api-mutating-smoke-$RUN_ID.json"
RUN_STATUS="$PRIVATE_DIR/staging-api-mutating-smoke-run-status-$RUN_ID.json"
VERIFY_STATUS="$PRIVATE_DIR/staging-api-mutating-smoke-verification-$RUN_ID.json"
IDENTITIES="$PRIVATE_DIR/staging-api-mutating-smoke-identities-$RUN_ID.txt"
for path in "$REPORT" "$RUN_STATUS" "$VERIFY_STATUS" "$IDENTITIES"; do
  [[ ! -e "$path" && ! -L "$path" ]] || fail 'fresh private API mutating smoke output path already exists'
done

cleanup_temporary_files() {
  if [[ -n "${IDENTITIES:-}" && -f "$IDENTITIES" && ! -L "$IDENTITIES" ]]; then
    rm -f -- "$IDENTITIES" || true
  fi
}
trap cleanup_temporary_files EXIT HUP INT TERM

"$PHP_BIN" -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($d)
    || ($d["ok"] ?? null) !== true
    || ($d["action"] ?? "") !== "staging_read_only_checkpoint_receipt_written"
    || ($d["repository_commit"] ?? "") !== $argv[2]
    || ($d["mutating_smoke_authorized"] ?? null) !== false
    || ($d["persistent_config_changed"] ?? null) !== false
    || ($d["webhook_allowed"] ?? null) !== false
    || ($d["cron_changed"] ?? null) !== false
    || ($d["production_changed"] ?? null) !== false) exit(1);
foreach (["database_identity_fingerprint", "evidence_fingerprint"] as $field) {
    $value=$d[$field] ?? null;
    if (!is_string($value) || preg_match("/\\A[a-f0-9]{64}\\z/", $value) !== 1) exit(1);
    echo $value, PHP_EOL;
}
' "$RECEIPT" "$CURRENT_COMMIT" > "$IDENTITIES" \
  || fail 'read-only receipt identities are invalid'
chmod 0600 "$IDENTITIES"
mapfile -t VALUES < "$IDENTITIES" || fail 'read-only receipt identities could not be loaded'
[[ "${#VALUES[@]}" -eq 2 ]] || fail 'read-only receipt identities are incomplete'
DATABASE_IDENTITY="${VALUES[0]}"
EVIDENCE_FINGERPRINT="${VALUES[1]}"
RECEIPT_SHA="$($PHP_BIN -r '$h=hash_file("sha256", $argv[1]); if (!is_string($h)) exit(1); echo $h;' "$RECEIPT")" \
  || fail 'read-only receipt fingerprint could not be calculated'
ROLLBACK_SHA="$($PHP_BIN -r '$h=hash_file("sha256", $argv[1]); if (!is_string($h)) exit(1); echo $h;' "$ROLLBACK_REPORT")" \
  || fail 'rollback-only report fingerprint could not be calculated'

if ! "$PHP_BIN" "$PROJECT_ROOT/ops/runtime/run-staging-api-mutating-smoke.php" \
  --receipt="$RECEIPT" \
  --rollback-report="$ROLLBACK_REPORT" \
  --evidence="$LIFECYCLE_EVIDENCE" \
  --output="$REPORT" \
  --expected-commit="$CURRENT_COMMIT" \
  --ttl-seconds=300 \
  > "$RUN_STATUS"; then
  chmod 0600 "$RUN_STATUS" 2>/dev/null || true
  cat "$RUN_STATUS" 2>/dev/null || true
  fail 'real API mutating smoke execution failed'
fi
chmod 0600 "$RUN_STATUS"

if ! "$PHP_BIN" "$PROJECT_ROOT/ops/runtime/verify-staging-api-mutating-smoke-evidence.php" \
  --report="$REPORT" \
  --expected-commit="$CURRENT_COMMIT" \
  --expected-database-identity="$DATABASE_IDENTITY" \
  --expected-receipt-sha256="$RECEIPT_SHA" \
  --expected-rollback-report-sha256="$ROLLBACK_SHA" \
  --expected-lifecycle-evidence-fingerprint="$EVIDENCE_FINGERPRINT" \
  --max-age-seconds=1800 \
  > "$VERIFY_STATUS"; then
  chmod 0600 "$VERIFY_STATUS" 2>/dev/null || true
  cat "$RUN_STATUS" 2>/dev/null || true
  cat "$VERIFY_STATUS" 2>/dev/null || true
  fail 'real API mutating smoke evidence verification failed'
fi
chmod 0600 "$VERIFY_STATUS"

"$PHP_BIN" -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($d)
    || ($d["ok"] ?? null) !== true
    || ($d["action"] ?? "") !== "staging_api_mutating_smoke_evidence_verified"
    || ($d["report_field_count"] ?? null) !== 60
    || ($d["api_state_revision"] ?? 0) !== ($d["baseline_state_revision"] ?? -2) + 1
    || ($d["cleanup_state_revision"] ?? 0) !== ($d["baseline_state_revision"] ?? -3) + 2
    || ($d["final_state_revision"] ?? 0) !== ($d["cleanup_state_revision"] ?? -1)
    || ($d["committed_api_state_write_count"] ?? null) !== 1
    || ($d["committed_cleanup_state_write_count"] ?? null) !== 1
    || ($d["committed_outbox_event_count"] ?? null) !== 2
    || ($d["state_restored_to_baseline"] ?? null) !== true
    || ($d["all_module_projection_restored"] ?? null) !== true
    || ($d["synthetic_identity_cleanup_verified"] ?? null) !== true
    || ($d["json_rollback_source_unchanged"] ?? null) !== true
    || ($d["persistent_config_changed"] ?? null) !== false
    || ($d["webhook_allowed"] ?? null) !== false
    || ($d["cron_changed"] ?? null) !== false
    || ($d["production_changed"] ?? null) !== false) exit(1);
echo "MGW_STAGING_API_MUTATING_SMOKE=PASSED", PHP_EOL;
echo "PHP_VERSION=", $argv[2], PHP_EOL;
echo "REPOSITORY_COMMIT=", $d["repository_commit"], PHP_EOL;
echo "REPORT_60_FIELDS=VERIFIED", PHP_EOL;
echo "REAL_API_ENTRYPOINT=bot/api.php", PHP_EOL;
echo "API_ACTION=bootstrap", PHP_EOL;
echo "API_STATE_WRITES=1", PHP_EOL;
echo "CLEANUP_STATE_WRITES=1", PHP_EOL;
echo "COMMITTED_OUTBOX_EVENTS=2", PHP_EOL;
echo "STATE_RESTORED_TO_BASELINE=true", PHP_EOL;
echo "ALL_MODULE_PROJECTION_RESTORED=true", PHP_EOL;
echo "SYNTHETIC_IDENTITY_CLEANUP_VERIFIED=true", PHP_EOL;
echo "JSON_ROLLBACK_SOURCE_UNCHANGED=true", PHP_EOL;
echo "PERSISTENT_CONFIG_CHANGED=false", PHP_EOL;
echo "WEBHOOK_ALLOWED=false", PHP_EOL;
echo "CRON_CHANGED=false", PHP_EOL;
echo "PRODUCTION_CHANGED=false", PHP_EOL;
echo "EVIDENCE_SAVED_PRIVATE=true", PHP_EOL;
' "$VERIFY_STATUS" "$PHP_VERSION_SAFE" || fail 'real API mutating smoke final proof is invalid'
