#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  verify-mvp14-9-ops-compliance.sh --evidence-root PATH

Verifies the ORIGINAL MVP-14.9 operations requirements using an operator-provided
evidence bundle. This script does not contact a database, production, Cron, or any
remote service. It only validates already-collected evidence and fails closed.

Required files under PATH:
  manifest.env
  checkpoint-verification.txt
  offhost-proof.txt
  restore-proof.txt
  slow-query-evidence.txt
  load-evidence.txt
  schedule-evidence.txt

Required manifest.env keys:
  environment
  exact_ref
  verified_at_unix
  checkpoint_created_unix
  checkpoint_sha256
  offhost_sha256
  restore_result
  restore_elapsed_seconds
  rpo_target_seconds
  rto_target_seconds
  slow_query_result
  load_result
  daily_backup_schedule
  monthly_restore_schedule
  quarterly_load_schedule
EOF
}

fail() {
  printf 'MVP-14.9 OPS COMPLIANCE: FAIL: %s\n' "$*" >&2
  exit 1
}

is_uint() {
  [[ "$1" =~ ^[0-9]+$ ]]
}

trim_cr() {
  printf '%s' "${1%$'\r'}"
}

EVIDENCE_ROOT=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --evidence-root)
      [[ $# -ge 2 ]] || fail '--evidence-root requires a path'
      EVIDENCE_ROOT="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "unknown argument: $1"
      ;;
  esac
done

[[ -n "$EVIDENCE_ROOT" ]] || fail 'missing --evidence-root'
[[ -d "$EVIDENCE_ROOT" ]] || fail "evidence root is not a directory: $EVIDENCE_ROOT"

MANIFEST="$EVIDENCE_ROOT/manifest.env"
[[ -f "$MANIFEST" ]] || fail 'missing manifest.env'

for evidence_file in \
  checkpoint-verification.txt \
  offhost-proof.txt \
  restore-proof.txt \
  slow-query-evidence.txt \
  load-evidence.txt \
  schedule-evidence.txt; do
  [[ -s "$EVIDENCE_ROOT/$evidence_file" ]] || fail "missing or empty evidence file: $evidence_file"
done

declare -A values=()
allowed_key() {
  case "$1" in
    environment|exact_ref|verified_at_unix|checkpoint_created_unix|checkpoint_sha256|offhost_sha256|restore_result|restore_elapsed_seconds|rpo_target_seconds|rto_target_seconds|slow_query_result|load_result|daily_backup_schedule|monthly_restore_schedule|quarterly_load_schedule)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

while IFS= read -r raw_line || [[ -n "$raw_line" ]]; do
  line="$(trim_cr "$raw_line")"
  [[ -z "$line" || "$line" == \#* ]] && continue
  [[ "$line" == *=* ]] || fail "invalid manifest line (expected key=value): $line"
  key="${line%%=*}"
  value="${line#*=}"
  [[ "$key" =~ ^[a-z0-9_]+$ ]] || fail "invalid manifest key: $key"
  allowed_key "$key" || fail "unknown manifest key: $key"
  [[ -z "${values[$key]+x}" ]] || fail "duplicate manifest key: $key"
  [[ -n "$value" ]] || fail "empty manifest value: $key"
  values[$key]="$value"
done < "$MANIFEST"

required_keys=(
  environment
  exact_ref
  verified_at_unix
  checkpoint_created_unix
  checkpoint_sha256
  offhost_sha256
  restore_result
  restore_elapsed_seconds
  rpo_target_seconds
  rto_target_seconds
  slow_query_result
  load_result
  daily_backup_schedule
  monthly_restore_schedule
  quarterly_load_schedule
)

for key in "${required_keys[@]}"; do
  [[ -n "${values[$key]+x}" ]] || fail "missing manifest key: $key"
done

case "${values[environment]}" in
  staging|production) ;;
  *) fail 'environment must be staging or production' ;;
esac

[[ "${values[exact_ref]}" =~ ^[0-9a-f]{40}$ ]] || fail 'exact_ref must be a full 40-character git SHA'
[[ "${values[checkpoint_sha256]}" =~ ^[0-9a-fA-F]{64}$ ]] || fail 'checkpoint_sha256 must be SHA-256 hex'
[[ "${values[offhost_sha256]}" =~ ^[0-9a-fA-F]{64}$ ]] || fail 'offhost_sha256 must be SHA-256 hex'

checkpoint_sha="${values[checkpoint_sha256],,}"
offhost_sha="${values[offhost_sha256],,}"
[[ "$checkpoint_sha" == "$offhost_sha" ]] || fail 'off-host copy digest does not match canonical checkpoint digest'

for numeric_key in verified_at_unix checkpoint_created_unix restore_elapsed_seconds rpo_target_seconds rto_target_seconds; do
  is_uint "${values[$numeric_key]}" || fail "$numeric_key must be an unsigned integer"
done

(( values[verified_at_unix] >= values[checkpoint_created_unix] )) || fail 'verified_at_unix predates checkpoint_created_unix'
backup_age=$(( values[verified_at_unix] - values[checkpoint_created_unix] ))
(( backup_age <= values[rpo_target_seconds] )) || fail "RPO missed: backup age ${backup_age}s > target ${values[rpo_target_seconds]}s"
(( values[restore_elapsed_seconds] <= values[rto_target_seconds] )) || fail "RTO missed: restore ${values[restore_elapsed_seconds]}s > target ${values[rto_target_seconds]}s"

[[ "${values[restore_result]}" == 'PASS' ]] || fail 'restore_result must be PASS'
[[ "${values[slow_query_result]}" == 'PASS' ]] || fail 'slow_query_result must be PASS'
[[ "${values[load_result]}" == 'PASS' ]] || fail 'load_result must be PASS'
[[ "${values[daily_backup_schedule]}" == 'PROVEN' ]] || fail 'daily_backup_schedule must be PROVEN'
[[ "${values[monthly_restore_schedule]}" == 'PROVEN' ]] || fail 'monthly_restore_schedule must be PROVEN'
[[ "${values[quarterly_load_schedule]}" == 'PROVEN' ]] || fail 'quarterly_load_schedule must be PROVEN'

printf 'MVP-14.9 OPS COMPLIANCE: PASS\n'
printf 'environment=%s\n' "${values[environment]}"
printf 'exact_ref=%s\n' "${values[exact_ref]}"
printf 'checkpoint_sha256=%s\n' "$checkpoint_sha"
printf 'backup_age_seconds=%s\n' "$backup_age"
printf 'rpo_target_seconds=%s\n' "${values[rpo_target_seconds]}"
printf 'restore_elapsed_seconds=%s\n' "${values[restore_elapsed_seconds]}"
printf 'rto_target_seconds=%s\n' "${values[rto_target_seconds]}"
printf 'offhost_copy=VERIFIED_BY_DIGEST\n'
printf 'restore=PASS\n'
printf 'slow_query_evidence=PASS\n'
printf 'load_evidence=PASS\n'
printf 'daily_backup_schedule=PROVEN\n'
printf 'monthly_restore_schedule=PROVEN\n'
printf 'quarterly_load_schedule=PROVEN\n'
