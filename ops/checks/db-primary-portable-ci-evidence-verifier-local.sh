#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-portable-self-hosted-ci-local.sh

FILES=(
  bot/runtime/RuntimePrimaryPortableCiEvidenceVerifier.php
  ops/ci/verify-portable-focused-suite-evidence.php
  bot/tests/RuntimePrimaryPortableCiEvidenceVerifierTest.php
  bot/tests/RuntimePrimaryPortableCiEvidenceVerifierContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryPortableCiEvidenceVerifierTest.php
  bot/tests/RuntimePrimaryPortableCiEvidenceVerifierContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary portable CI evidence verifier focused verification passed.\n'
