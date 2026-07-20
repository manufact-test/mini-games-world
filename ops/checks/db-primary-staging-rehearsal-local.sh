#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-all-module-projector-local.sh

FILES=(
  bot/runtime/RuntimePrimaryRehearsalBackendInterface.php
  bot/runtime/RuntimePrimaryStagingRehearsalBackend.php
  bot/runtime/StagingPrimaryRehearsalOperation.php
  ops/runtime/staging-db-primary-rehearsal.php
  bot/tests/StagingPrimaryRehearsalOperationTest.php
  bot/tests/StagingPrimaryRehearsalCliContractTest.php
  bot/tests/RuntimePrimaryStagingRehearsalBackendContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

"$PHP_BIN" bot/tests/StagingPrimaryRehearsalOperationTest.php
"$PHP_BIN" bot/tests/StagingPrimaryRehearsalCliContractTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryStagingRehearsalBackendContractTest.php

printf 'DB-primary staging rehearsal focused verification passed.\n'
