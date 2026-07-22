#!/usr/bin/env bash
set -eEuo pipefail
umask 077

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PRIVATE_DIR="$(cd -- "$PROJECT_ROOT/.." && pwd -P)/_private_mgw"
BASE_CONFIG="$PRIVATE_DIR/config.php"

fail() {
  printf 'MGW_STAGING_READ_ONLY_CHECKPOINT=BLOCKED\n' >&2
  printf 'REASON=%s\n' "$1" >&2
  printf 'PRODUCTION_CHANGED=false\n' >&2
  printf 'CRON_CHANGED=false\n' >&2
  printf 'WEBHOOK_ALLOWED=false\n' >&2
  exit 1
}

for command_name in bash git date chmod rm cat stat; do
  command -v "$command_name" >/dev/null 2>&1 || fail "required command is unavailable: $command_name"
done

if [[ -L "$PROJECT_ROOT" || ! -d "$PROJECT_ROOT" ]]; then
  fail 'project root is unavailable or symbolic'
fi
if [[ ! -d "$PRIVATE_DIR" || -L "$PRIVATE_DIR" ]]; then
  fail 'external private staging directory is unavailable or symbolic'
fi
if [[ ! -f "$BASE_CONFIG" || -L "$BASE_CONFIG" ]]; then
  fail 'external private staging config is unavailable or symbolic'
fi

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
  fail 'PHP 8.3 CLI binary was not found; website PHP and SSH PHP are separate on this hosting plan'
fi

PHP_VERSION_SAFE="$($PHP_BIN -r 'echo PHP_VERSION;' 2>/dev/null)"
if [[ ! "$PHP_VERSION_SAFE" =~ ^8\.3\.[0-9]+ ]]; then
  fail 'detected CLI runtime is not exact PHP 8.3.x'
fi

CURRENT_COMMIT="$(git -C "$PROJECT_ROOT" rev-parse --verify HEAD 2>/dev/null || true)"
if [[ ! "$CURRENT_COMMIT" =~ ^[a-f0-9]{40}$ ]]; then
  fail 'deployed repository commit is unavailable'
fi
if [[ -n "$(git -C "$PROJECT_ROOT" status --porcelain=v1 --untracked-files=all 2>/dev/null)" ]]; then
  fail 'deployed repository checkout is not clean'
fi

RUN_ID="$(date -u '+%Y%m%dT%H%M%SZ')-$$-${RANDOM}"
PREFLIGHT_FILE="$PRIVATE_DIR/staging-read-only-preflight-$RUN_ID.json"
PREFLIGHT_VALUES_FILE="$PRIVATE_DIR/staging-read-only-preflight-values-$RUN_ID.txt"
OVERLAY_FILE="$PRIVATE_DIR/staging-read-only-evidence-overlay-$RUN_ID.php"
COLLECTOR_STATUS_FILE="$PRIVATE_DIR/staging-lifecycle-collector-$RUN_ID.json"
COLLECTOR_VALUES_FILE="$PRIVATE_DIR/staging-lifecycle-collector-values-$RUN_ID.txt"
EVIDENCE_FILE="$PRIVATE_DIR/staging-lifecycle-evidence-v4-$RUN_ID.json"
REPORT_FILE="$PRIVATE_DIR/staging-api-read-only-smoke-$RUN_ID.json"
VERIFICATION_FILE="$PRIVATE_DIR/staging-api-read-only-smoke-verification-$RUN_ID.json"

for path in \
  "$PREFLIGHT_FILE" \
  "$PREFLIGHT_VALUES_FILE" \
  "$OVERLAY_FILE" \
  "$COLLECTOR_STATUS_FILE" \
  "$COLLECTOR_VALUES_FILE" \
  "$EVIDENCE_FILE" \
  "$REPORT_FILE" \
  "$VERIFICATION_FILE"; do
  [[ ! -e "$path" && ! -L "$path" ]] || fail 'fresh private output path already exists'
done

