#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-projection-outbox-local.sh
bash ops/checks/db-primary-projection-worker-local.sh
bash ops/checks/db-primary-staging-api-read-only-smoke-local.sh

SHELL_FILES=(
  ops/ci/run-portable-focused-suite.sh
  ops/checks/db-primary-portable-self-hosted-ci-local.sh
)

for file in "${SHELL_FILES[@]}"; do
  bash -n "$file"
  printf 'shell lint ok: %s\n' "$file"
done

PHP_FILES=(
  bot/tests/RuntimePrimaryPortableFocusedSuiteManifestTest.php
  bot/tests/RuntimePrimaryPortableSelfHostedCiContractTest.php
)

for file in "${PHP_FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

for test_file in "${PHP_FILES[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary portable self-hosted CI focused verification passed.\n'
