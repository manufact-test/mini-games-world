#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-current-portable-validation-local.sh

bash -n ops/checks/db-primary-current-portable-ci-evidence-verifier-local.sh

FILES=(
  bot/runtime/RuntimePrimaryCurrentPortableCiEvidenceVerifier.php
  ops/ci/verify-current-portable-focused-suite-evidence.php
  bot/tests/RuntimePrimaryCurrentPortableCiEvidenceVerifierTest.php
  bot/tests/RuntimePrimaryCurrentPortableCiEvidenceVerifierContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryCurrentPortableCiEvidenceVerifierTest.php
  bot/tests/RuntimePrimaryCurrentPortableCiEvidenceVerifierContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary current portable CI evidence verifier focused verification passed.\n'