cleanup_temporary_files() {
  for temporary_path in \
    "${OVERLAY_FILE:-}" \
    "${PREFLIGHT_VALUES_FILE:-}" \
    "${COLLECTOR_VALUES_FILE:-}"; do
    if [[ -n "$temporary_path" && -f "$temporary_path" && ! -L "$temporary_path" ]]; then
      rm -f -- "$temporary_path" || true
    fi
  done
}
trap cleanup_temporary_files EXIT HUP INT TERM

unset MGW_CONFIG_FILE MGW_DATABASE_CONFIG_FILE MGW_REHEARSAL_COMMIT_SHA
"$PHP_BIN" "$PROJECT_ROOT/ops/runtime/check-staging-read-only-prerequisites.php" \
  --expected-commit="$CURRENT_COMMIT" \
  > "$PREFLIGHT_FILE" || fail 'strict staging prerequisite check failed'
chmod 0600 "$PREFLIGHT_FILE"

"$PHP_BIN" -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($d) || ($d["ok"] ?? null) !== true || ($d["action"] ?? "") !== "staging_read_only_prerequisites_verified") exit(1);
foreach (["repository_commit", "database_identity_fingerprint"] as $k) {
    $v=$d[$k] ?? null;
    if (!is_string($v)) exit(1);
    echo $v, "\n";
}
' "$PREFLIGHT_FILE" > "$PREFLIGHT_VALUES_FILE" || fail 'staging prerequisite report could not be parsed'
chmod 0600 "$PREFLIGHT_VALUES_FILE"
mapfile -t PREFLIGHT_VALUES < "$PREFLIGHT_VALUES_FILE" || fail 'staging prerequisite report values could not be loaded'
rm -f -- "$PREFLIGHT_VALUES_FILE"
PREFLIGHT_VALUES_FILE=''
if [[ "${#PREFLIGHT_VALUES[@]}" -ne 2 ]]; then
  fail 'staging prerequisite report is incomplete'
fi
PREFLIGHT_COMMIT="${PREFLIGHT_VALUES[0]}"
DATABASE_IDENTITY="${PREFLIGHT_VALUES[1]}"
if [[ "$PREFLIGHT_COMMIT" != "$CURRENT_COMMIT" || ! "$DATABASE_IDENTITY" =~ ^[a-f0-9]{64}$ ]]; then
  fail 'staging prerequisite identities are invalid'
fi

APPROVAL_EXPIRES_AT="$($PHP_BIN -r 'echo gmdate("Y-m-d\\TH:i:s\\Z", time()+900);')"
cat > "$OVERLAY_FILE" <<EOF
<?php
declare(strict_types=1);
\$config = require __DIR__ . '/config.php';
if (!is_array(\$config)) {
    throw new RuntimeException('Base staging config must return an array.');
}
\$config['staging_db_primary_evidence'] = [
    'enabled' => true,
    'expected_database_identity_fingerprint' => '$DATABASE_IDENTITY',
    'expected_repository_commit' => '$CURRENT_COMMIT',
    'approval_expires_at_utc' => '$APPROVAL_EXPIRES_AT',
];
return \$config;
EOF
chmod 0600 "$OVERLAY_FILE"
if [[ "$(stat -c '%a' "$OVERLAY_FILE")" != '600' ]]; then
  fail 'temporary evidence overlay permissions are unsafe'
fi

MGW_CONFIG_FILE="$OVERLAY_FILE" \
MGW_REHEARSAL_COMMIT_SHA="$CURRENT_COMMIT" \
"$PHP_BIN" "$PROJECT_ROOT/ops/runtime/collect-staging-db-primary-lifecycle-evidence.php" \
  --output="$EVIDENCE_FILE" \
  --max-events=20 \
  > "$COLLECTOR_STATUS_FILE" || fail 'fresh lifecycle evidence v4 collection failed'
chmod 0600 "$COLLECTOR_STATUS_FILE"

