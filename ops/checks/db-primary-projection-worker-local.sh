#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
cd "$PROJECT_ROOT"

FILES=(
  bot/runtime/RuntimePrimaryProjectionProjectorInterface.php
  bot/runtime/RuntimePrimaryProjectionWorker.php
  bot/tests/RuntimePrimaryProjectionOutboxTest.php
  bot/tests/RuntimePrimaryProjectionWorkerTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

"$PHP_BIN" bot/tests/RuntimePrimaryProjectionOutboxTest.php
"$PHP_BIN" bot/tests/RuntimePrimaryProjectionWorkerTest.php

printf 'DB-primary projection worker focused verification passed.\n'
