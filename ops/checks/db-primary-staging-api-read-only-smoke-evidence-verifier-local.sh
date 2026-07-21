#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-api-read-only-smoke-local.sh

FILES=(
  bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifier.php
  ops/runtime/verify-staging-db-primary-api-read-only-smoke-evidence.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifierTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifierContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifierTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeEvidenceVerifierContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging API read-only smoke evidence verifier focused verification passed.\n'
