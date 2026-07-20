#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

FILES=(
  bot/runtime/RuntimePrimaryProjectionProjectorInterface.php
  bot/runtime/RuntimePrimaryModuleProjectorInterface.php
  bot/runtime/RuntimePrimaryCallbackModuleProjector.php
  bot/runtime/RuntimePrimaryAccountsModuleProjector.php
  bot/runtime/RuntimePrimaryAllModuleProjector.php
  bot/runtime/RuntimePrimaryRepositoryProjectorFactory.php
  bot/runtime/RuntimePrimaryProjectionBootstrap.php
  bot/tests/RuntimePrimaryAllModuleProjectorTest.php
  bot/tests/RuntimePrimaryAccountsModuleProjectorTest.php
  bot/tests/RuntimePrimaryRepositoryProjectorFactoryTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

"$PHP_BIN" bot/tests/RuntimePrimaryAllModuleProjectorTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryAccountsModuleProjectorTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryRepositoryProjectorFactoryTest.php

printf 'DB-primary all-module projector focused verification passed.\n'
