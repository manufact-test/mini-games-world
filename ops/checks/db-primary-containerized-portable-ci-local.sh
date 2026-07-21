#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-portable-ci-evidence-verifier-local.sh

SHELL_FILES=(
  ops/ci/run-portable-focused-suite-container.sh
  ops/checks/db-primary-containerized-portable-ci-local.sh
)

for file in "${SHELL_FILES[@]}"; do
  bash -n "$file"
  printf 'shell lint ok: %s\n' "$file"
done

PHP_FILES=(
  bot/tests/RuntimePrimaryPortableCiContainerContractTest.php
)

for file in "${PHP_FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

for test_file in "${PHP_FILES[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary containerized portable CI focused verification passed.\n'
