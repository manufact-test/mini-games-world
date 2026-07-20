#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-activation-local.sh

FILES=(
  bot/runtime/RuntimePrimaryStagingStorageResolverConfig.php
  bot/runtime/RuntimePrimaryStagingStorageResolution.php
  bot/runtime/RuntimePrimaryStagingStorageResolver.php
  ops/runtime/inspect-staging-db-primary-storage-resolution.php
  bot/tests/RuntimePrimaryStagingStorageResolverConfigTest.php
  bot/tests/RuntimePrimaryStagingStorageResolutionTest.php
  bot/tests/RuntimePrimaryStagingStorageResolverContractTest.php
  bot/tests/RuntimePrimaryStagingStorageResolutionCliContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryStagingStorageResolverConfigTest.php
  bot/tests/RuntimePrimaryStagingStorageResolutionTest.php
  bot/tests/RuntimePrimaryStagingStorageResolverContractTest.php
  bot/tests/RuntimePrimaryStagingStorageResolutionCliContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging storage resolver focused verification passed.\n'
