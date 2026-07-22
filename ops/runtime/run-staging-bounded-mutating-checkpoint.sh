#!/usr/bin/env bash
set -eEuo pipefail
umask 077

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PRIVATE_DIR="$(cd -- "$PROJECT_ROOT/.." && pwd -P)/_private_mgw"
CURRENT_COMMIT=''

fail() {
  local reason="$1"
  printf 'MGW_STAGING_BOUNDED_MUTATING_SMOKE=BLOCKED\n'
  printf 'REASON=%s\n' "$reason"
  printf 'PERSISTENT_CONFIG_CHANGED=false\n'
  printf 'WEBHOOK_ALLOWED=false\n'
  printf 'CRON_CHANGED=false\n'
  printf 'PRODUCTION_CHANGED=false\n'
  exit 1
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

RECEIPT="$({
  find "$PRIVATE_DIR" -maxdepth 1 -type f \
    -name 'staging-read-only-checkpoint-receipt-*.json' \
    -printf '%T@ %p\n' 2>/dev/null || true
} | sort -nr | head -n 1 | cut -d' ' -f2-)"
[[ -n "$RECEIPT" && -f "$RECEIPT" && ! -L "$RECEIPT" ]] \
  || fail 'fresh read-only checkpoint receipt was not found'

RUN_ID="$(date -u '+%Y%m%dT%H%M%SZ')-$$-${RANDOM}"
APPROVAL="$PRIVATE_DIR/staging-bounded-mutating-smoke-approval-$RUN_ID.json"
CONSUMED="$PRIVATE_DIR/staging-bounded-mutating-smoke-approval-consumed-$RUN_ID.json"
REPORT="$PRIVATE_DIR/staging-bounded-mutating-smoke-$RUN_ID.json"
PREP_STATUS="$PRIVATE_DIR/staging-bounded-mutating-smoke-prepare-status-$RUN_ID.json"
RUN_STATUS="$PRIVATE_DIR/staging-bounded-mutating-smoke-run-status-$RUN_ID.json"
VERIFY_STATUS="$PRIVATE_DIR/staging-bounded-mutating-smoke-verification-$RUN_ID.json"
META="$PRIVATE_DIR/staging-bounded-mutating-smoke-meta-$RUN_ID.txt"

for path in "$APPROVAL" "$CONSUMED" "$REPORT" "$PREP_STATUS" "$RUN_STATUS" "$VERIFY_STATUS" "$META"; do
  [[ ! -e "$path" && ! -L "$path" ]] || fail 'fresh private mutating smoke output path already exists'
done

cleanup_temporary_files() {
  for path in "${META:-}" "${PREP_STATUS:-}"; do
    if [[ -n "$path" && -f "$path" && ! -L "$path" ]]; then
      rm -f -- "$path" || true
    fi
  done
}
trap cleanup_temporary_files EXIT HUP INT TERM

if ! "$PHP_BIN" "$PROJECT_ROOT/ops/runtime/prepare-staging-bounded-mutating-smoke.php" \
  --receipt="$RECEIPT" \
  --output="$APPROVAL" \
  --expected-commit="$CURRENT_COMMIT" \
  --ttl-seconds=300 \
  > "$PREP_STATUS"; then
  chmod 0600 "$PREP_STATUS" 2>/dev/null || true
  cat "$PREP_STATUS" 2>/dev/null || true
  fail 'bounded mutating smoke preparation failed'
fi
chmod 0600 "$PREP_STATUS"

"$PHP_BIN" -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($d)
    || ($d["ok"] ?? null) !== true
    || ($d["action"] ?? "") !== "staging_bounded_mutating_smoke_prepared") exit(1);
foreach (["challenge", "approval_id", "database_identity_fingerprint", "read_only_report_sha256", "repository_commit"] as $field) {
    $value=$d[$field] ?? null;
    if (!is_string($value) || $value === "") exit(1);
    echo $value, PHP_EOL;
}
' "$PREP_STATUS" > "$META" || fail 'bounded mutating smoke preparation metadata is invalid'
chmod 0600 "$META"
mapfile -t VALUES < "$META" || fail 'bounded mutating smoke preparation metadata could not be loaded'
[[ "${#VALUES[@]}" -eq 5 ]] || fail 'bounded mutating smoke preparation metadata is incomplete'
CHALLENGE="${VALUES[0]}"
APPROVAL_ID="${VALUES[1]}"
DATABASE_IDENTITY="${VALUES[2]}"
READ_ONLY_REPORT_SHA="${VALUES[3]}"
PREPARED_COMMIT="${VALUES[4]}"
[[ "$PREPARED_COMMIT" == "$CURRENT_COMMIT" ]] || fail 'prepared commit does not match deployed commit'
RECEIPT_SHA="$($PHP_BIN -r '$h=hash_file("sha256", $argv[1]); if (!is_string($h)) exit(1); echo $h;' "$RECEIPT")" \
  || fail 'read-only receipt fingerprint could not be calculated'

