#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERIFIER="$ROOT/ops/runtime/verify-mvp14-9-ops-compliance.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() {
  printf 'MVP-14.9 OPS COMPLIANCE SELF-TEST: FAIL: %s\n' "$*" >&2
  exit 1
}

make_fixture() {
  local dir="$1"
  mkdir -p "$dir"
  cat > "$dir/manifest.env" <<'EOF'
environment=staging
exact_ref=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
verified_at_unix=2000
checkpoint_created_unix=1500
checkpoint_sha256=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
offhost_sha256=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
restore_result=PASS
restore_elapsed_seconds=300
rpo_target_seconds=1000
rto_target_seconds=600
slow_query_result=PASS
load_result=PASS
daily_backup_schedule=PROVEN
monthly_restore_schedule=PROVEN
quarterly_load_schedule=PROVEN
EOF
  for name in checkpoint-verification offhost-proof restore-proof slow-query-evidence load-evidence schedule-evidence; do
    printf '%s evidence\n' "$name" > "$dir/$name.txt"
  done
}

expect_fail() {
  local label="$1"
  local dir="$2"
  if bash "$VERIFIER" --evidence-root "$dir" >/dev/null 2>&1; then
    fail "$label unexpectedly passed"
  fi
}

[[ -f "$VERIFIER" ]] || fail "missing verifier: $VERIFIER"
bash -n "$VERIFIER"

valid="$TMP/valid"
make_fixture "$valid"
bash "$VERIFIER" --evidence-root "$valid" >/dev/null

bad_hash="$TMP/bad-hash"
make_fixture "$bad_hash"
sed -i.bak 's/^offhost_sha256=.*/offhost_sha256=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc/' "$bad_hash/manifest.env"
rm -f "$bad_hash/manifest.env.bak"
expect_fail 'off-host digest mismatch' "$bad_hash"

bad_rpo="$TMP/bad-rpo"
make_fixture "$bad_rpo"
sed -i.bak 's/^rpo_target_seconds=.*/rpo_target_seconds=100/' "$bad_rpo/manifest.env"
rm -f "$bad_rpo/manifest.env.bak"
expect_fail 'RPO miss' "$bad_rpo"

bad_rto="$TMP/bad-rto"
make_fixture "$bad_rto"
sed -i.bak 's/^rto_target_seconds=.*/rto_target_seconds=100/' "$bad_rto/manifest.env"
rm -f "$bad_rto/manifest.env.bak"
expect_fail 'RTO miss' "$bad_rto"

missing_evidence="$TMP/missing-evidence"
make_fixture "$missing_evidence"
rm "$missing_evidence/slow-query-evidence.txt"
expect_fail 'missing slow-query evidence' "$missing_evidence"

bad_schedule="$TMP/bad-schedule"
make_fixture "$bad_schedule"
sed -i.bak 's/^daily_backup_schedule=.*/daily_backup_schedule=UNKNOWN/' "$bad_schedule/manifest.env"
rm -f "$bad_schedule/manifest.env.bak"
expect_fail 'unproven daily backup schedule' "$bad_schedule"

printf 'MVP-14.9 OPS COMPLIANCE SELF-TEST: PASS\n'