"$PHP_BIN" -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($d) || ($d["ok"] ?? null) !== true || ($d["action"] ?? "") !== "api_lifecycle_evidence_v4_collected") exit(1);
foreach (["repository_commit", "database_identity_fingerprint", "manifest_fingerprint"] as $k) {
    $v=$d[$k] ?? null;
    if (!is_string($v)) exit(1);
    echo $v, "\n";
}
' "$COLLECTOR_STATUS_FILE" > "$COLLECTOR_VALUES_FILE" || fail 'lifecycle evidence result could not be parsed'
chmod 0600 "$COLLECTOR_VALUES_FILE"
mapfile -t COLLECTOR_VALUES < "$COLLECTOR_VALUES_FILE" || fail 'lifecycle evidence values could not be loaded'
rm -f -- "$COLLECTOR_VALUES_FILE"
COLLECTOR_VALUES_FILE=''
if [[ "${#COLLECTOR_VALUES[@]}" -ne 3 ]]; then
  fail 'lifecycle evidence result is incomplete'
fi
EVIDENCE_COMMIT="${COLLECTOR_VALUES[0]}"
EVIDENCE_DATABASE_IDENTITY="${COLLECTOR_VALUES[1]}"
EVIDENCE_FINGERPRINT="${COLLECTOR_VALUES[2]}"
if [[ "$EVIDENCE_COMMIT" != "$CURRENT_COMMIT"
      || "$EVIDENCE_DATABASE_IDENTITY" != "$DATABASE_IDENTITY"
      || ! "$EVIDENCE_FINGERPRINT" =~ ^[a-f0-9]{64}$ ]]; then
  fail 'lifecycle evidence identities do not match the deployed staging checkpoint'
fi

rm -f -- "$OVERLAY_FILE"
OVERLAY_FILE=''

(
  unset MGW_CONFIG_FILE MGW_DATABASE_CONFIG_FILE
  MGW_REHEARSAL_COMMIT_SHA="$CURRENT_COMMIT" \
  "$PHP_BIN" "$PROJECT_ROOT/ops/runtime/run-staging-db-primary-api-read-only-smoke.php" \
    --evidence="$EVIDENCE_FILE" \
    --ttl-seconds=300 \
    > "$REPORT_FILE"
) || fail 'CLI-only staging API read-only smoke failed'
chmod 0600 "$REPORT_FILE"

"$PHP_BIN" "$PROJECT_ROOT/ops/runtime/verify-staging-db-primary-api-read-only-smoke-evidence.php" \
  --report="$REPORT_FILE" \
  --expected-commit="$CURRENT_COMMIT" \
  --expected-database-identity="$DATABASE_IDENTITY" \
  --expected-evidence-fingerprint="$EVIDENCE_FINGERPRINT" \
  > "$VERIFICATION_FILE" || fail '43-field read-only smoke report verification failed'
chmod 0600 "$VERIFICATION_FILE"

"$PHP_BIN" -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($d) || ($d["ok"] ?? null) !== true || ($d["action"] ?? "") !== "staging_api_read_only_smoke_evidence_verified") exit(1);
' "$VERIFICATION_FILE" || fail 'read-only smoke verification result is invalid'

rm -f -- "$PREFLIGHT_FILE" "$COLLECTOR_STATUS_FILE"

printf 'MGW_STAGING_READ_ONLY_CHECKPOINT=PASSED\n'
printf 'PHP_VERSION=%s\n' "$PHP_VERSION_SAFE"
printf 'REPOSITORY_COMMIT=%s\n' "$CURRENT_COMMIT"
printf 'LIFECYCLE_EVIDENCE_V4=VERIFIED\n'
printf 'CLI_READ_ONLY_SMOKE=PASSED\n'
printf 'REPORT_43_FIELDS=VERIFIED\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WORKER_TICKS=0\n'
printf 'WEBHOOK_ALLOWED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
printf 'MUTATING_SMOKE_AUTHORIZED=false\n'