if ! "$PHP_BIN" "$PROJECT_ROOT/ops/runtime/run-staging-bounded-mutating-smoke.php" \
  --receipt="$RECEIPT" \
  --approval="$APPROVAL" \
  --consumed-approval="$CONSUMED" \
  --challenge="$CHALLENGE" \
  --output="$REPORT" \
  --expected-commit="$CURRENT_COMMIT" \
  > "$RUN_STATUS"; then
  chmod 0600 "$RUN_STATUS" 2>/dev/null || true
  cat "$RUN_STATUS" 2>/dev/null || true
  fail 'bounded mutating smoke execution failed'
fi
chmod 0600 "$RUN_STATUS"

if ! "$PHP_BIN" "$PROJECT_ROOT/ops/runtime/verify-staging-bounded-mutating-smoke-evidence.php" \
  --report="$REPORT" \
  --expected-commit="$CURRENT_COMMIT" \
  --expected-database-identity="$DATABASE_IDENTITY" \
  --expected-read-only-report-sha256="$READ_ONLY_REPORT_SHA" \
  --expected-receipt-sha256="$RECEIPT_SHA" \
  --expected-approval-id="$APPROVAL_ID" \
  --max-age-seconds=1800 \
  > "$VERIFY_STATUS"; then
  chmod 0600 "$VERIFY_STATUS" 2>/dev/null || true
  cat "$RUN_STATUS" 2>/dev/null || true
  cat "$VERIFY_STATUS" 2>/dev/null || true
  fail 'bounded mutating smoke evidence verification failed'
fi
chmod 0600 "$VERIFY_STATUS"

"$PHP_BIN" -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($d)
    || ($d["ok"] ?? null) !== true
    || ($d["action"] ?? "") !== "staging_bounded_mutating_smoke_evidence_verified"
    || ($d["report_field_count"] ?? null) !== 43
    || ($d["worker_tick_count"] ?? null) !== 1
    || ($d["temporary_state_write_count"] ?? null) !== 1
    || ($d["committed_state_write_count"] ?? null) !== 0
    || ($d["committed_outbox_event_count"] ?? null) !== 0
    || ($d["rollback_verified"] ?? null) !== true
    || ($d["persistent_config_changed"] ?? null) !== false
    || ($d["webhook_allowed"] ?? null) !== false
    || ($d["cron_changed"] ?? null) !== false
    || ($d["production_changed"] ?? null) !== false) exit(1);
echo "MGW_STAGING_BOUNDED_MUTATING_SMOKE=PASSED", PHP_EOL;
echo "PHP_VERSION=", $argv[2], PHP_EOL;
echo "REPOSITORY_COMMIT=", $d["repository_commit"], PHP_EOL;
echo "REPORT_43_FIELDS=VERIFIED", PHP_EOL;
echo "WORKER_TICKS=1", PHP_EOL;
echo "TEMPORARY_STATE_WRITES=1", PHP_EOL;
echo "ROLLBACK_VERIFIED=true", PHP_EOL;
echo "COMMITTED_STATE_WRITES=0", PHP_EOL;
echo "COMMITTED_OUTBOX_EVENTS=0", PHP_EOL;
echo "PERSISTENT_CONFIG_CHANGED=false", PHP_EOL;
echo "WEBHOOK_ALLOWED=false", PHP_EOL;
echo "CRON_CHANGED=false", PHP_EOL;
echo "PRODUCTION_CHANGED=false", PHP_EOL;
echo "EVIDENCE_SAVED_PRIVATE=true", PHP_EOL;
' "$VERIFY_STATUS" "$PHP_VERSION_SAFE" || fail 'bounded mutating smoke final proof is invalid'
