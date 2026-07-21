#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-projection-outbox-local.sh
bash ops/checks/db-primary-projection-worker-local.sh
bash ops/checks/db-primary-staging-api-read-only-smoke-evidence-verifier-local.sh

FILES=(
  ops/checks/db-primary-current-portable-validation-local.sh
  ops/ci/run-current-portable-focused-suite.sh
)

for file in "${FILES[@]}"; do
  bash -n "$file"
  printf 'shell lint ok: %s\n' "$file"
done

PHP_FILES=(
  bot/tests/RuntimePrimaryCurrentPortableSuiteManifestTest.php
  bot/tests/RuntimePrimaryCurrentPortableValidationContractTest.php
  bot/tests/RuntimePrimaryHostedWorkflowExactCommitContractTest.php
)

for file in "${PHP_FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

for test_file in "${PHP_FILES[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary current portable validation focused verification passed.\n'
